<?php

namespace Extensions\Dateien\Services;

use App\Models\User;
use Extensions\Dateien\Models\AuditEvent;
use Extensions\Dateien\Models\FileGroup;
use Extensions\Locations\Services\LocationAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GroupService
{
    public static function canManage(User $user): bool
    {
        try {
            $user = app(FileAccess::class)->member($user);
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            return false;
        }

        return $user->is_admin || $user->hasModuleRole('dateien', 'gruppenverwalter');
    }

    public function groups(User $user): Collection
    {
        $user = $this->actor($user);

        return FileGroup::query()->orderBy('name')->get()->filter(fn (FileGroup $group) => $this->visible($user, $group))->values();
    }

    public function save(User $user, ?FileGroup $group, string $name, ?int $locationId): FileGroup
    {
        $user = $this->actor($user);
        if ($group) {
            abort_unless($this->visible($user, $group), 403);
            $locationId = $group->ext_location_id;
        } elseif (config('locations.enabled') && ! $user->is_admin) {
            $locationId = (new LocationAccess)->currentLocationId($user);
        }
        if ($locationId !== null) {
            abort_unless(config('locations.enabled') && ($user->is_admin || (new LocationAccess)->canSee($user, $locationId)), 403);
        }
        $data = Validator::make(['name' => trim($name), 'ext_location_id' => $locationId], [
            'name' => ['required', 'string', 'max:150', 'not_regex:/[\x00-\x1f\x7f]/u', Rule::unique('x_files_groups', 'name')->ignore($group?->id)],
            'ext_location_id' => ['nullable', 'integer', 'exists:x_locations,id'],
        ])->validate();

        try {
            return DB::transaction(function () use ($user, $group, $data) {
                if ($group) {
                    $group = FileGroup::query()->lockForUpdate()->findOrFail($group->id);
                    $user = $this->actor($user);
                    abort_unless($this->visible($user, $group), 403);
                    $group->update($data);
                } else {
                    $user = $this->actor($user);
                    $group = FileGroup::create($data);
                }
                $this->audit($user, $group, 'group.saved');

                return $group;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages(['name' => 'Eine Dateigruppe mit diesem Namen existiert bereits.']);
            }
            throw $e;
        }
    }

    public function setMember(User $user, FileGroup $group, int $memberId, ?bool $manager): void
    {
        $user = $this->actor($user);
        DB::transaction(function () use ($user, $group, $memberId, $manager) {
            $group = FileGroup::query()->lockForUpdate()->findOrFail($group->id);
            $user = $this->actor($user);
            abort_unless($this->visible($user, $group), 403);
            if ($manager !== null) {
                $member = User::withoutGlobalScopes()->findOrFail($memberId);
                app(FileAccess::class)->member($member);
                if (config('locations.enabled') && ! $user->is_admin) {
                    $access = new LocationAccess;
                    abort_unless(array_intersect($access->visibleLocationIds($user), $access->visibleLocationIds($member)) !== [], 403);
                }
                if (config('locations.enabled') && $group->ext_location_id !== null && ! $member->is_admin) {
                    abort_unless((new LocationAccess)->canSee($member, (int) $group->ext_location_id), 403);
                }
            }
            if ($manager === null) {
                $group->members()->detach($memberId);
            } else {
                $group->members()->syncWithoutDetaching([$memberId => ['is_manager' => $manager]]);
            }
            $this->audit($user, $group, $manager === null ? 'group.member_removed' : 'group.member_saved', ['user_id' => $memberId]);
        });
    }

    private function visible(User $user, FileGroup $group): bool
    {
        return ! config('locations.enabled') || $user->is_admin || $group->ext_location_id === null
            || (new LocationAccess)->canSee($user, (int) $group->ext_location_id);
    }

    private function actor(User $user): User
    {
        $user = app(FileAccess::class)->member($user);
        abort_unless(self::canManage($user), 403);

        return $user;
    }

    private function audit(User $user, FileGroup $group, string $action, array $metadata = []): void
    {
        AuditEvent::create(['actor_id' => $user->id, 'action' => $action, 'metadata' => ['group_id' => $group->id] + $metadata]);
    }
}

<?php

namespace Extensions\Dateien\Services;

use App\Models\User;
use Extensions\Dateien\Models\Node;
use Extensions\Dateien\Models\Permission;
use Extensions\Dateien\Models\Space;
use Extensions\Locations\Services\LocationAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class FileAccess
{
    public const LEVELS = ['read' => 1, 'edit' => 2, 'manage' => 3];

    public function member(User $user): User
    {
        $fresh = User::withoutGlobalScopes()->find($user->getKey());
        $module = DB::table('modules')->where('key', 'dateien')->where('is_active', true)->first();
        if (! $fresh || ! $fresh->is_active || ! $module
            || (! $fresh->is_admin && ! $module->auto_assign && ! $fresh->moduleAccess()->where('module', 'dateien')->exists())) {
            throw new AuthorizationException('Kein Zugriff auf Dateien.');
        }

        return $fresh;
    }

    public function level(User $user, Space|Node $target): ?string
    {
        return $this->resolve($user, $target, false);
    }

    public function can(User $user, Space|Node $target, string $level): bool
    {
        if (! isset(self::LEVELS[$level])) {
            return false;
        }
        $actual = $this->level($user, $target);

        return $actual !== null && self::LEVELS[$actual] >= self::LEVELS[$level];
    }

    public function authorize(User $user, Space|Node $target, string $level): void
    {
        if (! $this->can($user, $target, $level)) {
            throw new AuthorizationException('Keine Berechtigung für diese Datei.');
        }
    }

    public function authorizeTrashed(User $user, Node $target, string $level = 'manage'): void
    {
        $actual = $this->resolve($user, $target, true);
        if (! isset(self::LEVELS[$level]) || $actual === null || self::LEVELS[$actual] < self::LEVELS[$level]) {
            throw new AuthorizationException('Keine Berechtigung für diesen Papierkorb-Eintrag.');
        }
    }

    private function resolve(User $user, Space|Node $target, bool $allowTrashed): ?string
    {
        try {
            $user = $this->member($user);
        } catch (AuthorizationException) {
            return null;
        }

        $chain = [];
        if ($target instanceof Node) {
            $node = Node::withTrashed()->find($target->getKey());
            if (! $node) {
                return null;
            }
            $space = Space::find($node->space_id);
            $seen = [];
            while ($node) {
                if (isset($seen[$node->id]) || ! $space || $node->space_id !== $space->id
                    || ($node->trashed() && (! $allowTrashed || $chain !== []))) {
                    return null;
                }
                $seen[$node->id] = true;
                $chain[] = $node;
                if ($node->parent_id === null) {
                    break;
                }
                $node = Node::withTrashed()->find($node->parent_id);
                if (! $node) {
                    return null;
                }
            }
        } else {
            $space = Space::find($target->getKey());
        }

        if (! $space) {
            return null;
        }
        if ($user->is_admin) {
            return 'manage';
        }
        if (config('locations.enabled') && $space->ext_location_id !== null && ! (new LocationAccess)->canSee($user, (int) $space->ext_location_id)) {
            return null;
        }

        $groups = DB::table('x_files_group_members')->join('x_files_groups', 'x_files_groups.id', '=', 'x_files_group_members.group_id')
            ->where('user_id', $user->id);
        if (config('locations.enabled')) {
            $locations = (new LocationAccess)->visibleLocationIds($user);
            $groups->where(fn ($q) => $q->whereNull('x_files_groups.ext_location_id')->orWhereIn('x_files_groups.ext_location_id', $locations));
        }
        $groups = $groups->pluck('group_id');
        $best = 0;
        $inherit = true;
        foreach ($chain as $node) {
            $best = max($best, $this->directLevel($user, $groups->all(), 'node', $node->id));
            if (! $node->inherit_permissions) {
                $inherit = false;
                break;
            }
        }
        if ($inherit) {
            $best = max($best, $this->directLevel($user, $groups->all(), 'space', $space->id));
        }

        return array_search($best, self::LEVELS, true) ?: null;
    }

    private function directLevel(User $user, array $groups, string $type, int $id): int
    {
        return Permission::where('target_type', $type)->where('target_id', $id)
            ->where(function ($query) use ($user, $groups) {
                $query->where(fn ($q) => $q->where('subject_type', 'user')->where('subject_id', $user->id))
                    ->orWhere(fn ($q) => $q->where('subject_type', 'group')->whereIn('subject_id', $groups));
            })->pluck('level')->map(fn ($level) => self::LEVELS[$level] ?? 0)->max() ?? 0;
    }
}

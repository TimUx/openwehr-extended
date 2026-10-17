<?php

namespace Extensions\Dateien\Services;

use App\Models\User;
use App\Models\Setting;
use App\Support\FileResponse;
use Extensions\Dateien\Models\AuditEvent;
use Extensions\Dateien\Models\FileGroup;
use Extensions\Dateien\Models\Node;
use Extensions\Dateien\Models\Permission;
use Extensions\Dateien\Models\Space;
use Extensions\Dateien\Models\Version;
use Extensions\Locations\Services\LocationAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Throwable;

class FileService
{
    public function __construct(private FileAccess $access) {}

    public static function maxUploadKb(): int
    {
        return max(1, min(51200, (int) Setting::get('dateien.max_upload_kb', config('dateien.max_upload_kb', 20480))));
    }

    public static function allowedExtensions(): array
    {
        $extensions = Setting::get('dateien.extensions', config('dateien.extensions', FileResponse::allowedExtensions()));
        if (is_string($extensions)) {
            $extensions = json_decode($extensions, true);
        }

        return array_values(array_intersect(FileResponse::allowedExtensions(), is_array($extensions) ? $extensions : []));
    }

    public static function retentionDays(): int
    {
        return max(0, min(3650, (int) Setting::get('dateien.retention_days', config('dateien.retention_days', 30))));
    }

    public function createSpace(User $user, array $data): Space
    {
        $user = $this->access->member($user);
        if (! $user->is_admin) {
            throw new AuthorizationException;
        }
        $name = $this->name((string) ($data['name'] ?? ''));
        $quota = $data['quota_bytes'] ?? null;
        if ($quota !== null && (filter_var($quota, FILTER_VALIDATE_INT) === false || $quota < 0)) {
            $this->invalid('quota_bytes', 'Das Speicherlimit muss eine nichtnegative ganze Zahl sein.');
        }
        $location = $data['ext_location_id'] ?? null;
        if ($location !== null && (! config('locations.enabled') || filter_var($location, FILTER_VALIDATE_INT) === false
            || ! DB::table('x_locations')->where('id', $location)->exists())) {
            $this->invalid('ext_location_id', 'Bitte einen gültigen Standort auswählen.');
        }

        return DB::transaction(function () use ($user, $data, $name, $quota) {
            $this->access->member($user);
            $space = Space::create([
                'name' => $name,
                'ext_location_id' => $data['ext_location_id'] ?? null,
                'quota_bytes' => $quota,
            ]);
            $this->access->authorize($user, $space, 'manage');
            $this->audit($user, 'space.created', $space);

            return $space;
        });
    }

    public function createFolder(User $user, Space $space, ?Node $parent, string $name): Node
    {
        return $this->mutate($user, $space, function (Space $space) use ($user, $parent, $name) {
            $parent = $this->destination($user, $space, $parent, 'edit');
            $node = Node::create(['space_id' => $space->id, 'parent_id' => $parent?->id, 'name' => $this->name($name), 'kind' => 'folder']);
            $this->audit($user, 'folder.created', $space, $node);

            return $node;
        });
    }

    public function upload(User $user, Space $space, ?Node $parent, UploadedFile $file): Node
    {
        $keys = [];
        try {
            return $this->mutate($user, $space, function (Space $space) use ($user, $parent, $file, &$keys) {
                $parent = $this->destination($user, $space, $parent, 'edit');
                $name = $this->name($file->getClientOriginalName());
                $node = Node::create(['space_id' => $space->id, 'parent_id' => $parent?->id, 'name' => $name, 'kind' => 'file']);
                $this->storeVersion($user, $space, $node, $file, null, $keys);
                $this->audit($user, 'file.created', $space, $node);

                return $node;
            });
        } catch (Throwable $e) {
            $this->cleanup($keys);
            throw $e;
        }
    }

    public function addVersion(User $user, Node $node, UploadedFile $file, ?int $expectedVersion = null): Version
    {
        $keys = [];
        try {
            return $this->mutateNode($user, $node, 'edit', function (Space $space, Node $node) use ($user, $file, $expectedVersion, &$keys) {
                return $this->storeVersion($user, $space, $node, $file, $expectedVersion, $keys);
            });
        } catch (Throwable $e) {
            $this->cleanup($keys);
            throw $e;
        }
    }

    public function restoreVersion(User $user, Node $node, Version $version, ?int $expectedVersion = null): Version
    {
        $keys = [];
        try {
            return $this->mutateNode($user, $node, 'edit', function (Space $space, Node $node) use ($user, $version, $expectedVersion, &$keys) {
                $this->assertFile($node);
                $version = Version::where('node_id', $node->id)->find($version->id);
                if (! $version) {
                    $this->invalid('version', 'Diese Version gehört nicht zu dieser Datei.');
                }
                $latest = $this->latest($node, $expectedVersion);
                if ($latest && hash_equals($latest->checksum, $version->checksum)) {
                    return $latest;
                }
                $this->quota($space, $version->size);
                $key = $this->key($space);
                $keys[] = $key;
                $stream = $this->disk()->readStream($this->storageKey($version->storage_key));
                if (! is_resource($stream)) {
                    $this->invalid('file', 'Die gespeicherte Datei ist nicht verfügbar.');
                }
                try {
                    $this->write($key, $stream);
                } finally {
                    fclose($stream);
                }
                if (! hash_equals($version->checksum, hash_file('sha256', $this->disk()->path($key)))) {
                    $this->invalid('file', 'Die Prüfsumme der gespeicherten Datei stimmt nicht.');
                }
                $new = Version::create([
                    'node_id' => $node->id, 'number' => ($latest?->number ?? 0) + 1, 'storage_key' => $key,
                    'checksum' => $version->checksum, 'size' => $version->size, 'mime' => $version->mime,
                    'extension' => $version->extension, 'created_by' => $user->id, 'source' => 'restore',
                ]);
                $this->audit($user, 'version.restored', $space, $node, ['version_id' => $new->id, 'source_version_id' => $version->id]);

                return $new;
            });
        } catch (Throwable $e) {
            $this->cleanup($keys);
            throw $e;
        }
    }

    public function rename(User $user, Node $node, string $name): void
    {
        $this->mutateNode($user, $node, 'edit', function (Space $space, Node $node) use ($user, $name) {
            $name = $this->name($name);
            if ($node->kind === 'file' && strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== strtolower(pathinfo($node->name, PATHINFO_EXTENSION))) {
                $this->invalid('name', 'Die Dateiendung darf nicht geändert werden.');
            }
            $node->update(['name' => $name]);
            $this->audit($user, 'node.renamed', $space, $node);
        });
    }

    public function move(User $user, Node $node, ?Node $parent): void
    {
        $this->mutateNode($user, $node, 'manage', function (Space $space, Node $node) use ($user, $parent) {
            $parent = $this->destination($user, $space, $parent, 'manage');
            $ancestor = $parent;
            while ($ancestor) {
                if ($ancestor->id === $node->id) {
                    $this->invalid('parent_id', 'Ein Ordner kann nicht in sich selbst verschoben werden.');
                }
                $ancestor = $ancestor->parent_id ? Node::withTrashed()->findOrFail($ancestor->parent_id) : null;
            }
            $old = $node->parent_id;
            $node->update(['parent_id' => $parent?->id]);
            $this->audit($user, 'node.moved', $space, $node, ['from_parent_id' => $old, 'to_parent_id' => $parent?->id]);
        });
    }

    public function trash(User $user, Node $node): void
    {
        $this->mutateNode($user, $node, 'manage', function (Space $space, Node $node) use ($user) {
            $node->delete();
            $this->audit($user, 'node.trashed', $space, $node);
        });
    }

    public function restore(User $user, Node $node): void
    {
        $this->mutateNode($user, $node, 'manage', function (Space $space, Node $node) use ($user) {
            if (! $node->trashed()) {
                $this->invalid('node', 'Dieser Eintrag liegt nicht im Papierkorb.');
            }
            $node->restore();
            $this->audit($user, 'node.restored', $space, $node);
        }, true);
    }

    public function purge(User $user, Node $node): void
    {
        $this->mutateNode($user, $node, 'manage', function (Space $space, Node $node) use ($user) {
            if (! $node->trashed()) {
                $this->invalid('node', 'Nur Papierkorb-Einträge können endgültig gelöscht werden.');
            }
            if ($node->deleted_at->copy()->addDays(self::retentionDays())->isFuture()) {
                $this->invalid('node', 'Die Mindestaufbewahrungsfrist im Papierkorb ist noch nicht abgelaufen.');
            }
            $ids = $this->descendantIds($node);
            $keys = Version::whereIn('node_id', $ids)->pluck('storage_key');
            foreach ($keys as $key) {
                DB::table('x_files_pending_deletions')->insertOrIgnore(['storage_key' => $this->storageKey($key), 'created_at' => now()]);
            }
            $this->audit($user, 'node.purged', $space, $node, ['node_count' => count($ids), 'version_count' => $keys->count()]);
            $node->forceDelete();
            // A durable deletion outbox survives crashes and storage failures after the DB commit.
            DB::afterCommit(function () {
                try {
                    $this->drainDeletions();
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }, true);
    }

    public function setPermission(User $user, Space|Node $target, string $subjectType, int $subjectId, ?string $level): void
    {
        $space = $target instanceof Space ? $target : $this->spaceOf($target);
        $this->mutate($user, $space, function (Space $space) use ($user, $target, $subjectType, $subjectId, $level) {
            $target = $target instanceof Node ? $this->freshNode($target, $space) : $space;
            $this->access->authorize($user, $target, 'manage');
            if ($subjectId < 1 || ! in_array($subjectType, ['user', 'group'], true) || ($level !== null && ! isset(FileAccess::LEVELS[$level]))) {
                $this->invalid('permission', 'Ungültige Berechtigung.');
            }
            if ($subjectType === 'user' && $level !== null) {
                $subject = User::withoutGlobalScopes()->find($subjectId);
                if (! $subject) {
                    throw new AuthorizationException('Keine Freigabe für diesen Benutzer möglich.');
                }
                $subject = $this->access->member($subject);
                if (config('locations.enabled') && ! $subject->is_admin && $space->ext_location_id !== null
                    && ! (new LocationAccess)->canSee($subject, (int) $space->ext_location_id)) {
                    throw new AuthorizationException('Keine Freigabe für diesen Benutzer möglich.');
                }
            } elseif ($subjectType === 'group' && $level !== null) {
                $actor = $this->access->member($user);
                $query = FileGroup::whereKey($subjectId);
                if (config('locations.enabled') && ! $actor->is_admin) {
                    $locations = (new LocationAccess)->visibleLocationIds($actor);
                    $query->where(fn ($q) => $q->whereNull('ext_location_id')->orWhereIn('ext_location_id', $locations));
                }
                $group = $query->first();
                if (! $group || (config('locations.enabled') && $space->ext_location_id !== null
                    && $group->ext_location_id !== null && $group->ext_location_id !== $space->ext_location_id)) {
                    throw new AuthorizationException('Kein Zugriff auf diese Dateigruppe.');
                }
            }
            $key = ['target_type' => $target instanceof Space ? 'space' : 'node', 'target_id' => $target->id, 'subject_type' => $subjectType, 'subject_id' => $subjectId];
            if ($level === null) {
                Permission::where($key)->delete();
            } else {
                Permission::updateOrCreate($key, ['level' => $level]);
            }
            $this->audit($user, 'permission.changed', $space, $target instanceof Node ? $target : null, ['subject_type' => $subjectType, 'subject_id' => $subjectId, 'level' => $level]);
        });
    }

    public function setInheritance(User $user, Node $node, bool $inherit): void
    {
        $this->mutateNode($user, $node, 'manage', function (Space $space, Node $node) use ($user, $inherit) {
            $node->update(['inherit_permissions' => $inherit]);
            $this->audit($user, 'inheritance.changed', $space, $node, ['inherit_permissions' => $inherit]);
        });
    }

    public function children(User $user, Space $space, ?Node $parent): Collection
    {
        $this->access->member($user);
        $space = Space::findOrFail($space->id);
        if ($parent) {
            $parent = $this->freshNode($parent, $space);
            if ($parent->kind !== 'folder') {
                $this->invalid('parent_id', 'Das Ziel muss ein Ordner sein.');
            }
        }
        $this->access->authorize($user, $parent ?? $space, 'read');

        return Node::where('space_id', $space->id)->where('parent_id', $parent?->id)->orderBy('kind')->orderBy('name')->get()
            ->filter(fn (Node $node) => $this->access->can($user, $node, 'read'))->values();
    }

    public function spaces(User $user): Collection
    {
        $this->access->member($user);

        return Space::orderBy('name')->get()->filter(fn (Space $space) => $this->access->can($user, $space, 'read'))->values();
    }

    public function shared(User $user): Collection
    {
        $user = $this->access->member($user);
        $groups = DB::table('x_files_group_members')->where('user_id', $user->id)->pluck('group_id');
        $ids = Permission::where('target_type', 'node')->where(function ($q) use ($user, $groups) {
            $q->where(fn ($q) => $q->where('subject_type', 'user')->where('subject_id', $user->id))
                ->orWhere(fn ($q) => $q->where('subject_type', 'group')->whereIn('subject_id', $groups));
        })->pluck('target_id');

        return Node::whereIn('id', $ids)->orderBy('name')->get()->filter(fn (Node $node) => $this->access->can($user, $node, 'read'))->values();
    }

    public function trashItems(User $user, Space $space): Collection
    {
        $this->access->member($user);

        return Node::onlyTrashed()->where('space_id', $space->id)->orderByDesc('deleted_at')->get()->filter(function (Node $node) use ($user) {
            try {
                $this->access->authorizeTrashed($user, $node);

                return true;
            } catch (AuthorizationException) {
                return false;
            }
        })->values();
    }

    public function versions(User $user, Node $node): Collection
    {
        $this->access->authorize($user, $node, 'read');

        return Version::where('node_id', $node->id)->orderByDesc('number')->get();
    }

    public function download(User $user, Node $node, ?Version $version = null): Response
    {
        return $this->mutateNode($user, $node, 'read', function (Space $space, Node $node) use ($user, $version) {
            $this->assertFile($node);
            $version = $version ? Version::where('node_id', $node->id)->findOrFail($version->id) : Version::where('node_id', $node->id)->orderByDesc('number')->firstOrFail();
            try {
                $bytes = $this->disk()->get($this->storageKey($version->storage_key));
            } catch (FilesystemException $e) {
                report($e);
                abort(404, 'Die gespeicherte Datei ist nicht verfügbar.');
            }
            if (! is_string($bytes)) {
                abort(404, 'Die gespeicherte Datei ist nicht verfügbar.');
            }
            if (! hash_equals($version->checksum, hash('sha256', $bytes))) {
                $this->invalid('file', 'Die Prüfsumme der gespeicherten Datei stimmt nicht.');
            }
            $this->audit($user, 'version.downloaded', $space, $node, ['version_id' => $version->id]);
            $name = pathinfo($node->name, PATHINFO_FILENAME).'.'.$version->extension;
            $fallback = str_replace(['%', '/', '\\'], '_', preg_replace('/[^\x20-\x7e]/', '_', Str::ascii($name))) ?: 'datei.'.$version->extension;
            $response = FileResponse::make($bytes, $fallback, 'private, no-store');
            $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
                isset(FileResponse::INLINE[$version->extension]) ? 'inline' : 'attachment',
                $name,
                $fallback,
            ));

            return $response;
        });
    }

    public function purgeExpired(User $user): int
    {
        $user = $this->access->member($user);
        if (! $user->is_admin) {
            throw new AuthorizationException;
        }
        $days = self::retentionDays();
        $count = 0;
        foreach (Node::onlyTrashed()->where('deleted_at', '<=', now()->subDays($days))->get() as $node) {
            try {
                $this->access->authorizeTrashed($user, $node);
                $this->purge($user, $node);
                $count++;
            } catch (AuthorizationException) {
                // An already trashed ancestor owns the subtree and will purge it as one unit.
            }
        }
        $this->retryPendingDeletions($user);

        return $count;
    }

    public function retryPendingDeletions(User $user): void
    {
        if (! $this->access->member($user)->is_admin) {
            throw new AuthorizationException;
        }
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => $this->drainDeletions());
        } else {
            $this->drainDeletions();
        }
    }

    private function mutate(User $user, Space $space, callable $callback): mixed
    {
        try {
            return DB::transaction(function () use ($user, $space, $callback) {
                $space = Space::whereKey($space->id)->lockForUpdate()->firstOrFail();
                $this->access->member($user);

                return $callback($space);
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                $this->invalid('name', 'Ein Eintrag mit diesem Namen existiert bereits.');
            }
            throw $e;
        }
    }

    private function mutateNode(User $user, Node $node, string $level, callable $callback, bool $trashed = false): mixed
    {
        return $this->mutate($user, $this->spaceOf($node), function (Space $space) use ($user, $node, $level, $callback, $trashed) {
            $node = $this->freshNode($node, $space);
            if ($trashed) {
                $this->access->authorizeTrashed($user, $node, $level);
            } else {
                $this->access->authorize($user, $node, $level);
            }

            return $callback($space, $node);
        });
    }

    private function spaceOf(Node $node): Space
    {
        return Space::findOrFail(Node::withTrashed()->findOrFail($node->id)->space_id);
    }

    private function freshNode(Node $node, Space $space): Node
    {
        $fresh = Node::withTrashed()->where('space_id', $space->id)->whereKey($node->id)->lockForUpdate()->first();
        if (! $fresh) {
            $this->invalid('parent_id', 'Der Eintrag muss im selben Bereich liegen.');
        }

        return $fresh;
    }

    private function destination(User $user, Space $space, ?Node $parent, string $level): ?Node
    {
        if ($parent) {
            $parent = $this->freshNode($parent, $space);
            if ($parent->kind !== 'folder') {
                $this->invalid('parent_id', 'Das Ziel muss ein Ordner im selben Bereich sein.');
            }
        }
        $this->access->authorize($user, $parent ?? $space, $level);

        return $parent;
    }

    private function storeVersion(User $user, Space $space, Node $node, UploadedFile $file, ?int $expected, array &$keys): Version
    {
        $this->assertFile($node);
        $latest = $this->latest($node, $expected);
        [$extension, $mime, $size, $checksum] = $this->validateUpload($file);
        if ($extension !== strtolower(pathinfo($node->name, PATHINFO_EXTENSION))) {
            $this->invalid('file', 'Eine neue Version muss dieselbe Dateiendung haben.');
        }
        if ($latest && hash_equals($latest->checksum, $checksum)) {
            return $latest;
        }
        $this->quota($space, $size);
        $key = $this->key($space);
        $keys[] = $key;
        $stream = fopen($file->getRealPath(), 'rb');
        if (! is_resource($stream)) {
            $this->invalid('file', 'Die Datei konnte nicht gelesen werden.');
        }
        try {
            $this->write($key, $stream);
        } finally {
            fclose($stream);
        }
        if (! hash_equals($checksum, hash_file('sha256', $this->disk()->path($key)))) {
            $this->invalid('file', 'Die Prüfsumme der hochgeladenen Datei stimmt nicht.');
        }
        $version = Version::create([
            'node_id' => $node->id, 'number' => ($latest?->number ?? 0) + 1, 'storage_key' => $key,
            'checksum' => $checksum, 'size' => $size, 'mime' => $mime, 'extension' => $extension,
            'created_by' => $user->id, 'source' => 'upload',
        ]);
        $this->audit($user, 'version.created', $space, $node, ['version_id' => $version->id, 'number' => $version->number, 'size' => $size]);

        return $version;
    }

    private function latest(Node $node, ?int $expected): ?Version
    {
        $latest = Version::where('node_id', $node->id)->orderByDesc('number')->first();
        if ($expected !== null && $expected !== ($latest?->number ?? 0)) {
            $this->invalid('expectedVersion', 'Die Datei wurde inzwischen geändert. Bitte neu laden.');
        }

        return $latest;
    }

    private function validateUpload(UploadedFile $file): array
    {
        $max = self::maxUploadKb() * 1024;
        if (! $file->isValid() || $file->getSize() === false || $file->getSize() > $max) {
            $this->invalid('file', 'Die Datei ist ungültig oder überschreitet das Upload-Limit.');
        }
        $extension = strtolower(pathinfo($this->name($file->getClientOriginalName()), PATHINFO_EXTENSION));
        $allowed = self::allowedExtensions();
        if (! in_array($extension, $allowed, true)) {
            $this->invalid('file', 'Dieser Dateityp ist nicht erlaubt.');
        }
        $mime = (string) $file->getMimeType();
        $expected = FileResponse::INLINE + FileResponse::DOWNLOAD;
        $matches = $mime === $expected[$extension];
        if (in_array($extension, ['txt', 'csv'], true)) {
            $matches = in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'application/x-empty', 'inode/x-empty'], true);
        }
        if (in_array($extension, ['doc', 'xls', 'ppt'], true)) {
            $matches = in_array($mime, [$expected[$extension], 'application/x-ole-storage', 'application/CDFV2'], true);
        }
        if (in_array($extension, ['docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp'], true)) {
            $matches = in_array($mime, [$expected[$extension], 'application/zip'], true)
                && $this->validOfficeArchive($file, $extension, $expected[$extension]);
        }
        if (! $matches) {
            $this->invalid('file', 'Dateiinhalt und Dateiendung stimmen nicht überein.');
        }

        return [$extension, $mime, (int) $file->getSize(), hash_file('sha256', $file->getRealPath())];
    }

    private function validOfficeArchive(UploadedFile $file, string $extension, string $mime): bool
    {
        if (! class_exists(\ZipArchive::class)) {
            return false;
        }
        $zip = new \ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            return false;
        }
        try {
            if (in_array($extension, ['odt', 'ods', 'odp'], true)) {
                $stat = $zip->statName('mimetype');

                return $stat && $stat['size'] < 256 && $zip->getFromName('mimetype', 256) === $mime;
            }
            $part = ['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml', 'pptx' => 'ppt/presentation.xml'][$extension];

            return $zip->locateName('[Content_Types].xml') !== false && $zip->locateName($part) !== false;
        } finally {
            $zip->close();
        }
    }

    private function quota(Space $space, int $bytes): void
    {
        $used = (int) Version::whereIn('node_id', Node::withTrashed()->where('space_id', $space->id)->select('id'))->sum('size');
        if ($space->quota_bytes !== null && $used + $bytes > $space->quota_bytes) {
            $this->invalid('file', 'Das Speicherlimit dieses Bereichs ist erreicht.');
        }
    }

    private function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || in_array($name, ['.', '..'], true) || mb_strlen($name) > 255
            || preg_match('/[\/\\\\\x00-\x1f\x7f]/u', $name) !== 0 || ! mb_check_encoding($name, 'UTF-8')) {
            $this->invalid('name', 'Bitte einen gültigen Namen ohne Pfad- oder Steuerzeichen angeben.');
        }

        return $name;
    }

    private function assertFile(Node $node): void
    {
        if ($node->kind !== 'file') {
            $this->invalid('node', 'Dieser Eintrag ist keine Datei.');
        }
    }

    private function descendantIds(Node $node): array
    {
        $ids = [$node->id];
        $front = $ids;
        while ($front !== []) {
            $front = Node::withTrashed()->where('space_id', $node->space_id)->whereIn('parent_id', $front)->pluck('id')->all();
            $ids = array_merge($ids, $front);
        }

        return $ids;
    }

    private function audit(User $user, string $action, Space $space, ?Node $node = null, array $metadata = []): void
    {
        AuditEvent::create(['actor_id' => $user->id, 'action' => $action, 'space_id' => $space->id, 'node_id' => $node?->id, 'metadata' => $metadata]);
    }

    private function disk(): FilesystemAdapter
    {
        // Never accept a public or remote disk configuration for this private local store.
        $config = config('filesystems.disks.dateien');
        if (is_array($config) && ($config['driver'] ?? null) === 'local'
            && ($config['visibility'] ?? 'private') === 'private'
            && ($config['root'] ?? null) === storage_path('app/private/dateien')) {
            return Storage::disk('dateien');
        }

        return Storage::build(['driver' => 'local', 'root' => storage_path('app/private/dateien'), 'visibility' => 'private', 'throw' => true]);
    }

    private function key(Space $space): string
    {
        return Str::uuid().'/'.Str::uuid();
    }

    private function storageKey(string $key): string
    {
        if (! preg_match('/\A[0-9a-f-]{36}\/[0-9a-f-]{36}\z/D', $key)) {
            $this->invalid('file', 'Ungültiger Speicherschlüssel.');
        }

        return $key;
    }

    private function write(string $key, mixed $stream): void
    {
        /** @var DatabaseTransactionsManager $transactions */
        $transactions = app('db.transactions');
        // A committed savepoint can still be rolled back by any enclosing transaction.
        foreach ($transactions->getPendingTransactions() as $transaction) {
            if ($transaction->connection === DB::connection()->getName()) {
                $transaction->addCallbackForRollback(fn () => $this->cleanup([$key]));
            }
        }
        $disk = $this->disk();
        $stage = '.staging/'.Str::uuid();
        try {
            if (! $disk->put($stage, $stream, ['visibility' => 'private']) || ! $disk->move($stage, $key)) {
                $this->invalid('file', 'Die Datei konnte nicht gespeichert werden.');
            }
        } finally {
            $disk->delete($stage);
        }
    }

    private function cleanup(array $keys): void
    {
        foreach ($keys as $key) {
            try {
                $this->disk()->delete($this->storageKey($key));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    private function drainDeletions(): void
    {
        foreach (DB::table('x_files_pending_deletions')->orderBy('id')->limit(500)->get() as $entry) {
            try {
                $key = $this->storageKey($entry->storage_key);
                if (Version::where('storage_key', $key)->exists()) {
                    continue;
                }
                if ($this->disk()->delete($key)) {
                    DB::table('x_files_pending_deletions')->where('id', $entry->id)->delete();
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

<?php

use App\Models\Module;
use Extensions\Dateien\Models\FileGroup;
use Extensions\Dateien\Models\Node;
use Extensions\Dateien\Models\Permission;
use Extensions\Dateien\Models\Version;
use Extensions\Dateien\Services\FileAccess;
use Extensions\Dateien\Services\FileService;
use Extensions\Locations\Models\Location;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Module::where('key', 'dateien')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Storage::fake('dateien');
    $this->admin = filesMember('Ada Atemschutz', admin: true);
    $this->member = filesMember();
    $this->other = filesMember('Bernd Blaulicht');
    $this->files = app(FileService::class);
    $this->access = app(FileAccess::class);
    $this->space = $this->files->createSpace($this->admin, [
        'name' => 'Dienstunterlagen',
        'ext_location_id' => Location::default()->id,
        'quota_bytes' => 1024 * 1024,
    ]);
});

it('enforces the complete user permission hierarchy', function (string $level, bool $edit, bool $manage) {
    $this->files->setPermission($this->admin, $this->space, 'user', $this->member->id, $level);
    $folder = $this->files->createFolder($this->admin, $this->space, null, 'Ausbildung');

    foreach ([$this->space, $folder] as $target) {
        expect($this->access->can($this->member, $target, 'read'))->toBeTrue()
            ->and($this->access->can($this->member, $target, 'edit'))->toBe($edit)
            ->and($this->access->can($this->member, $target, 'manage'))->toBe($manage)
            ->and($this->access->can($this->other, $target, 'read'))->toBeFalse();
    }
})->with([
    'reader' => ['read', false, false],
    'editor' => ['edit', true, false],
    'manager' => ['manage', true, true],
]);

it('combines group grants and revokes access immediately when membership disappears', function (string $level, bool $edit, bool $manage) {
    $group = FileGroup::create(['name' => 'Ausbildung']);
    $group->members()->attach($this->member->id, ['is_manager' => false]);
    $this->files->setPermission($this->admin, $this->space, 'group', $group->id, $level);
    $node = $this->files->upload($this->admin, $this->space, null, filesUpload());

    expect($this->access->can($this->member, $node, 'read'))->toBeTrue()
        ->and($this->access->can($this->member, $node, 'edit'))->toBe($edit)
        ->and($this->access->can($this->member, $node, 'manage'))->toBe($manage);
    $group->members()->detach($this->member->id);
    expect($this->access->can($this->member, $node->fresh(), 'read'))->toBeFalse();
})->with([
    'group reader' => ['read', false, false],
    'group editor' => ['edit', true, false],
    'group manager' => ['manage', true, true],
]);

it('breaks inheritance without losing explicit grants and can reenable it', function () {
    $this->files->setPermission($this->admin, $this->space, 'user', $this->member->id, 'edit');
    $folder = $this->files->createFolder($this->admin, $this->space, null, 'Geschützt');
    $node = $this->files->upload($this->admin, $this->space, $folder, filesUpload());
    $this->files->setInheritance($this->admin, $folder, false);

    expect($this->access->can($this->member, $node->fresh(), 'read'))->toBeFalse();
    $this->files->setPermission($this->admin, $node, 'user', $this->member->id, 'read');
    expect($this->access->can($this->member, $node->fresh(), 'read'))->toBeTrue()
        ->and($this->access->can($this->member, $node->fresh(), 'edit'))->toBeFalse();
    $this->files->setInheritance($this->admin, $folder, true);
    expect($this->access->can($this->member, $node->fresh(), 'edit'))->toBeTrue();
});

it('uses the strongest applicable grant without allowing a group manager to bypass ACLs', function () {
    $group = FileGroup::create(['name' => 'Ausbildung']);
    $group->members()->attach($this->member->id, ['is_manager' => true]);
    expect($this->access->can($this->member, $this->space, 'read'))->toBeFalse();
    $this->files->setPermission($this->admin, $this->space, 'group', $group->id, 'edit');
    $this->files->setPermission($this->admin, $this->space, 'user', $this->member->id, 'read');
    expect($this->access->level($this->member, $this->space))->toBe('edit');
    $this->files->setPermission($this->admin, $this->space, 'group', $group->id, null);
    expect($this->access->level($this->member, $this->space))->toBe('read');
});

it('ignores stale group grants after the group moves to a foreign Standort', function () {
    $north = Location::create(['name' => 'Wache Nord', 'slug' => 'dateien-gruppenrechte-nord']);
    $group = FileGroup::create(['name' => 'Ausbildung', 'ext_location_id' => Location::default()->id]);
    $group->members()->attach($this->member->id, ['is_manager' => false]);
    $this->files->setPermission($this->admin, $this->space, 'group', $group->id, 'read');
    expect($this->access->can($this->member, $this->space, 'read'))->toBeTrue();
    $group->update(['ext_location_id' => $north->id]);
    $this->actingAs($this->admin);

    expect($this->access->can($this->member, $this->space, 'read'))->toBeFalse();
    expect(fn () => $this->files->children($this->member, $this->space, null))->toThrow(AuthorizationException::class);
});

it('does not let readers write or editors administer access', function () {
    $node = $this->files->upload($this->admin, $this->space, null, filesUpload());
    $this->files->setPermission($this->admin, $this->space, 'user', $this->member->id, 'read');
    expect(fn () => $this->files->rename($this->member, $node, 'neu.txt'))->toThrow(AuthorizationException::class);
    expect(fn () => $this->files->trash($this->member, $node))->toThrow(AuthorizationException::class);
    expect(fn () => $this->files->upload($this->member, $this->space, null, filesUpload()))->toThrow(AuthorizationException::class);
    expect(fn () => $this->files->addVersion($this->member, $node, filesUpload('Nicht erlaubt')))->toThrow(AuthorizationException::class);
    $this->files->setPermission($this->admin, $this->space, 'user', $this->member->id, 'edit');
    $this->files->rename($this->member, $node, 'neu.txt');
    expect($node->fresh()->name)->toBe('neu.txt');
    expect(fn () => $this->files->setPermission($this->member, $node, 'user', $this->other->id, 'manage'))->toThrow(AuthorizationException::class);
    expect(fn () => $this->files->setInheritance($this->member, $node, false))->toThrow(AuthorizationException::class);
});

it('rejects stale actor instances after module assignment or login access is revoked', function () {
    $node = $this->files->upload($this->admin, $this->space, null, filesUpload());
    $this->files->setPermission($this->admin, $node, 'user', $this->member->id, 'manage');
    $this->files->setPermission($this->admin, $node, 'user', $this->other->id, 'manage');
    $this->member->moduleAccess()->where('module', 'dateien')->delete();
    $this->other->fresh()->update(['is_active' => false]);
    $this->actingAs($this->admin);

    foreach ([$this->member, $this->other] as $revoked) {
        expect($this->access->can($revoked, $node, 'read'))->toBeFalse();
        expect(fn () => $this->files->versions($revoked, $node))->toThrow(AuthorizationException::class);
        expect(fn () => $this->files->trash($revoked, $node))->toThrow(AuthorizationException::class);
    }
    expect($node->fresh()->trashed())->toBeFalse();
});

it('creates immutable versions and restores an old revision as a new version', function () {
    $node = $this->files->upload($this->admin, $this->space, null, filesUpload('Fassung eins'));
    $first = $this->files->versions($this->admin, $node)->sole();
    $second = $this->files->addVersion($this->admin, $node, filesUpload('Fassung zwei'), 1);
    $third = $this->files->restoreVersion($this->admin, $node, $first, 2);

    expect($first->number)->toBe(1)
        ->and($second->number)->toBe(2)
        ->and($third->number)->toBe(3)
        ->and($first->source)->toBe('upload')
        ->and($third->source)->toBe('restore')
        ->and($third->created_by)->toBe($this->admin->id)
        ->and($third->checksum)->toBe($first->checksum)
        ->and($this->files->versions($this->admin, $node)->pluck('number')->all())->toBe([3, 2, 1]);
    Storage::disk('dateien')->assertExists([$first->storage_key, $second->storage_key, $third->storage_key]);
    expect(Storage::disk('dateien')->get($first->storage_key))->toBe('Fassung eins')
        ->and(Storage::disk('dateien')->get($second->storage_key))->toBe('Fassung zwei')
        ->and(Storage::disk('dateien')->get($third->storage_key))->toBe('Fassung eins');
});

it('treats identical uploads idempotently without extra versions or blobs', function () {
    $node = $this->files->upload($this->admin, $this->space, null, filesUpload());
    $first = $node->versions()->sole();
    $blobs = Storage::disk('dateien')->allFiles();
    $same = $this->files->addVersion($this->admin, $node, filesUpload(), 1);

    expect($same->id)->toBe($first->id)
        ->and($node->versions()->count())->toBe(1)
        ->and(Storage::disk('dateien')->allFiles())->toEqualCanonicalizing($blobs);
});

it('rejects stale revisions without changing either metadata or stored files', function () {
    $node = $this->files->upload($this->admin, $this->space, null, filesUpload());
    $first = $node->versions()->sole();
    $this->files->addVersion($this->admin, $node, filesUpload('Neue Fassung'), 1);
    $blobs = Storage::disk('dateien')->allFiles();

    expect(fn () => $this->files->addVersion($this->admin, $node, filesUpload('Veralteter Stand'), 1))->toThrow(ValidationException::class);
    expect(fn () => $this->files->restoreVersion($this->admin, $node, $first, 1))->toThrow(ValidationException::class);
    expect($node->versions()->count())->toBe(2)
        ->and(Storage::disk('dateien')->allFiles())->toEqualCanonicalizing($blobs);
});

it('rejects a version belonging to a different file', function () {
    $first = $this->files->upload($this->admin, $this->space, null, filesUpload());
    $other = $this->files->upload($this->admin, $this->space, null, filesUpload('Fremd', 'fremd.txt'));
    expect(fn () => $this->files->restoreVersion($this->admin, $first, $other->versions()->sole(), 1))->toThrow(ValidationException::class);
    expect($first->versions()->count())->toBe(1);
});

it('enforces immutable version metadata at the PostgreSQL layer', function () {
    $node = $this->files->upload($this->admin, $this->space, null, filesUpload());
    $version = $node->versions()->sole();

    expect(fn () => DB::transaction(fn () => DB::table('x_files_versions')->where('id', $version->id)->update(['checksum' => str_repeat('0', 64)])))
        ->toThrow(QueryException::class);
    expect($version->fresh()->checksum)->toBe(hash('sha256', 'Erste Fassung'));
});

it('enforces sibling uniqueness tree consistency and immutable node identity in PostgreSQL', function () {
    $folder = $this->files->createFolder($this->admin, $this->space, null, 'Ausbildung');
    $child = $this->files->createFolder($this->admin, $this->space, $folder, 'Unterordner');
    $node = $this->files->upload($this->admin, $this->space, null, filesUpload());
    $otherSpace = $this->files->createSpace($this->admin, ['name' => 'Archiv', 'ext_location_id' => Location::default()->id]);

    foreach ([
        fn () => DB::table('x_files_nodes')->insert(['space_id' => $this->space->id, 'name' => 'AUSBILDUNG', 'kind' => 'folder']),
        fn () => DB::table('x_files_nodes')->where('id', $folder->id)->update(['parent_id' => $child->id]),
        fn () => DB::table('x_files_nodes')->where('id', $child->id)->update(['parent_id' => $node->id]),
        fn () => DB::table('x_files_nodes')->where('id', $child->id)->update(['space_id' => $otherSpace->id]),
        fn () => DB::table('x_files_nodes')->where('id', $node->id)->update(['kind' => 'folder']),
    ] as $invalidWrite) {
        expect(fn () => DB::transaction($invalidWrite))->toThrow(QueryException::class);
    }
    expect($folder->fresh()->parent_id)->toBeNull()
        ->and($child->fresh()->parent_id)->toBe($folder->id)
        ->and($node->fresh()->kind)->toBe('file');
});

it('rejects duplicate sibling names on upload folder creation rename and move', function () {
    $folder = $this->files->createFolder($this->admin, $this->space, null, 'Ausbildung');
    $target = $this->files->createFolder($this->admin, $this->space, null, 'Ziel');
    $node = $this->files->upload($this->admin, $this->space, $folder, filesUpload());
    $this->files->upload($this->admin, $this->space, $target, filesUpload('Anderer Inhalt'));
    $this->files->createFolder($this->admin, $this->space, $folder, 'belegt.txt');
    $blobs = Storage::disk('dateien')->allFiles();

    expect(fn () => $this->files->createFolder($this->admin, $this->space, null, 'Ausbildung'))->toThrow(ValidationException::class);
    expect(fn () => $this->files->upload($this->admin, $this->space, $folder, filesUpload('Konflikt')))->toThrow(ValidationException::class);
    expect(fn () => $this->files->rename($this->admin, $node, 'belegt.txt'))->toThrow(ValidationException::class);
    expect(fn () => $this->files->move($this->admin, $node, $target))->toThrow(ValidationException::class);
    expect($node->fresh()->parent_id)->toBe($folder->id)
        ->and(Storage::disk('dateien')->allFiles())->toEqualCanonicalizing($blobs);
});

it('hides descendants of trashed folders and rejects restoring over an occupied name', function () {
    $this->files->setPermission($this->admin, $this->space, 'user', $this->member->id, 'read');
    $folder = $this->files->createFolder($this->admin, $this->space, null, 'Ausbildung');
    $node = $this->files->upload($this->admin, $this->space, $folder, filesUpload());
    $this->files->trash($this->admin, $folder);

    expect($this->access->can($this->member, $node->fresh(), 'read'))->toBeFalse()
        ->and($this->files->children($this->member, $this->space, null))->toBeEmpty()
        ->and($this->files->shared($this->member))->toBeEmpty();
    $replacement = $this->files->createFolder($this->admin, $this->space, null, 'Ausbildung');
    expect(fn () => $this->files->restore($this->admin, $folder))->toThrow(ValidationException::class);
    $this->files->rename($this->admin, $replacement, 'Neu');
    $this->files->restore($this->admin, $folder);
    expect($this->access->can($this->member, $node->fresh(), 'read'))->toBeTrue();
});

it('rejects IDOR writes and requires access to both source and destination', function () {
    $folder = $this->files->createFolder($this->admin, $this->space, null, 'Geheim');
    $node = $this->files->upload($this->admin, $this->space, $folder, filesUpload());
    $destination = $this->files->createFolder($this->admin, $this->space, null, 'Eingang');
    $this->files->setPermission($this->admin, $destination, 'user', $this->member->id, 'edit');

    expect(fn () => $this->files->move($this->member, $node, $destination))->toThrow(AuthorizationException::class);
    expect(fn () => $this->files->trash($this->member, $node))->toThrow(AuthorizationException::class);
    expect(fn () => $this->files->versions($this->member, $node))->toThrow(AuthorizationException::class);
    $this->files->setPermission($this->admin, $node, 'user', $this->member->id, 'manage');
    expect(fn () => $this->files->move($this->member, $node, $folder))->toThrow(AuthorizationException::class);
    expect($node->fresh()->parent_id)->toBe($folder->id);
});

it('rejects folder cycles and cross-space moves', function () {
    $folder = $this->files->createFolder($this->admin, $this->space, null, 'Ausbildung');
    $child = $this->files->createFolder($this->admin, $this->space, $folder, 'Unterordner');
    $otherSpace = $this->files->createSpace($this->admin, ['name' => 'Archiv', 'ext_location_id' => Location::default()->id]);
    $foreign = $this->files->createFolder($this->admin, $otherSpace, null, 'Fremd');

    expect(fn () => $this->files->move($this->admin, $folder, $child))->toThrow(ValidationException::class);
    expect(fn () => $this->files->move($this->admin, $folder, $folder))->toThrow(ValidationException::class);
    expect(fn () => $this->files->move($this->admin, $child, $foreign))->toThrow(ValidationException::class);
    expect(fn () => $this->files->upload($this->admin, $this->space, $foreign, filesUpload()))->toThrow(ValidationException::class);
});

it('uses the explicit actor for Standort boundaries even while an administrator is authenticated', function () {
    $north = Location::create(['name' => 'Wache Nord', 'slug' => 'dateien-nord']);
    $foreign = filesMember('Nora Nord', $north);
    $node = $this->files->upload($this->admin, $this->space, null, filesUpload());
    Permission::create([
        'subject_type' => 'user', 'subject_id' => $foreign->id,
        'target_type' => 'space', 'target_id' => $this->space->id, 'level' => 'manage',
    ]);
    $this->actingAs($this->admin);

    expect($this->access->can($foreign, $node, 'read'))->toBeFalse();
    expect(fn () => $this->files->children($foreign, $this->space, null))->toThrow(AuthorizationException::class);
    expect(fn () => $this->files->rename($foreign, $node, 'fremd.txt'))->toThrow(AuthorizationException::class);
    expect(fn () => $this->files->upload($foreign, $this->space, null, filesUpload()))->toThrow(AuthorizationException::class);
    expect(fn () => $this->files->trash($foreign, $node))->toThrow(AuthorizationException::class);
    expect($node->fresh()->name)->toBe('dienstplan.txt');
});

it('counts retained versions against quota and leaves no blobs on quota failures', function () {
    $this->space->update(['quota_bytes' => 12]);
    $node = $this->files->upload($this->admin, $this->space, null, filesUpload('12345678'));
    $blobs = Storage::disk('dateien')->allFiles();

    expect(fn () => $this->files->addVersion($this->admin, $node, filesUpload('abcdefgh'), 1))->toThrow(ValidationException::class);
    expect(fn () => $this->files->upload($this->admin, $this->space, null, filesUpload('abcdefgh', 'neu.txt')))->toThrow(ValidationException::class);
    expect($node->versions()->count())->toBe(1)
        ->and(Storage::disk('dateien')->allFiles())->toEqualCanonicalizing($blobs);
    $this->files->trash($this->admin, $node);
    expect(fn () => $this->files->upload($this->admin, $this->space, null, filesUpload('abcdefgh', 'neu.txt')))->toThrow(ValidationException::class);
});

it('applies quota to restored revisions but permits an idempotent retry at the limit', function () {
    $this->space->update(['quota_bytes' => 16]);
    $node = $this->files->upload($this->admin, $this->space, null, filesUpload('12345678'));
    $first = $node->versions()->sole();
    $second = $this->files->addVersion($this->admin, $node, filesUpload('abcdefgh'), 1);
    $blobs = Storage::disk('dateien')->allFiles();

    expect(fn () => $this->files->restoreVersion($this->admin, $node, $first, 2))->toThrow(ValidationException::class);
    expect($this->files->addVersion($this->admin, $node, filesUpload('abcdefgh'), 2)->id)->toBe($second->id)
        ->and($node->versions()->count())->toBe(2)
        ->and(Storage::disk('dateien')->allFiles())->toEqualCanonicalizing($blobs);
});

it('rejects unsafe names without touching metadata or storage', function (string $name) {
    expect(fn () => $this->files->createFolder($this->admin, $this->space, null, $name))->toThrow(ValidationException::class);
    expect(Node::count())->toBe(0)
        ->and(Storage::disk('dateien')->allFiles())->toBeEmpty();
})->with(['../ausbruch', '..', '.', 'eins/zwei', 'eins\\zwei', "eins\0zwei", '']);

it('rejects executable and MIME-disguised uploads without leaving files behind', function () {
    expect(fn () => $this->files->upload($this->admin, $this->space, null, filesUpload('<?php echo "no";', 'shell.php')))->toThrow(ValidationException::class);
    expect(fn () => $this->files->upload($this->admin, $this->space, null, filesUpload('<?php echo "no";', 'bild.png')))->toThrow(ValidationException::class);
    expect(Node::count())->toBe(0)
        ->and(Version::count())->toBe(0)
        ->and(Storage::disk('dateien')->allFiles())->toBeEmpty();
});

it('rejects oversized uploads and extension-changing renames', function () {
    config(['dateien.max_upload_kb' => 1]);
    expect(fn () => $this->files->upload($this->admin, $this->space, null, filesUpload(str_repeat('x', 2048))))->toThrow(ValidationException::class);
    expect(Storage::disk('dateien')->allFiles())->toBeEmpty();
    $node = $this->files->upload($this->admin, $this->space, null, filesUpload());
    expect(fn () => $this->files->rename($this->admin, $node, 'dienstplan.php'))->toThrow(ValidationException::class);
    expect($node->fresh()->name)->toBe('dienstplan.txt')
        ->and($node->versions()->count())->toBe(1);
});

it('removes newly written blobs when the database transaction fails after storage succeeds', function () {
    DB::listen(function ($query) {
        if (str_contains($query->sql, 'insert into "x_files_audit_events"')) {
            throw new RuntimeException('Invented database failure');
        }
    });

    expect(fn () => $this->files->upload($this->admin, $this->space, null, filesUpload()))->toThrow(RuntimeException::class);
    expect(Node::count())->toBe(0)
        ->and(Version::count())->toBe(0)
        ->and(Storage::disk('dateien')->allFiles())->toBeEmpty();
});

it('cleans new blobs when a caller rolls back after nested file operations committed', function () {
    $node = $this->files->upload($this->admin, $this->space, null, filesUpload());
    $blobs = Storage::disk('dateien')->allFiles();

    expect(fn () => DB::transaction(function () use ($node) {
        $this->files->addVersion($this->admin, $node, filesUpload('Nicht gespeicherte Änderung'), 1);
        $this->files->upload($this->admin, $this->space, null, filesUpload('Nicht gespeichert', 'neu.txt'));
        throw new RuntimeException('Invented outer transaction failure');
    }))->toThrow(RuntimeException::class);

    expect(Node::count())->toBe(1)
        ->and(Version::count())->toBe(1)
        ->and(Storage::disk('dateien')->allFiles())->toEqualCanonicalizing($blobs);
});

it('permanently purges a trashed subtree including versions permissions and private blobs', function () {
    $folder = $this->files->createFolder($this->admin, $this->space, null, 'Ausbildung');
    $node = $this->files->upload($this->admin, $this->space, $folder, filesUpload());
    $this->files->addVersion($this->admin, $node, filesUpload('Zweite Fassung'), 1);
    $this->files->setPermission($this->admin, $node, 'user', $this->member->id, 'read');
    expect(fn () => $this->files->purge($this->admin, $folder))->toThrow(ValidationException::class);
    $this->files->trash($this->admin, $folder);
    expect(fn () => $this->files->purge($this->admin, $folder))->toThrow(ValidationException::class);
    expect(Version::count())->toBe(2);
    $this->travel(31)->days();
    $this->files->purge($this->admin, $folder);

    expect(Node::withTrashed()->count())->toBe(0)
        ->and(Version::count())->toBe(0)
        ->and(Permission::where('target_type', 'node')->count())->toBe(0)
        ->and(Storage::disk('dateien')->allFiles())->toBeEmpty();
});

it('does not delete retained blobs when an outer purge transaction rolls back', function () {
    $node = $this->files->upload($this->admin, $this->space, null, filesUpload());
    $version = $node->versions()->sole();
    $this->files->trash($this->admin, $node);
    $this->travel(31)->days();

    expect(fn () => DB::transaction(function () use ($node, $version) {
        $this->files->purge($this->admin, $node);
        $this->files->retryPendingDeletions($this->admin);
        Storage::disk('dateien')->assertExists($version->storage_key);
        throw new RuntimeException('Invented purge rollback');
    }))->toThrow(RuntimeException::class);

    expect(Node::withTrashed()->findOrFail($node->id)->trashed())->toBeTrue()
        ->and(Version::count())->toBe(1)
        ->and(DB::table('x_files_pending_deletions')->count())->toBe(0);
    Storage::disk('dateien')->assertExists($version->storage_key);
});

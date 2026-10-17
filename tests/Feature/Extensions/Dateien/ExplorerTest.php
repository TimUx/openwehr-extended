<?php

use App\Models\Module;
use App\Models\User;
use Extensions\Dateien\Livewire\Explorer;
use Extensions\Dateien\Models\Node;
use Extensions\Dateien\Models\Permission;
use Extensions\Dateien\Services\FileAccess;
use Extensions\Dateien\Services\FileService;
use Extensions\Locations\Models\Location;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Module::where('key', 'dateien')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Storage::fake('dateien');
    $this->admin = filesMember('Ada Atemschutz', admin: true);
    $this->member = filesMember();
    $this->files = app(FileService::class);
    $this->space = $this->files->createSpace($this->admin, [
        'name' => 'Vertraulicher Bereich',
        'ext_location_id' => Location::default()->id,
        'quota_bytes' => 1024 * 1024,
    ]);
    $this->folder = $this->files->createFolder($this->admin, $this->space, null, 'Vertraulicher Elternordner');
    $this->node = $this->files->upload($this->admin, $this->space, $this->folder, filesUpload());
    $this->version = $this->node->versions()->sole();
});

it('redirects guests and rejects unassigned and inactive members on technical downloads', function () {
    $url = route('dateien.download', ['node' => $this->node->id, 'version' => $this->version->id]);
    $this->get(route('dateien.index'))->assertRedirect();
    $this->get($url)->assertRedirect();

    $unassigned = User::factory()->create(['name' => 'Tina Tank', 'email' => 'tina.tank@example.com']);
    $this->actingAs($unassigned)->get(route('dateien.index'))->assertForbidden();
    $this->actingAs($unassigned)->get($url)->assertForbidden();
    Livewire::actingAs($unassigned)->test(Explorer::class)->assertForbidden();
    $this->files->setPermission($this->admin, $this->node, 'user', $this->member->id, 'read');
    $this->member->update(['is_active' => false]);
    $this->actingAs($this->member)->get(route('dateien.index'))->assertRedirect();
    $this->actingAs($this->member)->get($url)->assertRedirect();
    expect(app(FileAccess::class)->can($this->member, $this->node, 'read'))->toBeFalse();
});

it('disables the explorer downloads and direct service calls when the module is off', function () {
    Module::where('key', 'dateien')->update(['is_active' => false]);
    Cache::forget('modules_active_keys');
    $this->actingAs($this->admin)->get(route('dateien.index'))->assertNotFound();
    $this->actingAs($this->admin)->get(route('dateien.download', ['node' => $this->node->id, 'version' => $this->version->id]))->assertNotFound();
    Livewire::actingAs($this->admin)->test(Explorer::class)->assertForbidden();
    expect(fn () => $this->files->children($this->admin, $this->space, null))->toThrow(AuthorizationException::class);
    expect(fn () => $this->files->addVersion($this->admin, $this->node, filesUpload('Nicht erlaubt')))->toThrow(AuthorizationException::class);
});

it('authorizes every download and binds each revision to its actual file', function () {
    $url = route('dateien.download', ['node' => $this->node->id, 'version' => $this->version->id]);
    $this->actingAs($this->member)->get($url)->assertForbidden();
    $this->files->setPermission($this->admin, $this->node, 'user', $this->member->id, 'read');
    $this->actingAs($this->member)->get($url)
        ->assertOk()->assertContent('Erste Fassung')
        ->assertHeader('content-type', 'text/plain; charset=utf-8')
        ->assertHeader('x-content-type-options', 'nosniff');
    $other = $this->files->upload($this->admin, $this->space, null, filesUpload('Andere Datei', 'andere.txt'));
    $this->actingAs($this->member)->get(route('dateien.download', [
        'node' => $this->node->id, 'version' => $other->versions()->sole()->id,
    ]))->assertNotFound();
    $this->files->setPermission($this->admin, $this->node, 'user', $this->member->id, null);
    $this->actingAs($this->member)->get($url)->assertForbidden();
});

it('downloads percent and Unicode filenames with a safe encoded disposition', function (string $name) {
    $node = $this->files->upload($this->admin, $this->space, null, filesUpload(name: $name));
    $response = $this->actingAs($this->admin)->get(route('dateien.download', [
        'node' => $node->id, 'version' => $node->versions()->sole()->id,
    ]))->assertOk()->assertContent('Erste Fassung')
        ->assertHeader('content-type', 'text/plain; charset=utf-8')
        ->assertHeader('x-content-type-options', 'nosniff');

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->toContain("filename*=utf-8''".rawurlencode($name))
        ->and($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
})->with(['Fortschritt 100%.txt', 'Übung 100%.txt']);

it('returns not found rather than an error when the private blob is missing', function () {
    Storage::disk('dateien')->delete($this->version->storage_key);

    $this->actingAs($this->admin)->get(route('dateien.download', [
        'node' => $this->node->id, 'version' => $this->version->id,
    ]))->assertNotFound();
});

it('rejects corrupted stored bytes rather than serving an unverified file', function () {
    Storage::disk('dateien')->put($this->version->storage_key, 'Beschädigt');
    $this->actingAs($this->admin)->getJson(route('dateien.download', [
        'node' => $this->node->id, 'version' => $this->version->id,
    ]))->assertUnprocessable()->assertJsonValidationErrors('file');
});

it('does not serve descendants of a trashed folder even through an old download link', function () {
    $this->files->setPermission($this->admin, $this->node, 'user', $this->member->id, 'read');
    $this->files->trash($this->admin, $this->folder);
    $this->actingAs($this->member)->get(route('dateien.download', [
        'node' => $this->node->id, 'version' => $this->version->id,
    ]))->assertForbidden();
});

it('shows a direct share without revealing its inaccessible space or ancestors', function () {
    $this->files->setPermission($this->admin, $this->node, 'user', $this->member->id, 'read');
    expect($this->files->shared($this->member)->pluck('id')->all())->toContain($this->node->id);
    $this->actingAs($this->member)->get(route('dateien.index', ['node' => $this->node->id]))
        ->assertOk()->assertSee('dienstplan.txt')
        ->assertDontSee('Vertraulicher Bereich')->assertDontSee('Vertraulicher Elternordner');
    Livewire::actingAs($this->member)->test(Explorer::class, ['view' => 'shared'])
        ->assertSee('dienstplan.txt')
        ->assertDontSee('Vertraulicher Bereich')
        ->assertDontSee('Vertraulicher Elternordner')
        ->call('select', $this->node->id)
        ->assertSee('dienstplan.txt')
        ->assertDontSee('Vertraulicher Bereich')
        ->assertDontSee('Vertraulicher Elternordner');
});

it('displays the effective permission of a selected share', function (string $level, string $label) {
    $this->files->setPermission($this->admin, $this->node, 'user', $this->member->id, $level);

    Livewire::actingAs($this->member)->test(Explorer::class, ['view' => 'shared'])
        ->call('select', $this->node->id)->assertSee('Ihr Recht: '.$label);
})->with([
    'reader' => ['read', 'Lesen'],
    'editor' => ['edit', 'Bearbeiten'],
    'manager' => ['manage', 'Verwalten'],
]);

it('opens a shared folder without exposing inaccessible breadcrumb ancestors', function () {
    $shared = $this->files->createFolder($this->admin, $this->space, $this->folder, 'Freigegebene Ausbildung');
    $child = $this->files->createFolder($this->admin, $this->space, $shared, 'Unterricht');
    $this->files->upload($this->admin, $this->space, $shared, filesUpload());
    $this->files->setPermission($this->admin, $shared, 'user', $this->member->id, 'read');

    Livewire::actingAs($this->member)->test(Explorer::class, ['view' => 'shared'])
        ->assertSee('Freigegebene Ausbildung')
        ->call('navigate', null, $shared->id)
        ->assertSet('space', null)
        ->assertSee('dienstplan.txt')
        ->assertSee('Freigegebene Ausbildung')
        ->assertDontSee('Vertraulicher Bereich')
        ->assertDontSee('Vertraulicher Elternordner')
        ->call('navigate', null, $child->id)
        ->assertSet('space', null)
        ->assertSee('Freigegebene Ausbildung')
        ->assertSee('Unterricht')
        ->assertDontSee('Vertraulicher Bereich')
        ->assertDontSee('Vertraulicher Elternordner');
});

it('rejects manually selected foreign nodes and URL contexts in the explorer', function () {
    Livewire::actingAs($this->member)->test(Explorer::class)
        ->call('select', $this->node->id)->assertForbidden();
    Livewire::actingAs($this->member)->test(Explorer::class)
        ->call('navigate', $this->space->id)->assertForbidden();
    Livewire::actingAs($this->member)->test(Explorer::class)
        ->set('node', $this->folder->id)->assertForbidden();
    expect($this->node->fresh()->trashed())->toBeFalse();
});

it('locks selected record IDs and optimistic version tokens against client tampering', function (string $property) {
    expect(fn () => Livewire::actingAs($this->admin)->test(Explorer::class)
        ->call('select', $this->node->id)->set($property, 999999))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with(['selectedId', 'expectedVersion']);

it('refuses a forged move destination even when the selected file is manageable', function () {
    $destination = $this->files->createFolder($this->admin, $this->space, null, 'Gesperrtes Ziel');
    $this->files->setPermission($this->admin, $this->node, 'user', $this->member->id, 'manage');

    Livewire::actingAs($this->member)->test(Explorer::class, ['view' => 'shared'])
        ->call('select', $this->node->id)
        ->set('destination', (string) $destination->id)
        ->call('move')->assertForbidden();
    expect($this->node->fresh()->parent_id)->toBe($this->folder->id);
});

it('uploads edits and restores versions through the explorer with a stale-tab conflict', function () {
    $this->files->setPermission($this->admin, $this->space, 'user', $this->member->id, 'edit');
    $firstTab = Livewire::actingAs($this->member)->test(Explorer::class, ['space' => $this->space->id])
        ->call('select', $this->node->id)->assertSet('expectedVersion', 1);
    $secondTab = Livewire::actingAs($this->member)->test(Explorer::class, ['space' => $this->space->id])
        ->call('select', $this->node->id);
    $firstTab->set('upload', filesUpload('Neue Fassung'))->call('addVersion')->assertHasNoErrors()
        ->assertSet('expectedVersion', 2);
    $secondTab->set('upload', filesUpload('Veralteter Stand'))->call('addVersion')->assertHasErrors();
    expect($this->node->versions()->count())->toBe(2);
    $firstTab->call('restoreVersion', $this->version->id)->assertHasNoErrors()
        ->assertSet('expectedVersion', 3);
    expect($this->node->versions()->orderByDesc('number')->first()->checksum)->toBe($this->version->checksum);
});

it('rechecks rights after a file has already been selected', function (string $action) {
    $this->files->setPermission($this->admin, $this->node, 'user', $this->member->id, 'manage');
    $component = Livewire::actingAs($this->member)->test(Explorer::class, ['view' => 'shared'])
        ->call('select', $this->node->id);
    $this->files->setPermission($this->admin, $this->node, 'user', $this->member->id, null);
    $component->call($action)->assertForbidden();
    expect(Node::find($this->node->id))->not->toBeNull();
})->with(['trash', 'move', 'rename']);

it('saves and revokes direct permissions and inheritance from the explorer', function () {
    $component = Livewire::actingAs($this->admin)->test(Explorer::class, ['space' => $this->space->id])
        ->call('select', $this->node->id)
        ->set('subjectId', (string) $this->member->id)
        ->set('permissionLevel', 'read')->call('savePermission')->assertHasNoErrors()
        ->set('inheritPermissions', false)->call('saveInheritance')->assertHasNoErrors();

    expect($this->node->fresh()->inherit_permissions)->toBeFalse()
        ->and(app(FileAccess::class)->can($this->member, $this->node, 'read'))->toBeTrue();
    $permission = Permission::where('target_type', 'node')->where('target_id', $this->node->id)->sole();
    $component->call('revokePermission', $permission->id)->assertHasNoErrors();
    expect(app(FileAccess::class)->can($this->member, $this->node, 'read'))->toBeFalse();
});

it('binds permission revocation to the selected target and refuses reader privilege escalation', function () {
    $other = $this->files->upload($this->admin, $this->space, null, filesUpload('Fremd', 'fremd.txt'));
    $this->files->setPermission($this->admin, $other, 'user', $this->member->id, 'read');
    $permission = Permission::where('target_type', 'node')->where('target_id', $other->id)->sole();
    Livewire::actingAs($this->admin)->test(Explorer::class)
        ->call('select', $this->node->id)->call('revokePermission', $permission->id)->assertNotFound();
    expect($permission->fresh())->not->toBeNull();

    $this->files->setPermission($this->admin, $this->node, 'user', $this->member->id, 'read');
    Livewire::actingAs($this->member)->test(Explorer::class, ['view' => 'shared'])
        ->call('select', $this->node->id)
        ->set('subjectId', (string) $this->member->id)
        ->set('permissionLevel', 'manage')->call('savePermission')->assertForbidden();
    expect(app(FileAccess::class)->level($this->member, $this->node))->toBe('read');
});

it('renders trash restores folders and purges files only after the retention period', function () {
    $component = Livewire::actingAs($this->admin)->test(Explorer::class)
        ->call('select', $this->folder->id)->call('trash')->assertHasNoErrors()
        ->call('navigate', null, null, 'trash')->assertSee('Vertraulicher Elternordner')
        ->call('select', $this->folder->id)->call('restore')->assertHasNoErrors();
    expect($this->folder->fresh()->trashed())->toBeFalse();
    $component->call('navigate')
        ->call('select', $this->node->id)->call('trash')->assertHasNoErrors()
        ->call('navigate', null, null, 'trash')->call('select', $this->node->id)
        ->call('purge')->assertHasErrors();
    $this->travel(31)->days();
    $component->call('purge')->assertHasNoErrors();
    expect(Node::withTrashed()->find($this->node->id))->toBeNull();
    Storage::disk('dateien')->assertMissing($this->version->storage_key);
});

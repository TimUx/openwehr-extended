<?php

use App\Models\Module;
use App\Models\Setting;
use Extensions\Dateien\Filament\DateienPage;
use Extensions\Dateien\Models\FileGroup;
use Extensions\Dateien\Models\Space;
use Extensions\Dateien\Services\GroupService;
use Extensions\Locations\Models\Location;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Module::where('key', 'dateien')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = filesMember('Ada Atemschutz', admin: true);
    $this->manager = filesMember('Hanna Hydrant');
    $this->manager->moduleAccess()->where('module', 'dateien')->update(['role' => 'gruppenverwalter']);
});

it('renders file administration in the existing panel only for authorized managers', function () {
    $this->actingAs($this->admin)->get('/admin/dateien')->assertOk()->assertSee('Dateien verwalten');
    $this->actingAs($this->manager)->get('/admin/dateien')->assertOk()->assertSee('Dateigruppen');
    $this->actingAs(filesMember('Bernd Blaulicht'))->get('/admin/dateien')->assertForbidden();
    Module::where('key', 'dateien')->update(['is_active' => false]);
    Cache::forget('modules_active_keys');
    $this->actingAs($this->admin)->get('/admin/dateien')->assertForbidden();
});

it('creates spaces and saves validated upload settings as a global admin', function () {
    Livewire::actingAs($this->admin)->test(DateienPage::class)
        ->set('spaceName', 'Ausbildungsunterlagen')
        ->set('spaceLocationId', Location::default()->id)
        ->set('quotaMb', 25)->call('createSpace')->assertHasNoErrors()
        ->fillForm(['max_upload_kb' => 1024, 'extensions' => ['txt', 'pdf'], 'retention_days' => 7])
        ->call('saveSettings')->assertHasNoErrors();

    expect(Space::sole()->quota_bytes)->toBe(25 * 1024 * 1024)
        ->and(Setting::get('dateien.max_upload_kb'))->toEqual(1024)
        ->and(json_decode(Setting::get('dateien.extensions'), true))->toBe(['txt', 'pdf'])
        ->and(Setting::get('dateien.retention_days'))->toEqual(7);

    Livewire::actingAs($this->admin)->test(DateienPage::class)
        ->fillForm(['max_upload_kb' => 51201, 'extensions' => ['php'], 'retention_days' => -1])
        ->call('saveSettings')->assertHasErrors();
});

it('lets module managers maintain groups but not change global settings or spaces', function () {
    Livewire::actingAs($this->manager)->test(DateienPage::class)
        ->set('groupName', 'Ausbildung')->call('saveGroup')->assertHasNoErrors();

    expect(FileGroup::sole()->name)->toBe('Ausbildung')
        ->and(FileGroup::sole()->ext_location_id)->toBe($this->manager->ext_location_id);

    Livewire::actingAs($this->manager)->test(DateienPage::class)
        ->call('saveSettings')->assertForbidden();
    Livewire::actingAs($this->manager)->test(DateienPage::class)
        ->set('spaceName', 'Nicht erlaubt')->call('createSpace')->assertForbidden();
    Livewire::actingAs($this->manager)->test(DateienPage::class)
        ->call('retryDeletions')->assertForbidden();
    expect(Space::count())->toBe(0);
});

it('rechecks management authority after the page has been opened', function () {
    $component = Livewire::actingAs($this->manager)->test(DateienPage::class);
    $this->manager->moduleAccess()->where('module', 'dateien')->update(['role' => null]);
    $component->set('groupName', 'Nicht erlaubt')->call('saveGroup')->assertForbidden();
    expect(FileGroup::count())->toBe(0);
});

it('locks the selected administration group against client tampering', function () {
    $group = app(GroupService::class)->save($this->admin, null, 'Ausbildung', Location::default()->id);

    expect(fn () => Livewire::actingAs($this->admin)->test(DateienPage::class)
        ->call('editGroup', $group->id)->set('groupId', 999999))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('rejects foreign Standort group IDs and membership targets through administration actions', function () {
    $north = Location::create(['name' => 'Wache Nord', 'slug' => 'dateien-admin-nord']);
    $foreign = filesMember('Nora Nord', $north);
    $groups = app(GroupService::class);
    $localGroup = $groups->save($this->admin, null, 'Ausbildung', Location::default()->id);
    $foreignGroup = $groups->save($this->admin, null, 'Fahrzeugwarte', $north->id);

    Livewire::actingAs($this->manager)->test(DateienPage::class)
        ->assertSee('Ausbildung')->assertDontSee('Fahrzeugwarte')->assertDontSee('Nora Nord')
        ->call('editGroup', $foreignGroup->id)->assertForbidden();
    Livewire::actingAs($this->manager)->test(DateienPage::class)
        ->call('editGroup', $localGroup->id)
        ->set('memberId', $foreign->id)->call('saveMember')->assertForbidden();
    expect($localGroup->members()->count())->toBe(0);
});

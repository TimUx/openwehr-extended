<?php

use App\Models\Module;
use Extensions\Dateien\Models\FileGroup;
use Extensions\Dateien\Services\FileService;
use Extensions\Dateien\Services\GroupService;
use Extensions\Locations\Models\Location;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Module::where('key', 'dateien')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Storage::fake('dateien');
    $this->admin = filesMember('Ada Atemschutz', admin: true);
    $this->manager = filesMember('Hanna Hydrant');
    $this->manager->moduleAccess()->where('module', 'dateien')->update(['role' => 'gruppenverwalter']);
    $this->member = filesMember('Bernd Blaulicht');
    $this->groups = app(GroupService::class);
});

it('allows administrators and module managers to maintain group memberships', function () {
    $group = $this->groups->save($this->admin, null, 'Ausbildung', Location::default()->id);
    $this->groups->setMember($this->admin, $group, $this->manager->id, true);
    $this->groups->setMember($this->manager, $group, $this->member->id, false);

    expect(GroupService::canManage($this->manager))->toBeTrue()
        ->and(GroupService::canManage($this->member))->toBeFalse()
        ->and($group->members()->pluck('users.id')->all())->toContain($this->manager->id, $this->member->id);
    $this->groups->setMember($this->manager, $group, $this->member->id, null);
    expect($group->members()->whereKey($this->member->id)->exists())->toBeFalse();
});

it('limits module managers to shared and own Standort groups without granting file administration', function () {
    $north = Location::create(['name' => 'Wache Nord', 'slug' => 'dateigruppen-nord']);
    $group = $this->groups->save($this->admin, null, 'Ausbildung', Location::default()->id);
    $other = $this->groups->save($this->admin, null, 'Fahrzeugwarte', $north->id);
    $shared = $this->groups->save($this->admin, null, 'Gemeinsame Unterlagen', null);
    $this->groups->setMember($this->admin, $group, $this->manager->id, true);
    $files = app(FileService::class);
    $space = $files->createSpace($this->admin, ['name' => 'Archiv', 'ext_location_id' => Location::default()->id]);

    $this->actingAs($this->admin);
    expect($this->groups->groups($this->manager)->pluck('id')->all())->toEqualCanonicalizing([$group->id, $shared->id]);
    $this->groups->save($this->manager, $shared, 'Gemeinsame Ausbildung', null);
    expect($shared->fresh()->name)->toBe('Gemeinsame Ausbildung');
    expect(fn () => $this->groups->setMember($this->manager, $other, $this->member->id, true))->toThrow(HttpException::class);
    expect(fn () => $this->groups->save($this->manager, $other, 'Übernommen', null))->toThrow(HttpException::class);
    expect(app(\Extensions\Dateien\Services\FileAccess::class)->can($this->manager, $space, 'manage'))->toBeFalse();
    expect($other->fresh()->name)->toBe('Fahrzeugwarte');
});

it('prevents ordinary members from creating groups or escalating group membership', function () {
    $group = $this->groups->save($this->admin, null, 'Ausbildung', null);
    $this->groups->setMember($this->admin, $group, $this->member->id, false);

    expect(fn () => $this->groups->save($this->member, null, 'Neue Gruppe', null))->toThrow(HttpException::class);
    expect(fn () => $this->groups->setMember($this->member, $group, $this->member->id, true))->toThrow(HttpException::class);
    expect(fn () => $this->groups->setMember($this->member, $group, $this->admin->id, null))->toThrow(HttpException::class);
    expect(FileGroup::count())->toBe(1)
        ->and(GroupService::canManage($this->member))->toBeFalse();
});

it('does not grant module administration merely from a membership manager flag', function () {
    $group = $this->groups->save($this->admin, null, 'Ausbildung', Location::default()->id);
    $this->groups->setMember($this->admin, $group, $this->member->id, true);

    expect(GroupService::canManage($this->member))->toBeFalse();
    expect(fn () => $this->groups->setMember($this->member, $group, $this->admin->id, null))->toThrow(HttpException::class);
});

it('creates module-manager groups in the actor Standort despite a foreign location parameter', function () {
    $north = Location::create(['name' => 'Wache Nord', 'slug' => 'dateigruppen-ziel-nord']);
    $this->actingAs($this->admin);
    $group = $this->groups->save($this->manager, null, 'Ausbildung', $north->id);

    expect($group->ext_location_id)->toBe($this->manager->ext_location_id);
});

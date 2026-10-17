<?php

use App\Models\Module;
use Extensions\Dateien\Services\GroupService;
use Extensions\Locations\Models\Location;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

it('allows authorized managers to revoke memberships after a member changes Standort', function (bool $asAdmin) {
    Module::where('key', 'dateien')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $admin = filesMember('Ada Atemschutz', admin: true);
    $manager = filesMember('Hanna Hydrant');
    $manager->moduleAccess()->where('module', 'dateien')->update(['role' => 'gruppenverwalter']);
    $member = filesMember('Bernd Blaulicht');
    $groups = app(GroupService::class);
    $group = $groups->save($admin, null, 'Ausbildung', Location::default()->id);
    $groups->setMember($admin, $group, $member->id, false);
    $north = Location::create(['name' => 'Wache Nord', 'slug' => 'dateien-widerruf-nord']);
    $member->forceFill(['ext_location_id' => $north->id])->save();
    DB::table('x_location_user')->where('user_id', $member->id)->delete();
    DB::table('x_location_user')->insert(['user_id' => $member->id, 'location_id' => $north->id, 'role' => 'member']);
    $member->moduleAccess()->where('module', 'dateien')->delete();

    $groups->setMember($asAdmin ? $admin : $manager, $group, $member->id, null);

    expect($group->members()->whereKey($member->id)->exists())->toBeFalse();
})->with([true, false]);

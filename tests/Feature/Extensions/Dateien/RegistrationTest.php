<?php

use App\Models\Module;
use Illuminate\Support\Facades\Route;

it('registers Dateien as an optional module that is disabled on a fresh installation', function () {
    $module = Module::where('key', 'dateien')->sole();

    expect($module->is_active)->toBeFalse()
        ->and(Route::has('dateien.index'))->toBeTrue()
        ->and(Route::has('dateien.download'))->toBeTrue()
        ->and(config('filesystems.disks.dateien.visibility'))->toBe('private')
        ->and(config('filesystems.disks.dateien.root'))->toBe(storage_path('app/private/dateien'));
});

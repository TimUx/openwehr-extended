<?php

use Extensions\Dateien\Http\Controllers\DownloadController;
use Illuminate\Support\Facades\Route;

Route::prefix('dateien')->name('dateien.')
    ->middleware(['auth', 'active', 'module:dateien'])
    ->group(function () {
        Route::get('/', fn () => view('dateien::livewire.page'))->name('index');
        Route::get('/dateien/{node}/versionen/{version}', DownloadController::class)
            ->whereNumber(['node', 'version'])->name('download');
    });

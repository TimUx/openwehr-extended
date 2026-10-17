<?php

namespace Extensions\Dateien;

use App\Models\User;
use App\Services\ModuleRegistry;
use App\Support\ModuleNav;
use Extensions\Dateien\Filament\DateienPlugin;
use Extensions\Dateien\Livewire\Explorer;
use Extensions\Dateien\Services\FileAccess;
use Extensions\Dateien\Services\FileService;
use Extensions\Dateien\Services\GroupService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class DateienServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/dateien.php', 'dateien');
        config(['filesystems.disks.dateien' => [
            'driver' => 'local',
            'root' => storage_path('app/private/dateien'),
            'visibility' => 'private',
            'throw' => true,
        ]]);
        $this->app->singleton(FileAccess::class);
        $this->app->singleton(FileService::class);
        $this->app->singleton(GroupService::class);
        $this->app->booting(fn () => Filament::getPanel('admin')?->plugin(DateienPlugin::make()));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadViewsFrom(__DIR__.'/resources/views', 'dateien');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group(__DIR__.'/routes/web.php');
        }
        Livewire::component('dateien.explorer', Explorer::class);

        $registry = $this->app->make(ModuleRegistry::class);
        $registry->register(
            'dateien',
            'Dateien',
            'Private Dateiablage mit Dateigruppen, Zugriffsrechten und Versionen',
            roles: ['gruppenverwalter'],
            provider: self::class,
            route: 'dateien.index',
        );
        $registry->navigation('dateien', fn (User $user) => [
            'tabs' => [ModuleNav::tab('Dateien', 'dateien.index')],
            'manage' => GroupService::canManage($user) ? url('/admin/dateien') : null,
        ]);
    }
}

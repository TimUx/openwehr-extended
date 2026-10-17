<?php

namespace Extensions\Dateien\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;

class DateienPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'dateien';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([DateienPage::class]);
    }

    public function boot(Panel $panel): void {}
}

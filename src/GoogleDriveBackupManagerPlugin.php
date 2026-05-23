<?php

namespace Webteractive\GoogleDriveBackupManager;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Webteractive\GoogleDriveBackupManager\Filament\Resources\Backups\BackupResource;
use Webteractive\GoogleDriveBackupManager\Filament\Widgets\BackupStatsWidget;

class GoogleDriveBackupManagerPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'google-drive-backup-manager';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->resources([
                BackupResource::class,
            ])
            ->widgets([
                BackupStatsWidget::class,
            ]);
    }

    public function boot(Panel $panel): void {}
}

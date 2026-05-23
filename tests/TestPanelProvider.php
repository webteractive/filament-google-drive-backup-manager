<?php

namespace Webteractive\GoogleDriveBackupManager\Tests;

use Filament\Panel;
use Filament\PanelProvider;
use Webteractive\GoogleDriveBackupManager\Filament\Resources\Backups\BackupResource;

class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('test')
            ->default()
            ->path('admin')
            ->resources([BackupResource::class]);
    }
}

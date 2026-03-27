<?php

namespace Webteractive\GoogleDriveBackupManager\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Webteractive\GoogleDriveBackupManager\Models\Backup;

class RunBackup implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct(public bool $onlyDb = false) {}

    public function handle(): void
    {
        Artisan::call('backup:run', array_filter([
            '--only-db' => $this->onlyDb,
            '--no-interaction' => true,
            '--disable-notifications' => true,
        ]));

        Backup::clearCache();
    }
}

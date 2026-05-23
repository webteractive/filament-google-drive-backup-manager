<?php

namespace Webteractive\GoogleDriveBackupManager\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Webteractive\GoogleDriveBackupManager\Models\Setting;

/**
 * Asynchronously deletes a single file from the configured backup disk
 * (typically `gdbm`, so a Google Drive call). Runs in the queue so bulk
 * delete operations don't block the Livewire request on per-file Drive
 * round-trips.
 */
class DeleteDriveBackupFile implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public function __construct(
        public string $diskName,
        public string $path,
    ) {}

    /**
     * Dispatch respecting the `jobs_run_sync` setting — queued by default,
     * inline when the host opted out of a queue worker.
     */
    public static function for(string $diskName, string $path): void
    {
        (bool) Setting::get('jobs_run_sync')
            ? self::dispatchSync($diskName, $path)
            : self::dispatch($diskName, $path);
    }

    public function handle(): void
    {
        try {
            Storage::disk($this->diskName)->delete($this->path);
        } catch (Throwable $e) {
            Log::warning('gdbm drive file delete failed', [
                'disk' => $this->diskName,
                'path' => $this->path,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

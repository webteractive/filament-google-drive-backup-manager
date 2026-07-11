<?php

namespace Webteractive\GoogleDriveBackupManager\Jobs;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Webteractive\GoogleDriveBackupManager\Models\Backup;

/**
 * Reconciles the `gdbm_backups` table against what actually remains on the
 * backup disk. Spatie's `backup:clean` deletes old backup *files* per the
 * retention strategy but never touches our rows, so after a cleanup the table
 * keeps rows pointing at files that no longer exist. This removes those rows
 * so the resource list reflects what is really stored on Drive.
 *
 * Runs inline as part of {@see RunCleanup}, immediately after `backup:clean`.
 *
 * Only settled rows that once produced a file (Completed with a non-null path)
 * are candidates. In-progress rows are always preserved — their file may not
 * exist yet. If the existence check itself fails (e.g. a transient Drive
 * outage), the row is kept: "unknown" must never be treated as "deleted".
 */
class ReconcileBackupRows
{
    public function handle(): void
    {
        Backup::query()
            ->completed()
            ->whereNotNull('path')
            ->get()
            ->each(function (Backup $backup): void {
                try {
                    $exists = Storage::disk($backup->disk)->exists($backup->path);
                } catch (Throwable $e) {
                    Log::warning('gdbm reconcile: existence check failed, keeping row', [
                        'backup_id' => $backup->id,
                        'disk' => $backup->disk,
                        'path' => $backup->path,
                        'error' => $e->getMessage(),
                    ]);

                    return;
                }

                if (! $exists) {
                    $backup->delete();
                }
            });
    }
}

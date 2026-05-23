<?php

namespace Webteractive\GoogleDriveBackupManager\Jobs;

use Webteractive\GoogleDriveBackupManager\Enums\BackupStatus;
use Webteractive\GoogleDriveBackupManager\Models\Backup;
use Webteractive\GoogleDriveBackupManager\Models\Setting;

/**
 * Deletes `gdbm_backups` rows older than the configured retention. Invoked
 * inline at the end of the cleanup schedule callback (see service provider),
 * not via the queue, so we deliberately don't implement ShouldQueue — the
 * actual destructive work is the Spatie `backup:clean` call that runs just
 * before us; pruning the row table afterwards is fast.
 *
 * Spatie's `backup:clean` removes old files from the destination disk, but
 * the rows in our table pointing at them stay forever. This class prunes
 * rows whose lifecycle is settled (Completed or Failed) and whose
 * `completed_at` is older than the retention window. In-progress rows are
 * always preserved.
 */
class PruneBackupRows
{
    public function handle(): void
    {
        $days = (int) (Setting::get('cleanup_prune_rows_after_days') ?? 0);

        if ($days <= 0) {
            return;
        }

        Backup::query()
            ->whereIn('status', [BackupStatus::Completed->value, BackupStatus::Failed->value])
            ->where(function ($query) use ($days): void {
                $query->where('completed_at', '<', now()->subDays($days))
                    ->orWhere(function ($inner) use ($days): void {
                        $inner->whereNull('completed_at')
                            ->where('created_at', '<', now()->subDays($days));
                    });
            })
            ->delete();
    }
}

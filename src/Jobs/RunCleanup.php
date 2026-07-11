<?php

namespace Webteractive\GoogleDriveBackupManager\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Webteractive\GoogleDriveBackupManager\GoogleDriveBackupManagerServiceProvider;
use Webteractive\GoogleDriveBackupManager\Models\Backup;
use Webteractive\GoogleDriveBackupManager\Models\Setting;

/**
 * Runs Spatie's retention cleanup against the gdbm disk, then prunes settled
 * `gdbm_backups` rows. This is the exact work the scheduled cleanup performs;
 * it is also dispatched manually from the "Run Cleanup" header action.
 *
 * Dispatch through {@see self::queueRun()} so the sync-vs-queue behaviour
 * matches {@see Backup::queueRun()}.
 *
 * The dispatch helper must NOT be named `queue()`: Laravel's bus dispatcher
 * treats a `queue()` method on a queued command as a custom queueing hook
 * (`Dispatcher::dispatchToQueue()`), which would recurse into itself here.
 */
class RunCleanup implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function handle(): void
    {
        // Long-running queue workers (Horizon with --max-time=0) boot the
        // provider once and snapshot Spatie's cleanup.default_strategy from
        // settings as they were AT BOOT. Retention values saved through the UI
        // afterwards never reach those workers without this re-apply.
        app()->getProvider(GoogleDriveBackupManagerServiceProvider::class)
            ?->applyBackupConfig();

        // Let Spatie fire CleanupWasSuccessful / CleanupHasFailed —
        // SendBackupNotifications listens for both and respects the user's
        // notify_events checklist.
        $exitCode = Artisan::call('backup:clean', ['--no-interaction' => true]);

        // Spatie's backup:clean swallows internal exceptions and exits non-zero
        // instead of throwing. Surface that so a failed cleanup is visible to
        // the queue (and the scheduled run) rather than silently "succeeding".
        if ($exitCode !== 0) {
            throw new RuntimeException(
                'backup:clean exited with code '.$exitCode.': '.trim(Artisan::output()),
            );
        }

        // Spatie just deleted files from Drive but left our rows behind. Drop
        // the rows whose file no longer exists so the list reflects what is
        // actually stored.
        (new ReconcileBackupRows)->handle();

        // Then clear out settled rows older than the configured window so
        // gdbm_backups doesn't grow without bound. No-op unless
        // cleanup_prune_rows_after_days is set.
        (new PruneBackupRows)->handle();
    }

    /**
     * Dispatch a cleanup honouring the jobs_run_sync toggle and configured
     * queue — mirrors Backup::queueRun().
     */
    public static function queueRun(): void
    {
        $job = new self;

        if ((bool) Setting::get('jobs_run_sync')) {
            dispatch_sync($job);

            return;
        }

        $queue = Setting::get('queue') ?: config('google-drive-backup-manager.queue');

        if ($queue) {
            $job->onQueue($queue);
        }

        dispatch($job);
    }
}

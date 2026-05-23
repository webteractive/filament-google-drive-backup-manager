<?php

namespace Webteractive\GoogleDriveBackupManager\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;
use Webteractive\GoogleDriveBackupManager\Enums\BackupStatus;
use Webteractive\GoogleDriveBackupManager\GoogleDriveBackupManagerServiceProvider;
use Webteractive\GoogleDriveBackupManager\Models\Backup;
use Webteractive\GoogleDriveBackupManager\Support\BackupMessages;

class RunBackup implements ShouldQueue
{
    use Queueable;

    public const CURRENT_BACKUP_CACHE_KEY = 'gdbm:current-backup-id';

    public int $timeout = 300;

    /**
     * @param  array<int, string>|null  $databases  Restrict the DB dump to these connection names (passed as --db-name). Null = use Spatie config.
     */
    public function __construct(
        public int $backupId,
        public bool $onlyDb = false,
        public ?array $databases = null,
    ) {}

    public function handle(): void
    {
        $record = Backup::query()->find($this->backupId);

        if (! $record) {
            // The record was deleted before the job ran — still execute the
            // backup, the success/failure listener will create a fresh row.
            $this->runBackupCommand();

            return;
        }

        $record->update([
            'status' => BackupStatus::Running,
            'started_at' => now(),
        ]);

        try {
            $this->runBackupCommand();
            // BackupWasSuccessful listener finalizes the record with path/size.
        } catch (Throwable $e) {
            $record->refresh();

            if (in_array($record->status, [BackupStatus::Pending, BackupStatus::Running], true)) {
                $record->update([
                    'status' => BackupStatus::Failed,
                    'error_message' => BackupMessages::redact($e->getMessage()),
                    'completed_at' => now(),
                ]);
            }

            throw $e;
        }
    }

    private function runBackupCommand(): void
    {
        // Long-running queue workers (Horizon with --max-time=0) boot the
        // provider once and snapshot Spatie's source.files/databases config
        // from settings as they were AT BOOT. Settings saved through the UI
        // afterwards never reach those workers without this re-apply.
        app()->getProvider(GoogleDriveBackupManagerServiceProvider::class)
            ?->applyBackupConfig();

        $args = array_filter([
            '--only-db' => $this->onlyDb,
            '--only-to-disk' => config('google-drive-backup-manager.disk', 'gdbm'),
            '--no-interaction' => true,
        ]);

        if (! empty($this->databases)) {
            $args['--db-name'] = array_values($this->databases);
        }

        // Stash the current backup ID so the BackupWasSuccessful /
        // BackupHasFailed listeners can correlate the event back to the row
        // that was being processed. Use Cache::add so concurrent jobs don't
        // clobber each other.
        //
        // If we lose the race (another worker is already running a backup),
        // we MUST NOT also fire backup:run. Two simultaneous Spatie runs
        // produce interleaved BackupWasSuccessful events, and the listener
        // would correlate them to the wrong rows. Mark this row Failed with
        // a "concurrent run" reason and let the original job own the outcome.
        $weOwnTheKey = Cache::add(self::CURRENT_BACKUP_CACHE_KEY, $this->backupId, now()->addMinutes(15));

        if (! $weOwnTheKey) {
            throw new RuntimeException(
                'Another backup is already running. This run was skipped to avoid event-correlation conflicts.',
            );
        }

        try {
            $exitCode = Artisan::call('backup:run', $args);
        } finally {
            Cache::forget(self::CURRENT_BACKUP_CACHE_KEY);
        }

        // Spatie's backup:run swallows internal exceptions and exits non-zero
        // instead of throwing — without this check, setup-time failures (disk
        // init, missing config) leave rows permanently Running because the
        // BackupHasFailed event never fires.
        if ($exitCode !== 0) {
            throw new RuntimeException(
                'backup:run exited with code '.$exitCode.': '.trim(Artisan::output()),
            );
        }
    }
}

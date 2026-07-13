<?php

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Webteractive\GoogleDriveBackupManager\Enums\BackupStatus;
use Webteractive\GoogleDriveBackupManager\GoogleDriveBackupManagerServiceProvider;
use Webteractive\GoogleDriveBackupManager\Jobs\RunBackup;
use Webteractive\GoogleDriveBackupManager\Models\Backup;
use Webteractive\GoogleDriveBackupManager\Models\Setting;

/**
 * RunBackup shells out to `Artisan::call('backup:run', ...)`. To avoid pulling
 * in spatie/laravel-backup's real command (which needs disk + DB config), we
 * register a stand-in directly on the console kernel and route assertions
 * through its `$handler` callback.
 */
function fakeBackupCommand(?callable $handler = null, int $exitCode = Command::SUCCESS): void
{
    /** @var ConsoleKernel $kernel */
    $kernel = app(ConsoleKernel::class);

    $command = new class extends Command
    {
        protected $signature = 'backup:run {--only-db} {--only-to-disk=} {--db-name=*} {--disable-notifications}';

        /** @var callable|null */
        public static $handler = null;

        public static int $exitCode = Command::SUCCESS;

        public function handle(): int
        {
            if (self::$handler) {
                (self::$handler)($this);
            }

            return self::$exitCode;
        }
    };

    $command::$handler = $handler;
    $command::$exitCode = $exitCode;

    $kernel->registerCommand($command);
}

it('implements ShouldQueue and has a 5 minute timeout', function () {
    $job = new RunBackup(backupId: 1);

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->timeout)->toBe(300);
});

it('marks the backup as Running before invoking backup:run', function () {
    $backup = Backup::query()->create([
        'disk' => 'gdbm',
        'status' => BackupStatus::Pending,
    ]);

    $observed = null;

    fakeBackupCommand(function () use ($backup, &$observed) {
        $backup->refresh();
        $observed = $backup->status;
    });

    (new RunBackup(backupId: $backup->id))->handle();

    expect($observed)->toBe(BackupStatus::Running);
});

it('marks the backup as Failed when the backup command throws', function () {
    $backup = Backup::query()->create([
        'disk' => 'gdbm',
        'status' => BackupStatus::Pending,
    ]);

    fakeBackupCommand(function () {
        throw new RuntimeException('boom');
    });

    try {
        (new RunBackup(backupId: $backup->id))->handle();
    } catch (Throwable) {
        // expected
    }

    $backup->refresh();

    expect($backup->status)->toBe(BackupStatus::Failed)
        ->and($backup->error_message)->toContain('boom')
        ->and($backup->completed_at)->not->toBeNull();
});

it('throws and marks Failed when backup:run returns a non-zero exit code', function () {
    $backup = Backup::query()->create([
        'disk' => 'gdbm',
        'status' => BackupStatus::Pending,
    ]);

    fakeBackupCommand(null, exitCode: Command::FAILURE);

    try {
        (new RunBackup(backupId: $backup->id))->handle();
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('exited with code');
    }

    $backup->refresh();

    expect($backup->status)->toBe(BackupStatus::Failed);
});

it('stashes the current backup id in cache while running and clears it after', function () {
    $backup = Backup::query()->create([
        'disk' => 'gdbm',
        'status' => BackupStatus::Pending,
    ]);

    $observedDuringRun = null;

    fakeBackupCommand(function () use (&$observedDuringRun) {
        $observedDuringRun = Cache::get(RunBackup::CURRENT_BACKUP_CACHE_KEY);
    });

    (new RunBackup(backupId: $backup->id))->handle();

    expect($observedDuringRun)->toBe($backup->id)
        ->and(Cache::get(RunBackup::CURRENT_BACKUP_CACHE_KEY))->toBeNull();
});

it('passes --db-name args when databases are specified', function () {
    $backup = Backup::query()->create([
        'disk' => 'gdbm',
        'status' => BackupStatus::Pending,
    ]);

    $observedDbNames = null;

    fakeBackupCommand(function (Command $cmd) use (&$observedDbNames) {
        $observedDbNames = $cmd->option('db-name');
    });

    (new RunBackup(backupId: $backup->id, onlyDb: true, databases: ['mysql', 'pgsql']))->handle();

    expect($observedDbNames)->toBe(['mysql', 'pgsql']);
});

it('still runs the backup command when the record was deleted before the job ran', function () {
    $called = false;

    fakeBackupCommand(function () use (&$called) {
        $called = true;
    });

    (new RunBackup(backupId: 999_999))->handle();

    expect($called)->toBeTrue();
});

it('skips backup:run when another run already holds the cache key', function () {
    // Simulate a sibling worker already in the middle of a run.
    Cache::put(RunBackup::CURRENT_BACKUP_CACHE_KEY, 999, now()->addMinutes(15));

    $backup = Backup::query()->create([
        'disk' => 'gdbm',
        'status' => BackupStatus::Pending,
    ]);

    $invoked = false;
    fakeBackupCommand(function () use (&$invoked) {
        $invoked = true;
    });

    try {
        (new RunBackup(backupId: $backup->id))->handle();
    } catch (RuntimeException) {
        // expected — second worker bails out so the listener doesn't
        // cross-correlate events between runs.
    }

    $backup->refresh();

    expect($invoked)->toBeFalse()
        ->and($backup->status)->toBe(BackupStatus::Failed)
        ->and(Cache::get(RunBackup::CURRENT_BACKUP_CACHE_KEY))->toBe(999); // not clobbered
});

it('re-resolves Spatie config from settings at job runtime so stale workers still pick up file_targets', function () {
    // Simulate "worker booted before user saved file_targets": empty out the
    // setting and re-trigger config wiring so config('...source.files.include')
    // starts at the boot-time empty state.
    Setting::forget('file_targets');
    app()->getProvider(GoogleDriveBackupManagerServiceProvider::class)->applyBackupConfig();
    expect(config('backup.backup.source.files.include'))->toBe([]);

    // User saves file_targets via the UI (mid-process — the long-running
    // worker would NOT pick this up on its own).
    Setting::set('file_targets', [
        ['path' => '/tmp/include-me', 'exclude' => []],
    ]);

    $backup = Backup::query()->create([
        'disk' => 'gdbm',
        'status' => BackupStatus::Pending,
    ]);

    fakeBackupCommand();

    (new RunBackup(backupId: $backup->id))->handle();

    expect(config('backup.backup.source.files.include'))->toBe(['/tmp/include-me']);
});

it('points the health-check monitor at the destination disk under the environment name', function () {
    app()->getProvider(GoogleDriveBackupManagerServiceProvider::class)->applyBackupConfig();

    $monitor = config('backup.monitor_backups');

    expect($monitor)->toHaveCount(1)
        ->and($monitor[0]['name'])->toBe(app()->environment())
        ->and($monitor[0]['disks'])->toContain('gdbm')
        ->and($monitor[0]['disks'])->toBe(config('backup.backup.destination.disks'))
        ->and($monitor[0]['health_checks'])->not->toBeEmpty();
});

it('can be dispatched to a specific queue', function () {
    Queue::fake();

    $job = new RunBackup(backupId: 1);
    $job->onQueue('backups');
    dispatch($job);

    Queue::assertPushedOn('backups', RunBackup::class);
});

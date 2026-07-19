<?php

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Webteractive\GoogleDriveBackupManager\Enums\BackupStatus;
use Webteractive\GoogleDriveBackupManager\GoogleDriveBackupManagerServiceProvider;
use Webteractive\GoogleDriveBackupManager\Jobs\RunCleanup;
use Webteractive\GoogleDriveBackupManager\Models\Backup;
use Webteractive\GoogleDriveBackupManager\Models\Setting;

/**
 * RunCleanup shells out to `Artisan::call('backup:clean', ...)`. To avoid
 * pulling in spatie/laravel-backup's real command (which needs disk + DB
 * config), we register a stand-in on the console kernel and route assertions
 * through its `$handler` callback — same shape as RunBackupTest's fake.
 */
function fakeCleanCommand(?callable $handler = null, int $exitCode = Command::SUCCESS): void
{
    /** @var ConsoleKernel $kernel */
    $kernel = app(ConsoleKernel::class);

    $command = new class extends Command
    {
        protected $signature = 'backup:clean {--disable-notifications}';

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
    $job = new RunCleanup;

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->timeout)->toBe(300);
});

it('invokes backup:clean', function () {
    $called = false;

    fakeCleanCommand(function () use (&$called) {
        $called = true;
    });

    (new RunCleanup)->handle();

    expect($called)->toBeTrue();
});

it('throws when backup:clean returns a non-zero exit code', function () {
    fakeCleanCommand(null, exitCode: Command::FAILURE);

    expect(fn () => (new RunCleanup)->handle())
        ->toThrow(RuntimeException::class, 'exited with code');
});

it('prunes settled rows older than the configured window after cleaning', function () {
    Setting::set('cleanup_prune_rows_after_days', 7);
    fakeCleanCommand();

    $old = Backup::query()->create([
        'disk' => 'gdbm',
        'status' => BackupStatus::Completed,
        'completed_at' => now()->subDays(30),
    ]);
    $recent = Backup::query()->create([
        'disk' => 'gdbm',
        'status' => BackupStatus::Completed,
        'completed_at' => now()->subDays(3),
    ]);

    (new RunCleanup)->handle();

    expect(Backup::query()->find($old->id))->toBeNull()
        ->and(Backup::query()->find($recent->id))->not->toBeNull();
});

it('reconciles rows against the disk after cleaning, dropping ones whose file is gone', function () {
    // RunCleanup->applyBackupConfig() now forgets the cached gdbm disk each run
    // (so long-lived workers rebuild a fresh Google client). A Storage::fake()
    // instance is registered via set() and wouldn't survive that forget, so
    // back the disk with a real local driver on the same root — the reconcile
    // step then rebuilds an equivalent disk from config, mirroring production.
    $root = storage_path('framework/testing/disks/gdbm');
    config(['filesystems.disks.gdbm' => ['driver' => 'local', 'root' => $root, 'throw' => false]]);
    Storage::fake('gdbm');
    fakeCleanCommand();

    Storage::disk('gdbm')->put('coopit/kept.zip', 'bytes');

    $kept = Backup::query()->create([
        'disk' => 'gdbm',
        'status' => BackupStatus::Completed,
        'path' => 'coopit/kept.zip',
        'completed_at' => now(),
    ]);
    $gone = Backup::query()->create([
        'disk' => 'gdbm',
        'status' => BackupStatus::Completed,
        'path' => 'coopit/deleted-by-spatie.zip',
        'completed_at' => now(),
    ]);

    (new RunCleanup)->handle();

    expect(Backup::query()->find($kept->id))->not->toBeNull()
        ->and(Backup::query()->find($gone->id))->toBeNull();
});

it('re-resolves Spatie cleanup config from settings at job runtime', function () {
    // Simulate "worker booted before user tightened retention": clear the
    // setting and re-wire config so it starts at the boot-time default.
    Setting::forget('cleanup_keep_daily_days');
    app()->getProvider(GoogleDriveBackupManagerServiceProvider::class)->applyBackupConfig();
    $bootValue = config('backup.cleanup.default_strategy.keep_daily_backups_for_days');

    // User saves a new value via the UI mid-process.
    Setting::set('cleanup_keep_daily_days', 99);
    fakeCleanCommand();

    (new RunCleanup)->handle();

    expect(config('backup.cleanup.default_strategy.keep_daily_backups_for_days'))
        ->toBe(99)
        ->not->toBe($bootValue);
});

it('runs synchronously when jobs_run_sync is set', function () {
    // No Queue::fake() here: dispatch_sync must actually execute handle()
    // inline, which is the whole point of the sync path. Faking the queue
    // would intercept it and nothing would run.
    Setting::set('jobs_run_sync', true);

    $ran = false;
    fakeCleanCommand(function () use (&$ran) {
        $ran = true;
    });

    RunCleanup::queueRun();

    expect($ran)->toBeTrue();
});

it('dispatches to the configured queue when not running sync', function () {
    Setting::forget('jobs_run_sync');
    Setting::set('queue', 'backups');
    Queue::fake();

    RunCleanup::queueRun();

    Queue::assertPushedOn('backups', RunCleanup::class);
});

it('dispatches without an explicit queue when none is configured', function () {
    Setting::forget('jobs_run_sync');
    Setting::forget('queue');
    Queue::fake();

    RunCleanup::queueRun();

    Queue::assertPushed(RunCleanup::class);
});

<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;
use Webteractive\GoogleDriveBackupManager\GoogleDriveBackupManagerServiceProvider;
use Webteractive\GoogleDriveBackupManager\Models\Setting;
use Webteractive\GoogleDriveBackupManager\Services\GoogleDriveConnection;

it('publishes the config file with sensible defaults', function () {
    expect(config('google-drive-backup-manager'))->toBeArray()
        ->and(config('google-drive-backup-manager.disk'))->toBe('gdbm')
        ->and(config('google-drive-backup-manager.gate'))->toBe('viewBackups')
        ->and(config('google-drive-backup-manager.settings_table'))->toBe('gdbm_settings')
        ->and(config('google-drive-backup-manager.backups_table'))->toBe('gdbm_backups');
});

it('registers package routes', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->pluck('action.as')
        ->filter()
        ->values()
        ->all();

    expect($routes)->toContain('google-drive-backup-manager.google.redirect')
        ->toContain('google-drive-backup-manager.google.callback')
        ->toContain('google-drive-backup-manager.google.disconnect')
        ->toContain('backup.download');
});

it('binds the GoogleDriveConnection as a singleton', function () {
    expect(app(GoogleDriveConnection::class))->toBe(app(GoogleDriveConnection::class));
});

it('registers the gdbm and gdbm_local disks at runtime', function () {
    expect(config('filesystems.disks.gdbm'))->toBeArray()
        ->and(config('filesystems.disks.gdbm.driver'))->toBe('gdbm')
        ->and(config('filesystems.disks.gdbm_local'))->toBeArray()
        ->and(config('filesystems.disks.gdbm_local.driver'))->toBe('local');
});

it('throws when resolving the gdbm disk without an active OAuth connection', function () {
    expect(fn () => Storage::disk('gdbm'))
        ->toThrow(RuntimeException::class, 'Google Drive is not configured');
});

it('disables Spatie\'s native notification map (we own all channels)', function () {
    $map = config('backup.notifications.notifications');

    expect($map)->toBeArray();
    foreach ($map as $channels) {
        expect($channels)->toBe([]);
    }
});

it('sets backup.backup.name to the current environment when config not published', function () {
    expect(config('backup.backup.name'))->toBe(app()->environment());
});

it('flattens database_targets into Spatie source.databases', function () {
    Setting::set('database_targets', [
        ['connection' => 'testing', 'databases' => []],
    ]);

    // Re-run boot to pick up new settings.
    app()->getProvider(GoogleDriveBackupManagerServiceProvider::class)
        ->packageBooted();

    expect(config('backup.backup.source.databases'))->toContain('testing');
});

it('rejects database names that would inject into PDO DSN', function () {
    Setting::set('database_targets', [
        ['connection' => 'testing', 'databases' => ['ok_name', 'bad;dsn=injection']],
    ]);

    app()->getProvider(GoogleDriveBackupManagerServiceProvider::class)
        ->packageBooted();

    $configured = config('backup.backup.source.databases');

    expect($configured)->toContain('testing__ok_name')
        ->and($configured)->not->toContain('testing__bad;dsn=injection');
});

it('overrides Spatie cleanup defaults when cleanup_* settings are set', function () {
    Setting::set('cleanup_keep_daily_days', 99);
    Setting::set('cleanup_max_megabytes', 12345);

    app()->getProvider(GoogleDriveBackupManagerServiceProvider::class)
        ->packageBooted();

    expect(config('backup.cleanup.default_strategy.keep_daily_backups_for_days'))->toBe(99)
        ->and(config('backup.cleanup.default_strategy.delete_oldest_backups_when_using_more_megabytes_than'))->toBe(12345);
});

it('explicitly empties source.files.include when no file_targets are configured', function () {
    Setting::forget('file_targets');

    app()->getProvider(GoogleDriveBackupManagerServiceProvider::class)
        ->packageBooted();

    // Empty → no files in the zip (DB-only backups), not Spatie's default of base_path().
    expect(config('backup.backup.source.files.include'))->toBe([]);
});

it('monitors only the Drive disk, not the appended local destination', function () {
    // Reproduce Spatie's vendor default so the append path runs exactly as in
    // production, where destination.disks starts as ['local'].
    config()->set('backup.backup.destination.disks', ['local']);

    app()->getProvider(GoogleDriveBackupManagerServiceProvider::class)
        ->packageBooted();

    // Spatie's default `local` disk stays in the destination list (so we don't
    // clobber other backup setups)...
    expect(config('backup.backup.destination.disks'))->toContain('local')
        ->toContain('gdbm');

    // ...but the monitor must watch ONLY the Drive disk, because package
    // backups run with --only-to-disk=gdbm and `local` never receives one.
    // Monitoring `local` would perpetually fire UnhealthyBackupWasFound.
    expect(config('backup.monitor_backups.0.disks'))->toBe(['gdbm'])
        ->and(config('backup.monitor_backups.0.name'))->toBe(app()->environment());
});

it('drops the cached gdbm disk on every config re-apply so long-lived workers rebuild a fresh token', function () {
    $provider = app()->getProvider(GoogleDriveBackupManagerServiceProvider::class);
    $provider->packageBooted();

    // Simulate what a long-running Horizon worker holds after its first backup:
    // a resolved gdbm disk cached in the FilesystemManager. Its Google access
    // token was minted once, at build time.
    Storage::fake('gdbm');
    expect(Storage::disk('gdbm'))->not->toBeNull();

    // Re-applying config is what every worker run does (RunBackup / RunCleanup /
    // monitor). It must forget the cached disk so the next resolve rebuilds a
    // fresh Google client and mints a fresh access token.
    $provider->applyBackupConfig();

    // With no connected account in the test env, the rebuilt driver throws —
    // proving the stale fake was forgotten rather than reused.
    expect(fn () => Storage::disk('gdbm'))
        ->toThrow(RuntimeException::class, 'Google Drive is not configured');
});

it('registers backup and cleanup schedules when their settings are enabled', function () {
    Setting::set('schedule_backup_enabled', true);
    Setting::set('schedule_backup_cron', '0 2 * * *');
    Setting::set('schedule_cleanup_enabled', true);
    Setting::set('schedule_cleanup_cron', '0 3 * * *');

    app()->getProvider(GoogleDriveBackupManagerServiceProvider::class)
        ->packageBooted();

    $names = collect(app(Schedule::class)->events())
        ->map(fn ($event) => $event->description)
        ->filter()
        ->values()
        ->all();

    expect($names)->toContain('gdbm:scheduled-backup')
        ->toContain('gdbm:scheduled-cleanup');
});

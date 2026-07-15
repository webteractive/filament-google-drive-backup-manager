<?php

namespace Webteractive\GoogleDriveBackupManager;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use RuntimeException;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\CleanupWasSuccessful;
use Spatie\Backup\Events\HealthyBackupWasFound;
use Spatie\Backup\Events\UnhealthyBackupWasFound;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Webteractive\GoogleDriveBackupManager\Console\UpgradeFromV02Command;
use Webteractive\GoogleDriveBackupManager\Filesystem\GoogleDriveAdapter;
use Webteractive\GoogleDriveBackupManager\Jobs\RunCleanup;
use Webteractive\GoogleDriveBackupManager\Listeners\RecordBackupOutcome;
use Webteractive\GoogleDriveBackupManager\Listeners\SendBackupNotifications;
use Webteractive\GoogleDriveBackupManager\Models\Backup;
use Webteractive\GoogleDriveBackupManager\Models\Setting;
use Webteractive\GoogleDriveBackupManager\Services\GoogleDriveConnection;

class GoogleDriveBackupManagerServiceProvider extends PackageServiceProvider
{
    /**
     * Short key → Spatie Notification class. Drives the Notifications tab
     * checkboxes and the per-event notifications.notifications map.
     */
    public const NOTIFICATION_CLASSES = [
        'backup_successful' => BackupWasSuccessfulNotification::class,
        'backup_failed' => BackupHasFailedNotification::class,
        'healthy_found' => HealthyBackupWasFoundNotification::class,
        'unhealthy_found' => UnhealthyBackupWasFoundNotification::class,
        'cleanup_successful' => CleanupWasSuccessfulNotification::class,
        'cleanup_failed' => CleanupHasFailedNotification::class,
    ];

    public function configurePackage(Package $package): void
    {
        $package
            ->name('google-drive-backup-manager')
            ->hasConfigFile()
            ->hasRoute('web')
            ->hasTranslations()
            ->discoversMigrations()
            ->hasCommand(UpgradeFromV02Command::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(GoogleDriveConnection::class);
    }

    public function packageBooted(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerDisks();
        $this->applyBackupConfig();
        $this->registerSchedule();

        Event::listen(BackupWasSuccessful::class, [RecordBackupOutcome::class, 'handleSuccess']);
        Event::listen(BackupHasFailed::class, [RecordBackupOutcome::class, 'handleFailure']);

        Event::listen(BackupWasSuccessful::class, [SendBackupNotifications::class, 'handleSuccess']);
        Event::listen(BackupHasFailed::class, [SendBackupNotifications::class, 'handleFailure']);
        Event::listen(HealthyBackupWasFound::class, [SendBackupNotifications::class, 'handleHealthy']);
        Event::listen(UnhealthyBackupWasFound::class, [SendBackupNotifications::class, 'handleUnhealthy']);
        Event::listen(CleanupWasSuccessful::class, [SendBackupNotifications::class, 'handleCleanupSuccess']);
        Event::listen(CleanupHasFailed::class, [SendBackupNotifications::class, 'handleCleanupFailure']);

        Storage::extend('gdbm', $this->createGoogleDriver(...));
    }

    protected function registerSchedule(): void
    {
        $this->scheduleTask(
            enabledKey: 'schedule_backup_enabled',
            cronKey: 'schedule_backup_cron',
            name: 'gdbm:scheduled-backup',
            callback: fn () => Backup::queueRun(),
        );

        $this->scheduleTask(
            enabledKey: 'schedule_cleanup_enabled',
            cronKey: 'schedule_cleanup_cron',
            name: 'gdbm:scheduled-cleanup',
            // RunCleanup owns the whole pipeline (re-resolve config → Spatie
            // backup:clean → prune settled rows). Run it inline here so the
            // scheduled and manual "Run Cleanup" paths share one source of
            // truth. It re-applies config itself — long-running schedulers
            // otherwise share the worker's stale-config problem.
            callback: fn () => (new RunCleanup)->handle(),
        );

        $this->scheduleTask(
            enabledKey: 'schedule_monitor_enabled',
            cronKey: 'schedule_monitor_cron',
            name: 'gdbm:scheduled-monitor',
            // Let Spatie fire HealthyBackupWasFound / UnhealthyBackupWasFound;
            // we listen for both.
            callback: function (): void {
                app()->getProvider(self::class)?->applyBackupConfig();
                Artisan::call('backup:monitor', ['--no-interaction' => true]);
            },
        );
    }

    protected function scheduleTask(string $enabledKey, string $cronKey, string $name, callable $callback): void
    {
        if (! (bool) Setting::get($enabledKey)) {
            return;
        }

        $cron = Setting::get($cronKey);

        if (! is_string($cron) || trim($cron) === '') {
            return;
        }

        // The scheduler is resolved lazily by Laravel; this callback registers
        // our task the moment it's instantiated (or immediately, if it
        // already has been).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) use ($cron, $name, $callback): void {
            $schedule->call($callback)
                ->cron(trim($cron))
                ->name($name)
                ->withoutOverlapping()
                ->onOneServer();
        });
    }

    protected function registerDisks(): void
    {
        Config::set('filesystems.disks.gdbm', [
            'driver' => 'gdbm',
        ]);

        Config::set('filesystems.disks.gdbm_local', [
            'driver' => 'local',
            'root' => storage_path('app/private/gdbm-backups'),
            'visibility' => 'private',
            'throw' => false,
        ]);
    }

    /**
     * Write the settings-derived Spatie backup config (destination disk,
     * source databases + files, cleanup retention, notifications). Re-runs
     * cheaply on every backup job so long-lived queue workers always reflect
     * settings saved AFTER the worker booted.
     */
    public function applyBackupConfig(): void
    {
        // If the host has explicitly published Spatie's config, treat that as a
        // hard opt-out — don't override anything from our settings table.
        if (file_exists(config_path('backup.php'))) {
            return;
        }

        $destinations = (array) config('backup.backup.destination.disks', []);

        if (! in_array('gdbm', $destinations, true)) {
            Config::set(
                'backup.backup.destination.disks',
                array_values([...$destinations, 'gdbm']),
            );
        }

        // Spatie nests every backup zip under a folder named `backup.backup.name`
        // (default: APP_NAME). Our disk root is already the user's configured
        // Drive folder, so we re-purpose this prefix to separate environments
        // (e.g. local/ vs production/) — that way the same Drive folder can
        // safely accept backups from multiple environments without collision.
        Config::set('backup.backup.name', $this->app->environment());

        $dbConnections = $this->resolveBackupDatabaseConnections();
        if ($dbConnections !== []) {
            Config::set('backup.backup.source.databases', $dbConnections);
        }

        [$includePaths, $excludePaths] = $this->resolveBackupFilePaths();

        // Empty target list means "no files" — explicitly override Spatie's
        // vendor default (base_path()) so a full backup doesn't accidentally
        // sweep the entire project. DB dumps still run.
        Config::set('backup.backup.source.files.include', $includePaths);

        if ($excludePaths !== []) {
            Config::set('backup.backup.source.files.exclude', $excludePaths);
        }

        $this->configureMonitor();
        $this->configureCleanup();
        $this->configureNotifications();
    }

    /**
     * Point Spatie's health-check monitor at the disk we actually write
     * backups to. Spatie's vendor default monitors the `local` disk under
     * APP_NAME, but our backups land on the Drive disk inside a folder named
     * after the environment (see backup.backup.name above), so without this
     * override `backup:monitor` looks in the wrong place, finds nothing, and
     * falsely fires UnhealthyBackupWasFound.
     *
     * We deliberately monitor ONLY the Drive disk — not the full
     * `backup.backup.destination.disks` list. applyBackupConfig() *appends*
     * `gdbm` to Spatie's default (`['local']`), leaving `local` in place so we
     * don't clobber any other Spatie backup setups. But package backups run
     * with `--only-to-disk=gdbm` (see RunBackup), so `local` never receives a
     * backup. Monitoring it would perpetually report "no backups → unhealthy"
     * even when the Drive backups are perfectly healthy.
     */
    protected function configureMonitor(): void
    {
        $disks = [config('google-drive-backup-manager.disk', 'gdbm')];

        $healthChecks = config('backup.monitor_backups.0.health_checks', [
            MaximumAgeInDays::class => 1,
            MaximumStorageInMegabytes::class => 5000,
        ]);

        Config::set('backup.monitor_backups', [[
            'name' => $this->app->environment(),
            'disks' => $disks,
            'health_checks' => $healthChecks,
        ]]);
    }

    /**
     * Apply user-configured retention values to Spatie's
     * cleanup.default_strategy. Empty settings keep Spatie's vendor defaults.
     */
    protected function configureCleanup(): void
    {
        $map = [
            'cleanup_keep_all_days' => 'keep_all_backups_for_days',
            'cleanup_keep_daily_days' => 'keep_daily_backups_for_days',
            'cleanup_keep_weekly_weeks' => 'keep_weekly_backups_for_weeks',
            'cleanup_keep_monthly_months' => 'keep_monthly_backups_for_months',
            'cleanup_keep_yearly_years' => 'keep_yearly_backups_for_years',
            'cleanup_max_megabytes' => 'delete_oldest_backups_when_using_more_megabytes_than',
        ];

        foreach ($map as $settingKey => $spatieKey) {
            $value = Setting::get($settingKey);

            if ($value === null || $value === '' || ! is_numeric($value)) {
                continue;
            }

            Config::set("backup.cleanup.default_strategy.{$spatieKey}", (int) $value);
        }
    }

    protected function configureNotifications(): void
    {
        // We build richer notifications ourselves in SendBackupNotifications, so
        // turn Spatie's native pipeline off across the board — otherwise the
        // user would receive both our payload and Spatie's plain default.
        $map = [];
        foreach (self::NOTIFICATION_CLASSES as $key => $class) {
            $map[$class] = [];
        }
        Config::set('backup.notifications.notifications', $map);
    }

    /**
     * Read database targets from settings, register temporary Laravel
     * connections for any target that names specific databases (so each
     * one dumps independently), and return the flat list of connection
     * names to pass to Spatie's source.databases.
     *
     * @return array<int, string>
     */
    protected function resolveBackupDatabaseConnections(): array
    {
        $targets = Setting::get('database_targets');

        if (! is_array($targets) || $targets === []) {
            return [];
        }

        $connections = [];

        foreach ($targets as $target) {
            if (! is_array($target)) {
                continue;
            }

            $connection = $target['connection'] ?? null;

            if (! is_string($connection) || $connection === '') {
                continue;
            }

            $databases = array_values(array_filter(
                (array) ($target['databases'] ?? []),
                // Database names get merged into a PDO DSN as `dbname=$value`
                // — chars outside [A-Za-z0-9_-] (notably `;` and `=`) would
                // let a saved-setting value inject arbitrary DSN options.
                fn ($value): bool => is_string($value) && preg_match('/^[A-Za-z0-9_\-]+$/', $value) === 1,
            ));

            if ($databases === []) {
                $connections[] = $connection;

                continue;
            }

            $baseConfig = config("database.connections.{$connection}");

            if (! is_array($baseConfig)) {
                continue;
            }

            foreach ($databases as $database) {
                $cloneName = $connection.'__'.$database;
                Config::set("database.connections.{$cloneName}", array_merge($baseConfig, [
                    'database' => $database,
                ]));
                $connections[] = $cloneName;
            }
        }

        return array_values(array_unique($connections));
    }

    /**
     * Flatten the configured file targets into the two flat arrays Spatie
     * expects (source.files.include + source.files.exclude).
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    protected function resolveBackupFilePaths(): array
    {
        $targets = Setting::get('file_targets');

        if (! is_array($targets) || $targets === []) {
            return [[], []];
        }

        $includes = [];
        $excludes = [];

        foreach ($targets as $target) {
            if (! is_array($target)) {
                continue;
            }

            $path = $target['path'] ?? null;

            if (! is_string($path) || trim($path) === '') {
                continue;
            }

            $includes[] = $path;

            foreach ((array) ($target['exclude'] ?? []) as $excluded) {
                if (is_string($excluded) && trim($excluded) !== '') {
                    $excludes[] = $excluded;
                }
            }
        }

        return [
            array_values(array_unique($includes)),
            array_values(array_unique($excludes)),
        ];
    }

    public function createGoogleDriver($app, $config): FilesystemAdapter
    {
        /** @var GoogleDriveConnection $connection */
        $connection = $app->make(GoogleDriveConnection::class);

        if (! $connection->isConnected() || ! $connection->hasCredentials()) {
            throw new RuntimeException(
                'Google Drive is not configured. Please save OAuth credentials and connect a Google account via the admin panel.'
            );
        }

        $service = $connection->makeDriveService();
        $adapter = new GoogleDriveAdapter($service, $connection->getFolderId() ?? 'root');
        $driver = new Filesystem($adapter);

        return new FilesystemAdapter($driver, $adapter);
    }
}

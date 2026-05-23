<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\CleanupWasSuccessful;
use Spatie\Backup\Events\HealthyBackupWasFound;
use Spatie\Backup\Events\UnhealthyBackupWasFound;
use Webteractive\GoogleDriveBackupManager\Listeners\SendBackupNotifications;
use Webteractive\GoogleDriveBackupManager\Models\Setting;

beforeEach(function () {
    // Drive the listener through the generic-webhook path because it's the
    // easiest to inspect — its JSON payload reflects the full context.
    Setting::set('notify_generic_webhook', 'https://example.test/webhook');
    Http::fake([
        'example.test/*' => Http::response('', 200),
    ]);
});

it('handleHealthy reads disk/backup_name directly from the Spatie event', function () {
    Setting::set('notify_events', ['healthy_found']);

    (new SendBackupNotifications)->handleHealthy(
        new HealthyBackupWasFound(diskName: 'gdbm', backupName: 'local'),
    );

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://example.test/webhook'
            && $request->data()['event'] === 'healthy_found'
            && $request->data()['disk'] === 'gdbm'
            && $request->data()['backup_name'] === 'local';
    });
});

it('handleUnhealthy includes the failureMessages summary', function () {
    Setting::set('notify_events', ['unhealthy_found']);

    $failures = new Collection([
        ['check' => 'NewestBackupIsTooOld', 'message' => 'Newest backup is older than 7 days.'],
        ['check' => 'BackupCount', 'message' => 'No backups found.'],
    ]);

    (new SendBackupNotifications)->handleUnhealthy(
        new UnhealthyBackupWasFound(
            diskName: 'gdbm',
            backupName: 'local',
            failureMessages: $failures,
        ),
    );

    Http::assertSent(function ($request): bool {
        return $request->data()['event'] === 'unhealthy_found'
            && $request->data()['disk'] === 'gdbm'
            && str_contains($request->data()['error'], 'NewestBackupIsTooOld')
            && str_contains($request->data()['error'], 'No backups found');
    });
});

it('handleCleanupSuccess fires with disk/backup_name resolved', function () {
    Setting::set('notify_events', ['cleanup_successful']);

    (new SendBackupNotifications)->handleCleanupSuccess(
        new CleanupWasSuccessful(diskName: 'gdbm', backupName: 'local'),
    );

    Http::assertSent(function ($request): bool {
        return $request->data()['event'] === 'cleanup_successful'
            && $request->data()['disk'] === 'gdbm'
            && $request->data()['backup_name'] === 'local';
    });
});

it('handleCleanupFailure carries the redacted exception message', function () {
    Setting::set('notify_events', ['cleanup_failed']);

    (new SendBackupNotifications)->handleCleanupFailure(
        new CleanupHasFailed(
            exception: new RuntimeException('cleanup blew up — password=hunter2'),
            diskName: 'gdbm',
            backupName: 'local',
        ),
    );

    Http::assertSent(function ($request): bool {
        return $request->data()['event'] === 'cleanup_failed'
            && $request->data()['disk'] === 'gdbm'
            // Redacted credential survived through into the webhook payload.
            && str_contains($request->data()['error'], 'password=***')
            && ! str_contains($request->data()['error'], 'hunter2');
    });
});

it('CleanupHasFailed without disk falls back to "unknown" instead of erroring', function () {
    Setting::set('notify_events', ['cleanup_failed']);

    (new SendBackupNotifications)->handleCleanupFailure(
        new CleanupHasFailed(
            exception: new RuntimeException('no disk in event'),
            diskName: null,
            backupName: null,
        ),
    );

    Http::assertSent(function ($request): bool {
        return $request->data()['disk'] === 'unknown'
            && $request->data()['backup_name'] === 'unknown';
    });
});

it('handlers skip the fan-out when the event is not opted into', function () {
    Setting::set('notify_events', []); // nothing opted in

    (new SendBackupNotifications)->handleHealthy(
        new HealthyBackupWasFound(diskName: 'gdbm', backupName: 'local'),
    );

    Http::assertNothingSent();
});

it('RunBackup-derived handlers (success/failure) still work after the refactor', function () {
    Setting::set('notify_events', ['backup_successful', 'backup_failed']);

    (new SendBackupNotifications)->handleSuccess(
        new BackupWasSuccessful(diskName: 'gdbm', backupName: 'local'),
    );

    (new SendBackupNotifications)->handleFailure(
        new BackupHasFailed(
            exception: new RuntimeException('boom'),
            diskName: 'gdbm',
            backupName: 'local',
        ),
    );

    Http::assertSentCount(2);
});

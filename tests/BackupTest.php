<?php

use Illuminate\Support\Facades\Queue;
use Webteractive\GoogleDriveBackupManager\Enums\BackupStatus;
use Webteractive\GoogleDriveBackupManager\Jobs\RunBackup;
use Webteractive\GoogleDriveBackupManager\Models\Backup;
use Webteractive\GoogleDriveBackupManager\Models\Setting;
use Webteractive\GoogleDriveBackupManager\Tests\TestUser;

it('queueRun creates a Pending row and dispatches RunBackup on the queue by default', function () {
    Queue::fake();

    $record = Backup::queueRun();

    expect($record->status)->toBe(BackupStatus::Pending)
        ->and($record->disk)->toBe('gdbm');

    Queue::assertPushed(RunBackup::class, fn (RunBackup $job): bool => $job->backupId === $record->id && $job->onlyDb === false);
});

it('queueRun forwards the onlyDb flag to RunBackup', function () {
    Queue::fake();

    Backup::queueRun(onlyDb: true);

    Queue::assertPushed(RunBackup::class, fn (RunBackup $job): bool => $job->onlyDb === true);
});

it('queueRun passes the configured queue name on the job', function () {
    Queue::fake();

    Setting::forget('queue');
    config()->set('google-drive-backup-manager.queue', 'gdbm-jobs');

    Backup::queueRun();

    Queue::assertPushed(RunBackup::class, fn (RunBackup $job): bool => $job->queue === 'gdbm-jobs');
});

it('queueRun runs synchronously when jobs_run_sync is enabled', function () {
    // No Queue::fake — dispatch_sync should run inline.
    Setting::set('jobs_run_sync', true);
    fakeBackupCommand();

    $record = Backup::queueRun();
    $record->refresh();

    // Inline execution updates status to Running before backup:run is
    // invoked; with our fake command succeeding, the row's status stays as
    // whatever the listener set it to (or Running if no listener fired).
    expect(in_array($record->status, [BackupStatus::Running, BackupStatus::Completed], true))->toBeTrue();
});

it('Backup scopes filter by lifecycle status', function () {
    Backup::query()->create(['disk' => 'gdbm', 'status' => BackupStatus::Pending]);
    Backup::query()->create(['disk' => 'gdbm', 'status' => BackupStatus::Running]);
    Backup::query()->create(['disk' => 'gdbm', 'status' => BackupStatus::Completed]);
    Backup::query()->create(['disk' => 'gdbm', 'status' => BackupStatus::Failed]);

    expect(Backup::query()->completed()->count())->toBe(1)
        ->and(Backup::query()->failed()->count())->toBe(1)
        ->and(Backup::query()->inProgress()->count())->toBe(2);
});

it('drive_url accessor returns null when drive_file_id is not set', function () {
    $row = Backup::query()->create([
        'disk' => 'gdbm',
        'status' => BackupStatus::Pending,
    ]);

    expect($row->drive_url)->toBeNull();
});

it('drive_url accessor builds the Drive viewer URL', function () {
    $row = Backup::query()->create([
        'disk' => 'gdbm',
        'status' => BackupStatus::Completed,
        'drive_file_id' => 'abc123',
    ]);

    expect($row->drive_url)->toBe('https://drive.google.com/file/d/abc123/view');
});

it('queueRun records the authenticated user on the triggered_by_user_id column', function () {
    Queue::fake();

    $user = TestUser::create(['email' => 'tester@example.com']);

    $this->actingAs($user);

    $record = Backup::queueRun();

    expect($record->triggered_by_user_id)->toBe($user->getKey());
});

<?php

use Illuminate\Support\Facades\Cache;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Webteractive\GoogleDriveBackupManager\Enums\BackupStatus;
use Webteractive\GoogleDriveBackupManager\Jobs\RunBackup;
use Webteractive\GoogleDriveBackupManager\Listeners\RecordBackupOutcome;
use Webteractive\GoogleDriveBackupManager\Models\Backup;

it('finalizes the in-progress backup as Completed on success', function () {
    $pending = Backup::query()->create([
        'disk' => 'gdbm',
        'status' => BackupStatus::Running,
        'started_at' => now(),
    ]);

    (new RecordBackupOutcome)->handleSuccess(
        new BackupWasSuccessful(diskName: 'gdbm', backupName: 'app')
    );

    $pending->refresh();

    expect($pending->status)->toBe(BackupStatus::Completed)
        ->and($pending->completed_at)->not->toBeNull();
});

it('creates a fresh Completed row when nothing is in progress', function () {
    (new RecordBackupOutcome)->handleSuccess(
        new BackupWasSuccessful(diskName: 'gdbm', backupName: 'app')
    );

    $record = Backup::query()->latest('id')->first();

    expect($record)->not->toBeNull()
        ->and($record->status)->toBe(BackupStatus::Completed)
        ->and($record->disk)->toBe('gdbm');
});

it('records the failure reason on the in-progress row', function () {
    $pending = Backup::query()->create([
        'disk' => 'gdbm',
        'status' => BackupStatus::Running,
        'started_at' => now(),
    ]);

    (new RecordBackupOutcome)->handleFailure(
        new BackupHasFailed(new RuntimeException('drive is full'), diskName: 'gdbm')
    );

    $pending->refresh();

    expect($pending->status)->toBe(BackupStatus::Failed)
        ->and($pending->error_message)->toBe('drive is full')
        ->and($pending->completed_at)->not->toBeNull();
});

it('creates a fresh Failed row when nothing is in progress', function () {
    (new RecordBackupOutcome)->handleFailure(
        new BackupHasFailed(new RuntimeException('boom'), diskName: 'gdbm')
    );

    $record = Backup::query()->latest('id')->first();

    expect($record)->not->toBeNull()
        ->and($record->status)->toBe(BackupStatus::Failed)
        ->and($record->error_message)->toBe('boom');
});

it('falls back to the configured disk when the event omits one', function () {
    config()->set('google-drive-backup-manager.disk', 'custom-disk');

    (new RecordBackupOutcome)->handleFailure(
        new BackupHasFailed(new RuntimeException('nope'))
    );

    $record = Backup::query()->latest('id')->first();

    expect($record->disk)->toBe('custom-disk');
});

it('correlates events to the right row via the RunBackup cache key', function () {
    // Two concurrent in-progress rows — the listener must pick the one our
    // RunBackup stashed in cache, not "latest in progress".
    $first = Backup::query()->create([
        'disk' => 'gdbm', 'status' => BackupStatus::Running, 'started_at' => now()->subMinute(),
    ]);
    $second = Backup::query()->create([
        'disk' => 'gdbm', 'status' => BackupStatus::Running, 'started_at' => now(),
    ]);

    // The "wrong" pick would be $second (newest id). Cache says it's $first.
    Cache::put(RunBackup::CURRENT_BACKUP_CACHE_KEY, $first->id, now()->addMinute());

    (new RecordBackupOutcome)->handleSuccess(
        new BackupWasSuccessful(diskName: 'gdbm', backupName: 'app')
    );

    $first->refresh();
    $second->refresh();

    expect($first->status)->toBe(BackupStatus::Completed)
        ->and($second->status)->toBe(BackupStatus::Running);
});

it('falls back to "latest in progress" when no cache key is set', function () {
    Cache::forget(RunBackup::CURRENT_BACKUP_CACHE_KEY);

    $row = Backup::query()->create([
        'disk' => 'gdbm', 'status' => BackupStatus::Running, 'started_at' => now(),
    ]);

    (new RecordBackupOutcome)->handleSuccess(
        new BackupWasSuccessful(diskName: 'gdbm', backupName: 'app')
    );

    $row->refresh();

    expect($row->status)->toBe(BackupStatus::Completed);
});

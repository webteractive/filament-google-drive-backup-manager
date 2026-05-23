<?php

use Webteractive\GoogleDriveBackupManager\Enums\BackupStatus;
use Webteractive\GoogleDriveBackupManager\Jobs\PruneBackupRows;
use Webteractive\GoogleDriveBackupManager\Models\Backup;
use Webteractive\GoogleDriveBackupManager\Models\Setting;

it('is a no-op when no retention is configured', function () {
    $row = Backup::query()->create([
        'disk' => 'gdbm',
        'status' => BackupStatus::Completed,
        'completed_at' => now()->subYears(5),
    ]);

    Setting::forget('cleanup_prune_rows_after_days');

    (new PruneBackupRows)->handle();

    expect(Backup::query()->find($row->id))->not->toBeNull();
});

it('prunes Completed rows older than the configured threshold', function () {
    Setting::set('cleanup_prune_rows_after_days', 7);

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

    (new PruneBackupRows)->handle();

    expect(Backup::query()->find($old->id))->toBeNull()
        ->and(Backup::query()->find($recent->id))->not->toBeNull();
});

it('also prunes Failed rows older than the threshold', function () {
    Setting::set('cleanup_prune_rows_after_days', 7);

    $oldFailed = Backup::query()->create([
        'disk' => 'gdbm',
        'status' => BackupStatus::Failed,
        'completed_at' => now()->subDays(30),
    ]);

    (new PruneBackupRows)->handle();

    expect(Backup::query()->find($oldFailed->id))->toBeNull();
});

it('never prunes in-progress rows even if old', function () {
    Setting::set('cleanup_prune_rows_after_days', 1);

    $running = Backup::query()->create([
        'disk' => 'gdbm',
        'status' => BackupStatus::Running,
        'started_at' => now()->subDays(30),
    ]);

    (new PruneBackupRows)->handle();

    expect(Backup::query()->find($running->id))->not->toBeNull();
});

it('uses created_at as a fallback when completed_at is null', function () {
    Setting::set('cleanup_prune_rows_after_days', 7);

    $row = Backup::query()->create([
        'disk' => 'gdbm',
        'status' => BackupStatus::Failed,
        'completed_at' => null,
    ]);

    // Backdate created_at past the threshold.
    Backup::query()->where('id', $row->id)->update(['created_at' => now()->subDays(30)]);

    (new PruneBackupRows)->handle();

    expect(Backup::query()->find($row->id))->toBeNull();
});

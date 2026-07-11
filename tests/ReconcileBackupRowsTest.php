<?php

use Illuminate\Support\Facades\Storage;
use Webteractive\GoogleDriveBackupManager\Enums\BackupStatus;
use Webteractive\GoogleDriveBackupManager\Jobs\ReconcileBackupRows;
use Webteractive\GoogleDriveBackupManager\Models\Backup;

beforeEach(function () {
    // Stand in for the real Google Drive-backed gdbm disk so existence checks
    // hit an in-memory filesystem instead of the network.
    Storage::fake('gdbm');
});

it('deletes a completed row whose file no longer exists on the disk', function () {
    $row = Backup::query()->create([
        'disk' => 'gdbm',
        'status' => BackupStatus::Completed,
        'path' => 'coopit/backup-1.zip',
        'completed_at' => now(),
    ]);

    // File is absent on the (faked) disk — Spatie's retention deleted it.
    (new ReconcileBackupRows)->handle();

    expect(Backup::query()->find($row->id))->toBeNull();
});

it('keeps a completed row whose file still exists', function () {
    Storage::disk('gdbm')->put('coopit/backup-2.zip', 'zip-bytes');

    $row = Backup::query()->create([
        'disk' => 'gdbm',
        'status' => BackupStatus::Completed,
        'path' => 'coopit/backup-2.zip',
        'completed_at' => now(),
    ]);

    (new ReconcileBackupRows)->handle();

    expect(Backup::query()->find($row->id))->not->toBeNull();
});

it('never touches in-progress rows even if they have no file', function () {
    $pending = Backup::query()->create([
        'disk' => 'gdbm',
        'status' => BackupStatus::Pending,
        'path' => null,
    ]);
    $running = Backup::query()->create([
        'disk' => 'gdbm',
        'status' => BackupStatus::Running,
        'path' => 'coopit/in-flight.zip',
        'started_at' => now(),
    ]);

    (new ReconcileBackupRows)->handle();

    expect(Backup::query()->find($pending->id))->not->toBeNull()
        ->and(Backup::query()->find($running->id))->not->toBeNull();
});

it('ignores completed rows that never had a file (null path)', function () {
    $row = Backup::query()->create([
        'disk' => 'gdbm',
        'status' => BackupStatus::Completed,
        'path' => null,
        'completed_at' => now(),
    ]);

    (new ReconcileBackupRows)->handle();

    expect(Backup::query()->find($row->id))->not->toBeNull();
});

it('keeps the row when the existence check throws (transient disk error)', function () {
    // A disk that isn't configured makes Storage::disk() throw — reconciliation
    // must treat that as "unknown", not "gone", so history isn't wiped on a
    // transient Drive outage.
    $row = Backup::query()->create([
        'disk' => 'this-disk-does-not-exist',
        'status' => BackupStatus::Completed,
        'path' => 'coopit/backup-3.zip',
        'completed_at' => now(),
    ]);

    (new ReconcileBackupRows)->handle();

    expect(Backup::query()->find($row->id))->not->toBeNull();
});

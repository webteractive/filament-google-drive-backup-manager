<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Webteractive\GoogleDriveBackupManager\Jobs\DeleteDriveBackupFile;
use Webteractive\GoogleDriveBackupManager\Models\Setting;

beforeEach(function () {
    Storage::fake('gdbm');
});

it('queues by default', function () {
    Queue::fake();

    Setting::forget('jobs_run_sync');

    DeleteDriveBackupFile::for('gdbm', 'backups/x.zip');

    Queue::assertPushed(DeleteDriveBackupFile::class, function (DeleteDriveBackupFile $job): bool {
        return $job->diskName === 'gdbm' && $job->path === 'backups/x.zip';
    });
});

it('runs inline when jobs_run_sync is on', function () {
    Storage::disk('gdbm')->put('backups/now.zip', 'data');

    Setting::set('jobs_run_sync', true);

    DeleteDriveBackupFile::for('gdbm', 'backups/now.zip');

    // Inline execution deletes immediately — the observable proof that
    // dispatch_sync ran instead of queuing.
    expect(Storage::disk('gdbm')->exists('backups/now.zip'))->toBeFalse();
});

it('handle() swallows storage errors so a flaky Drive call can\'t fail the queue worker', function () {
    // Point at a disk that doesn't exist — Storage::disk throws, the job
    // catches via Log::warning and returns cleanly.
    (new DeleteDriveBackupFile('non-existent-disk', 'anything.zip'))->handle();

    // Reaching here without an exception is the assertion.
    expect(true)->toBeTrue();
});

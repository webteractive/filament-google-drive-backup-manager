<?php

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Queue;
use Webteractive\GoogleDriveBackupManager\Jobs\RunBackup;

it('dispatches to the queue', function () {
    Queue::fake();

    dispatch(new RunBackup);

    Queue::assertPushed(RunBackup::class);
});

it('dispatches database-only backup', function () {
    Queue::fake();

    dispatch(new RunBackup(onlyDb: true));

    Queue::assertPushed(RunBackup::class, function (RunBackup $job) {
        return $job->onlyDb === true;
    });
});

it('dispatches full backup by default', function () {
    Queue::fake();

    dispatch(new RunBackup);

    Queue::assertPushed(RunBackup::class, function (RunBackup $job) {
        return $job->onlyDb === false;
    });
});

it('has a 5 minute timeout', function () {
    expect((new RunBackup)->timeout)->toBe(300);
});

it('defaults to full backup', function () {
    expect((new RunBackup)->onlyDb)->toBeFalse();
});

it('accepts onlyDb parameter', function () {
    expect((new RunBackup(onlyDb: true))->onlyDb)->toBeTrue();
});

it('can be dispatched to a specific queue', function () {
    Queue::fake();

    $job = new RunBackup;
    $job->onQueue('backups');
    dispatch($job);

    Queue::assertPushedOn('backups', RunBackup::class);
});

it('implements ShouldQueue', function () {
    expect(new RunBackup)->toBeInstanceOf(ShouldQueue::class);
});

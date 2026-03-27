<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Webteractive\GoogleDriveBackupManager\Models\Backup;

beforeEach(function () {
    Storage::fake('google');
    config()->set('google-drive-backup-manager.disk', 'google');
    Backup::clearCache();
});

it('caches backup listing from google drive', function () {
    config()->set('google-drive-backup-manager.cache_ttl', 60);

    Storage::disk('google')->put('backup-2024-01-01.zip', 'content');

    $backup = new Backup;
    $rows = $backup->getRows();

    expect($rows)->toHaveCount(1);
    expect(Cache::has('google-drive-backup-manager:backups'))->toBeTrue();
});

it('returns cached rows without hitting storage again', function () {
    config()->set('google-drive-backup-manager.cache_ttl', 60);

    Storage::disk('google')->put('backup-2024-01-01.zip', 'content');

    $backup = new Backup;
    $backup->getRows();

    // Add another file — should not appear since results are cached
    Storage::disk('google')->put('backup-2024-01-02.zip', 'content');

    $rows = $backup->getRows();

    expect($rows)->toHaveCount(1);
});

it('skips cache when cache_ttl is zero', function () {
    config()->set('google-drive-backup-manager.cache_ttl', 0);

    Storage::disk('google')->put('backup-2024-01-01.zip', 'content');

    $backup = new Backup;
    $backup->getRows();

    expect(Cache::has('google-drive-backup-manager:backups'))->toBeFalse();
});

it('clears cache via clearCache method', function () {
    config()->set('google-drive-backup-manager.cache_ttl', 60);

    Storage::disk('google')->put('backup-2024-01-01.zip', 'content');

    $backup = new Backup;
    $backup->getRows();

    expect(Cache::has('google-drive-backup-manager:backups'))->toBeTrue();

    Backup::clearCache();

    expect(Cache::has('google-drive-backup-manager:backups'))->toBeFalse();
});

it('returns fresh results after cache is cleared', function () {
    config()->set('google-drive-backup-manager.cache_ttl', 60);

    Storage::disk('google')->put('backup-2024-01-01.zip', 'content');

    $backup = new Backup;
    $backup->getRows();

    // Add another file and clear cache
    Storage::disk('google')->put('backup-2024-01-02.zip', 'content');
    Backup::clearCache();

    $rows = $backup->getRows();

    expect($rows)->toHaveCount(2);
});

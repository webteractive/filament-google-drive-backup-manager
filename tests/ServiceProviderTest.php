<?php

use Illuminate\Support\Facades\Storage;

it('publishes the config file', function () {
    expect(config('google-drive-backup-manager'))->toBeArray()
        ->and(config('google-drive-backup-manager.disk'))->toBe('google')
        ->and(config('google-drive-backup-manager.gate'))->toBe('viewBackups')
        ->and(config('google-drive-backup-manager.navigation_group'))->toBe('System')
        ->and(config('google-drive-backup-manager.google_token_column'))->toBe('google_backup');
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

it('registers the google storage driver', function () {
    config()->set('filesystems.disks.google', [
        'driver' => 'google',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'folder' => '/',
    ]);

    config()->set('google-drive-backup-manager.refresh_token_resolver', null);

    expect(fn () => Storage::disk('google'))
        ->toThrow(RuntimeException::class, 'Google Drive is not configured');
});

it('uses custom refresh token resolver when provided', function () {
    $resolved = false;

    config()->set('google-drive-backup-manager.refresh_token_resolver', function () use (&$resolved) {
        $resolved = true;

        return 'custom-token';
    });

    config()->set('filesystems.disks.google', [
        'driver' => 'google',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'folder' => '/',
    ]);

    try {
        Storage::disk('google');
    } catch (Throwable) {
        // Google API will fail in tests, but the resolver should have been called
    }

    expect($resolved)->toBeTrue();
});

it('falls back to config refresh_token when resolver returns null', function () {
    config()->set('google-drive-backup-manager.refresh_token_resolver', fn () => null);

    config()->set('filesystems.disks.google', [
        'driver' => 'google',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'folder' => '/',
    ]);

    // No refresh_token in disk config and resolver returns null
    expect(fn () => Storage::disk('google'))
        ->toThrow(RuntimeException::class, 'Google Drive is not configured');
});

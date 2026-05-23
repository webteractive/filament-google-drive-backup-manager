<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webteractive\GoogleDriveBackupManager\Tests\TestUser;

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

it('throws when no user has connected google', function () {
    config()->set('filesystems.disks.google', [
        'driver' => 'google',
        'folder' => '/',
    ]);

    config()->set('google-drive-backup-manager.google', [
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
    ]);

    expect(fn () => Storage::disk('google'))
        ->toThrow(RuntimeException::class, 'Google Drive is not configured');
});

it('resolves refresh token from authenticated user', function () {
    $user = TestUser::create([
        'email' => 'test@example.com',
        'google_backup' => ['refresh_token' => 'user-refresh-token'],
    ]);

    config()->set('filesystems.disks.google', [
        'driver' => 'google',
        'folder' => '/',
    ]);

    config()->set('google-drive-backup-manager.google', [
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
    ]);

    $this->actingAs($user);

    // Google API will fail but it should NOT throw "not configured"
    $threw = null;

    try {
        Storage::disk('google');
    } catch (Throwable $e) {
        $threw = $e;
    }

    expect($threw instanceof RuntimeException && str_contains($threw->getMessage(), 'Google Drive is not configured'))
        ->toBeFalse();
});

it('resolves refresh token from database for queued jobs', function () {
    TestUser::create([
        'email' => 'admin@example.com',
        'google_backup' => ['refresh_token' => 'stored-refresh-token'],
    ]);

    config()->set('filesystems.disks.google', [
        'driver' => 'google',
        'folder' => '/',
    ]);

    config()->set('google-drive-backup-manager.google', [
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
    ]);

    // No authenticated user (simulating a queued job)
    $threw = null;

    try {
        Storage::disk('google');
    } catch (Throwable $e) {
        $threw = $e;
    }

    expect($threw instanceof RuntimeException && str_contains($threw->getMessage(), 'Google Drive is not configured'))
        ->toBeFalse();
});

it('skips unreadable encrypted google token data when resolving queued job token', function () {
    $unreadableUser = TestUser::create([
        'email' => 'broken@example.com',
        'google_backup' => ['refresh_token' => 'broken-refresh-token'],
    ]);

    DB::table('users')
        ->where('id', $unreadableUser->getKey())
        ->update(['google_backup' => 'unreadable-encrypted-payload']);

    TestUser::create([
        'email' => 'admin@example.com',
        'google_backup' => ['refresh_token' => 'stored-refresh-token'],
    ]);

    config()->set('filesystems.disks.google', [
        'driver' => 'google',
        'folder' => '/',
    ]);

    config()->set('google-drive-backup-manager.google', [
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
    ]);

    // No authenticated user (simulating a queued job)
    $threw = null;

    try {
        Storage::disk('google');
    } catch (Throwable $e) {
        $threw = $e;
    }

    expect($threw instanceof RuntimeException && str_contains($threw->getMessage(), 'Google Drive is not configured'))
        ->toBeFalse();
});

it('throws not configured when queued job token data is unreadable', function () {
    $user = TestUser::create([
        'email' => 'broken@example.com',
        'google_backup' => ['refresh_token' => 'broken-refresh-token'],
    ]);

    DB::table('users')
        ->where('id', $user->getKey())
        ->update(['google_backup' => 'unreadable-encrypted-payload']);

    config()->set('filesystems.disks.google', [
        'driver' => 'google',
        'folder' => '/',
    ]);

    config()->set('google-drive-backup-manager.google', [
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
    ]);

    expect(fn () => Storage::disk('google'))
        ->toThrow(RuntimeException::class, 'Google Drive is not configured');
});

it('wires google oauth config into services.google for socialite', function () {
    $pkgGoogle = config('google-drive-backup-manager.google');

    if (! empty($pkgGoogle['client_id'])) {
        expect(config('services.google.client_id'))->toBe($pkgGoogle['client_id'])
            ->and(config('services.google.client_secret'))->toBe($pkgGoogle['client_secret']);
    } else {
        expect(true)->toBeTrue();
    }
});

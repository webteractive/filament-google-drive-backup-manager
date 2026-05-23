<?php

use Illuminate\Support\Facades\Cache;
use Laravel\Socialite\Two\User as SocialiteUser;
use Webteractive\GoogleDriveBackupManager\Models\Setting;
use Webteractive\GoogleDriveBackupManager\Services\GoogleDriveConnection;

it('reports disconnected when no oauth row exists', function () {
    expect(app(GoogleDriveConnection::class)->isConnected())->toBeFalse();
});

it('reports connected when a refresh token is stored', function () {
    Setting::set('oauth', ['refresh_token' => 'r'], encrypted: true);

    expect(app(GoogleDriveConnection::class)->isConnected())->toBeTrue();
});

it('ignores empty refresh tokens', function () {
    Setting::set('oauth', ['refresh_token' => ''], encrypted: true);

    expect(app(GoogleDriveConnection::class)->isConnected())->toBeFalse();
});

it('detects unreadable oauth payload', function () {
    Setting::query()->updateOrCreate(['key' => 'oauth'], [
        'value' => 'corrupt-payload',
        'encrypted' => true,
    ]);
    Cache::forget(Setting::CACHE_KEY);

    $connection = app(GoogleDriveConnection::class);

    expect($connection->isConnected())->toBeFalse()
        ->and($connection->hasUnreadableOauth())->toBeTrue();
});

it('returns false for hasUnreadableOauth when oauth row is absent', function () {
    expect(app(GoogleDriveConnection::class)->hasUnreadableOauth())->toBeFalse();
});

it('stores socialite user details encrypted as the full payload', function () {
    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = 'google-1';
    $socialiteUser->token = 'access';
    $socialiteUser->refreshToken = 'refresh';
    $socialiteUser->name = 'Test';
    $socialiteUser->email = 'test@gmail.com';
    $socialiteUser->avatar = 'https://example.com/a.jpg';

    app(GoogleDriveConnection::class)->store($socialiteUser);

    $oauth = Setting::get('oauth');

    expect($oauth)->toMatchArray([
        'id' => 'google-1',
        'token' => 'access',
        'refresh_token' => 'refresh',
        'name' => 'Test',
        'email' => 'test@gmail.com',
        'avatar' => 'https://example.com/a.jpg',
    ]);
});

it('hasCredentials checks both client_id and client_secret', function () {
    $connection = app(GoogleDriveConnection::class);

    expect($connection->hasCredentials())->toBeFalse();

    Setting::set('client_id', 'cid');
    expect($connection->hasCredentials())->toBeFalse();

    Setting::set('client_secret', 'csec', encrypted: true);
    expect($connection->hasCredentials())->toBeTrue();
});

it('disconnect only forgets the oauth key', function () {
    Setting::set('client_id', 'cid');
    Setting::set('client_secret', 'csec', encrypted: true);
    Setting::set('oauth', ['refresh_token' => 'r'], encrypted: true);

    app(GoogleDriveConnection::class)->disconnect();

    expect(Setting::exists('client_id'))->toBeTrue()
        ->and(Setting::exists('client_secret'))->toBeTrue()
        ->and(Setting::exists('oauth'))->toBeFalse();
});

it('withSocialiteConfig swaps and restores services.google', function () {
    config()->set('services.google', ['client_id' => 'host-app-id']);

    Setting::set('client_id', 'gdbm-id');
    Setting::set('client_secret', 'gdbm-secret', encrypted: true);

    $observed = null;

    app(GoogleDriveConnection::class)->withSocialiteConfig(function () use (&$observed) {
        $observed = config('services.google.client_id');
    });

    // Inside the callback the gdbm creds were active.
    expect($observed)->toBe('gdbm-id')
        // After the callback returned, the original host config is restored.
        ->and(config('services.google.client_id'))->toBe('host-app-id');
});

it('withSocialiteConfig restores config even when the callback throws', function () {
    config()->set('services.google', ['client_id' => 'host-app-id']);

    Setting::set('client_id', 'gdbm-id');
    Setting::set('client_secret', 'gdbm-secret', encrypted: true);

    try {
        app(GoogleDriveConnection::class)->withSocialiteConfig(function () {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(config('services.google.client_id'))->toBe('host-app-id');
});

it('setFolder forgets both name and id when given empty', function () {
    Setting::set('folder_name', 'BAK');
    Setting::set('folder_id', '123');

    app(GoogleDriveConnection::class)->setFolder(null);

    expect(Setting::exists('folder_name'))->toBeFalse()
        ->and(Setting::exists('folder_id'))->toBeFalse();
});

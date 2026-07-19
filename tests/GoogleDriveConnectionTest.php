<?php

use Google\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
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

it('isReady requires both credentials and an active oauth connection', function () {
    $connection = app(GoogleDriveConnection::class);

    expect($connection->isReady())->toBeFalse();

    // Credentials alone are not enough — must also be authenticated.
    Setting::set('client_id', 'cid');
    Setting::set('client_secret', 'csec', encrypted: true);
    expect($connection->isReady())->toBeFalse();

    // OAuth alone is not enough either.
    Setting::forget('client_id');
    Setting::forget('client_secret');
    Setting::set('oauth', ['refresh_token' => 'r'], encrypted: true);
    expect($connection->isReady())->toBeFalse();

    // Both together → ready.
    Setting::set('client_id', 'cid');
    Setting::set('client_secret', 'csec', encrypted: true);
    expect($connection->isReady())->toBeTrue();
});

it('surfaces a clear reconnect error when the refresh-token exchange fails', function () {
    Setting::set('client_id', 'cid');
    Setting::set('client_secret', 'csec', encrypted: true);
    Setting::set('oauth', ['refresh_token' => 'revoked'], encrypted: true);

    // Drive a real Google client whose token endpoint returns invalid_grant —
    // the shape Google sends for a revoked/expired refresh token.
    $connection = new class extends GoogleDriveConnection
    {
        protected function newGoogleClient(): Client
        {
            $client = parent::newGoogleClient();
            $client->setHttpClient(new GuzzleHttp\Client([
                'handler' => HandlerStack::create(new MockHandler([
                    new Response(400, [], json_encode(['error' => 'invalid_grant'])),
                ])),
                'http_errors' => false,
            ]));

            return $client;
        }
    };

    // Without the guard this returns a Drive service that 401s cryptically on
    // the first call; with it, the failure is named and points at reconnecting.
    expect(fn () => $connection->makeDriveService())
        ->toThrow(RuntimeException::class, 'reconnect');
});

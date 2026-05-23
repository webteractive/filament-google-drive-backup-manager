<?php

use Illuminate\Support\Facades\Gate;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Webteractive\GoogleDriveBackupManager\Models\Setting;
use Webteractive\GoogleDriveBackupManager\Services\GoogleDriveConnection;
use Webteractive\GoogleDriveBackupManager\Tests\TestUser;

beforeEach(function () {
    Setting::set('client_id', 'test-cid');
    Setting::set('client_secret', 'test-secret', encrypted: true);

    // The viewBackups gate is what the controller enforces — register a
    // simple "any authenticated user" version for these tests.
    Gate::define('viewBackups', fn ($user) => $user !== null);
});

it('redirects unauthenticated users to login', function () {
    $this->get(route('google-drive-backup-manager.google.redirect'))
        ->assertRedirect(route('login'));
});

it('forbids authenticated users when the gate denies', function () {
    Gate::define('viewBackups', fn () => false);

    $user = TestUser::create(['email' => 'denied@example.com']);

    $this->actingAs($user)
        ->get(route('google-drive-backup-manager.google.redirect'))
        ->assertStatus(403);
});

it('forbids authenticated users when no gate is registered (fail-closed)', function () {
    config()->set('google-drive-backup-manager.gate', 'undefined-gate-name');

    $user = TestUser::create(['email' => 'denied2@example.com']);

    $this->actingAs($user)
        ->get(route('google-drive-backup-manager.google.redirect'))
        ->assertStatus(403);
});

it('redirects to Google when the gate allows', function () {
    $user = TestUser::create(['email' => 'allowed@example.com']);

    $response = $this->actingAs($user)
        ->get(route('google-drive-backup-manager.google.redirect'));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('accounts.google.com');
});

it('stores the OAuth payload on a successful callback', function () {
    $user = TestUser::create(['email' => 'allowed@example.com']);

    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = 'google-123';
    $socialiteUser->token = 'access-token';
    $socialiteUser->refreshToken = 'refresh-token';
    $socialiteUser->name = 'Test User';
    $socialiteUser->email = 'test@gmail.com';
    $socialiteUser->avatar = 'https://example.com/avatar.jpg';

    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->andReturn($socialiteUser);
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $this->actingAs($user)
        ->get(route('google-drive-backup-manager.google.callback'))
        ->assertRedirect();

    $connection = app(GoogleDriveConnection::class);

    expect($connection->isConnected())->toBeTrue()
        ->and($connection->getRefreshToken())->toBe('refresh-token');

    $oauth = $connection->getOauthData();

    expect($oauth['id'])->toBe('google-123')
        ->and($oauth['email'])->toBe('test@gmail.com');
});

it('clears only the oauth row on disconnect', function () {
    Setting::set('oauth', ['refresh_token' => 'stored-refresh-token'], encrypted: true);

    $user = TestUser::create(['email' => 'allowed@example.com']);

    $this->actingAs($user)
        ->post(route('google-drive-backup-manager.google.disconnect'))
        ->assertRedirect();

    expect(app(GoogleDriveConnection::class)->isConnected())->toBeFalse()
        ->and(Setting::exists('client_id'))->toBeTrue()
        ->and(Setting::exists('client_secret'))->toBeTrue();
});

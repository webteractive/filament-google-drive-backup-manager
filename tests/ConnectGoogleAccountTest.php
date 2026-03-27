<?php

use Laravel\Socialite\Two\User as SocialiteUser;
use Webteractive\GoogleDriveBackupManager\Actions\ConnectGoogleAccount;
use Webteractive\GoogleDriveBackupManager\Tests\TestUser;

it('stores google user data on the user model', function () {
    $user = TestUser::create([
        'email' => 'test@example.com',
    ]);

    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = 'google-123';
    $socialiteUser->token = 'access-token';
    $socialiteUser->refreshToken = 'refresh-token';
    $socialiteUser->name = 'Test User';
    $socialiteUser->email = 'test@gmail.com';
    $socialiteUser->avatar = 'https://example.com/avatar.jpg';

    $action = new ConnectGoogleAccount;
    $action->handle($user, $socialiteUser);

    $user->refresh();

    expect($user->hasGoogleToken())->toBeTrue();

    $token = $user->getGoogleToken();

    expect($token['id'])->toBe('google-123')
        ->and($token['token'])->toBe('access-token')
        ->and($token['refresh_token'])->toBe('refresh-token')
        ->and($token['name'])->toBe('Test User')
        ->and($token['email'])->toBe('test@gmail.com')
        ->and($token['avatar'])->toBe('https://example.com/avatar.jpg');
});

it('overwrites existing google data on reconnect', function () {
    $user = TestUser::create([
        'email' => 'test@example.com',
        'google_backup' => [
            'id' => 'old-id',
            'refresh_token' => 'old-token',
        ],
    ]);

    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = 'new-id';
    $socialiteUser->token = 'new-access';
    $socialiteUser->refreshToken = 'new-refresh';
    $socialiteUser->name = 'New Name';
    $socialiteUser->email = 'new@gmail.com';
    $socialiteUser->avatar = 'https://example.com/new.jpg';

    $action = new ConnectGoogleAccount;
    $action->handle($user, $socialiteUser);

    $user->refresh();
    $token = $user->getGoogleToken();

    expect($token['id'])->toBe('new-id')
        ->and($token['refresh_token'])->toBe('new-refresh');
});

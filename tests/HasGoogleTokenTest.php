<?php

use Webteractive\GoogleDriveBackupManager\Tests\TestUser;

it('detects when user has a google token', function () {
    $user = TestUser::create([
        'email' => 'test@example.com',
        'google_backup' => [
            'refresh_token' => 'fake-refresh-token',
            'token' => 'fake-token',
        ],
    ]);

    expect($user->hasGoogleToken())->toBeTrue();
});

it('detects when user has no google token', function () {
    $user = TestUser::create([
        'email' => 'test@example.com',
    ]);

    expect($user->hasGoogleToken())->toBeFalse();
});

it('detects when google token column is null', function () {
    $user = TestUser::create([
        'email' => 'test@example.com',
        'google_backup' => null,
    ]);

    expect($user->hasGoogleToken())->toBeFalse();
});

it('detects when refresh token is empty', function () {
    $user = TestUser::create([
        'email' => 'test@example.com',
        'google_backup' => [
            'refresh_token' => '',
            'token' => 'fake-token',
        ],
    ]);

    expect($user->hasGoogleToken())->toBeFalse();
});

it('can disconnect google account', function () {
    $user = TestUser::create([
        'email' => 'test@example.com',
        'google_backup' => [
            'refresh_token' => 'fake-refresh-token',
        ],
    ]);

    expect($user->hasGoogleToken())->toBeTrue();

    $user->disconnectGoogle();
    $user->refresh();

    expect($user->hasGoogleToken())->toBeFalse();
    expect($user->getGoogleToken())->toBeNull();
});

it('can retrieve the full google token data', function () {
    $tokenData = [
        'id' => '123',
        'refresh_token' => 'fake-refresh-token',
        'token' => 'fake-token',
        'name' => 'Test User',
        'email' => 'test@example.com',
    ];

    $user = TestUser::create([
        'email' => 'test@example.com',
        'google_backup' => $tokenData,
    ]);

    $retrieved = $user->getGoogleToken();

    expect($retrieved)->toBeArray()
        ->and($retrieved['refresh_token'])->toBe('fake-refresh-token')
        ->and($retrieved['name'])->toBe('Test User');
});

it('hides the google token column from serialization', function () {
    $user = TestUser::create([
        'email' => 'test@example.com',
        'google_backup' => ['refresh_token' => 'secret'],
    ]);

    $array = $user->toArray();

    expect($array)->not->toHaveKey('google_backup');
});

it('uses the configured column name', function () {
    config()->set('google-drive-backup-manager.google_token_column', 'custom_column');

    $user = new TestUser;

    expect($user->isFillable('custom_column'))->toBeTrue();
});

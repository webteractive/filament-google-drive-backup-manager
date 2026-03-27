<?php

use Webteractive\GoogleDriveBackupManager\Tests\TestUser;

beforeEach(function () {
    config()->set('google-drive-backup-manager.google', [
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'redirect' => 'http://localhost/callback',
    ]);

    // Wire into services.google for Socialite
    config()->set('services.google', config('google-drive-backup-manager.google'));
});

it('redirects to google for oauth', function () {
    $user = TestUser::create(['email' => 'test@example.com']);

    $response = $this->actingAs($user)
        ->get(route('google-drive-backup-manager.google.redirect'));

    $response->assertRedirect();
    expect($response->headers->get('Location'))
        ->toContain('accounts.google.com');
});

it('requires authentication for oauth redirect', function () {
    $this->get(route('google-drive-backup-manager.google.redirect'))
        ->assertRedirect(route('login'));
});

it('requires authentication for oauth callback', function () {
    $this->get(route('google-drive-backup-manager.google.callback'))
        ->assertRedirect(route('login'));
});

it('requires authentication for disconnect', function () {
    $this->post(route('google-drive-backup-manager.google.disconnect'))
        ->assertRedirect(route('login'));
});

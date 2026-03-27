<?php

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Webteractive\GoogleDriveBackupManager\Tests\TestUser;

beforeEach(function () {
    Storage::fake('google');
});

it('requires authentication', function () {
    $path = encrypt('backups/test.zip');

    $this->get(route('backup.download', ['path' => $path]))
        ->assertRedirect(route('login'));
});

it('downloads a backup file', function () {
    Storage::disk('google')->put('backups/test.zip', 'file-contents');

    $user = TestUser::create(['email' => 'test@example.com']);
    $path = encrypt('backups/test.zip');

    $this->actingAs($user)
        ->get(route('backup.download', ['path' => $path]))
        ->assertOk()
        ->assertDownload('test.zip');
});

it('returns 404 for invalid encrypted path', function () {
    $user = TestUser::create(['email' => 'test@example.com']);

    $this->actingAs($user)
        ->get(route('backup.download', ['path' => 'invalid-encrypted-string']))
        ->assertNotFound();
});

it('returns 404 when file does not exist', function () {
    $user = TestUser::create(['email' => 'test@example.com']);
    $path = encrypt('backups/nonexistent.zip');

    $this->actingAs($user)
        ->get(route('backup.download', ['path' => $path]))
        ->assertNotFound();
});

it('enforces the gate when defined', function () {
    Gate::define('viewBackups', fn ($user) => false);

    $user = TestUser::create(['email' => 'test@example.com']);
    Storage::disk('google')->put('backups/test.zip', 'file-contents');
    $path = encrypt('backups/test.zip');

    $this->actingAs($user)
        ->get(route('backup.download', ['path' => $path]))
        ->assertForbidden();
});

it('allows download when gate passes', function () {
    Gate::define('viewBackups', fn ($user) => true);

    $user = TestUser::create(['email' => 'test@example.com']);
    Storage::disk('google')->put('backups/test.zip', 'file-contents');
    $path = encrypt('backups/test.zip');

    $this->actingAs($user)
        ->get(route('backup.download', ['path' => $path]))
        ->assertOk()
        ->assertDownload('test.zip');
});

it('skips gate check when gate is not defined', function () {
    $user = TestUser::create(['email' => 'test@example.com']);
    Storage::disk('google')->put('backups/test.zip', 'file-contents');
    $path = encrypt('backups/test.zip');

    $this->actingAs($user)
        ->get(route('backup.download', ['path' => $path]))
        ->assertOk();
});

<?php

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Webteractive\GoogleDriveBackupManager\Tests\TestUser;

beforeEach(function () {
    // The package now registers the gdbm disk at runtime, but in tests we
    // swap in an in-memory fake so we don't hit Drive.
    Storage::fake('gdbm');

    config()->set('google-drive-backup-manager.disk', 'gdbm');
});

function tokenFor(string $path, ?int $userId, int $expiresAt): string
{
    return encrypt([
        'path' => $path,
        'user_id' => $userId,
        'expires_at' => $expiresAt,
    ]);
}

it('redirects unauthenticated users to login', function () {
    $token = tokenFor('backups/test.zip', 1, now()->addMinutes(5)->timestamp);

    $this->get(route('backup.download', ['path' => $token]))
        ->assertRedirect(route('login'));
});

it('forbids when the gate is not defined (fail-closed)', function () {
    config()->set('google-drive-backup-manager.gate', 'undefined-gate');

    $user = TestUser::create(['email' => 'a@b.test']);
    Storage::disk('gdbm')->put('backups/test.zip', 'data');
    $token = tokenFor('backups/test.zip', $user->getKey(), now()->addMinutes(5)->timestamp);

    $this->actingAs($user)
        ->get(route('backup.download', ['path' => $token]))
        ->assertForbidden();
});

it('forbids when the gate denies', function () {
    Gate::define('viewBackups', fn () => false);

    $user = TestUser::create(['email' => 'a@b.test']);
    Storage::disk('gdbm')->put('backups/test.zip', 'data');
    $token = tokenFor('backups/test.zip', $user->getKey(), now()->addMinutes(5)->timestamp);

    $this->actingAs($user)
        ->get(route('backup.download', ['path' => $token]))
        ->assertForbidden();
});

it('forbids when the token belongs to a different user', function () {
    Gate::define('viewBackups', fn ($user) => $user !== null);

    $owner = TestUser::create(['email' => 'owner@b.test']);
    $attacker = TestUser::create(['email' => 'attacker@b.test']);

    Storage::disk('gdbm')->put('backups/test.zip', 'data');
    $token = tokenFor('backups/test.zip', $owner->getKey(), now()->addMinutes(5)->timestamp);

    $this->actingAs($attacker)
        ->get(route('backup.download', ['path' => $token]))
        ->assertForbidden();
});

it('forbids when the token is expired', function () {
    Gate::define('viewBackups', fn ($user) => $user !== null);

    $user = TestUser::create(['email' => 'a@b.test']);
    Storage::disk('gdbm')->put('backups/test.zip', 'data');
    $token = tokenFor('backups/test.zip', $user->getKey(), now()->subMinute()->timestamp);

    $this->actingAs($user)
        ->get(route('backup.download', ['path' => $token]))
        ->assertForbidden();
});

it('returns 404 for a malformed token', function () {
    Gate::define('viewBackups', fn ($user) => $user !== null);

    $user = TestUser::create(['email' => 'a@b.test']);

    $this->actingAs($user)
        ->get(route('backup.download', ['path' => 'not-a-valid-encryption-blob']))
        ->assertNotFound();
});

it('returns 404 when the file is missing on the disk', function () {
    Gate::define('viewBackups', fn ($user) => $user !== null);

    $user = TestUser::create(['email' => 'a@b.test']);
    $token = tokenFor('backups/missing.zip', $user->getKey(), now()->addMinutes(5)->timestamp);

    $this->actingAs($user)
        ->get(route('backup.download', ['path' => $token]))
        ->assertNotFound();
});

it('downloads when gate passes, token matches user, and not expired', function () {
    Gate::define('viewBackups', fn ($user) => $user !== null);

    $user = TestUser::create(['email' => 'a@b.test']);
    Storage::disk('gdbm')->put('backups/ok.zip', 'data');
    $token = tokenFor('backups/ok.zip', $user->getKey(), now()->addMinutes(5)->timestamp);

    $this->actingAs($user)
        ->get(route('backup.download', ['path' => $token]))
        ->assertOk()
        ->assertDownload('ok.zip');
});

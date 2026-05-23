<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Webteractive\GoogleDriveBackupManager\Models\Setting;

it('round-trips a plain string', function () {
    Setting::set('client_id', 'abc-123');

    expect(Setting::get('client_id'))->toBe('abc-123');
});

it('round-trips an array', function () {
    Setting::set('database_targets', [
        ['connection' => 'mysql', 'databases' => ['app', 'logs']],
    ]);

    expect(Setting::get('database_targets'))
        ->toBe([['connection' => 'mysql', 'databases' => ['app', 'logs']]]);
});

it('encrypts values when encrypted=true', function () {
    Setting::set('oauth', ['refresh_token' => 'secret-token'], encrypted: true);

    $row = Setting::query()->where('key', 'oauth')->first();

    expect($row->encrypted)->toBeTrue()
        ->and($row->value)->not->toContain('secret-token')
        ->and(Crypt::decryptString($row->value))->toContain('secret-token');

    // Decoded via Setting::get round-trips cleanly.
    expect(Setting::get('oauth'))->toBe(['refresh_token' => 'secret-token']);
});

it('returns default when key missing', function () {
    expect(Setting::get('nope', 'fallback'))->toBe('fallback');
});

it('returns default when decryption fails (APP_KEY rotated scenario)', function () {
    Setting::query()->updateOrCreate(['key' => 'oauth'], [
        'value' => 'not-real-ciphertext',
        'encrypted' => true,
    ]);

    expect(Setting::get('oauth', ['fallback' => true]))->toBe(['fallback' => true]);
});

it('forget removes the row', function () {
    Setting::set('client_id', 'abc');

    Setting::forget('client_id');

    expect(Setting::get('client_id'))->toBeNull()
        ->and(Setting::exists('client_id'))->toBeFalse();
});

it('reads through raw DB deletes without caching stale values', function () {
    Setting::set('client_id', 'abc');
    expect(Setting::get('client_id'))->toBe('abc');

    // Mass deletes bypass Eloquent model events. The previous cached
    // implementation could return the stale value here; the no-cache
    // implementation must read through.
    Setting::query()->where('key', 'client_id')->delete();

    expect(Setting::get('client_id'))->toBeNull();
});

it('exists differentiates "never set" from "unreadable"', function () {
    expect(Setting::exists('oauth'))->toBeFalse();

    Setting::set('oauth', ['x' => 1], encrypted: true);

    expect(Setting::exists('oauth'))->toBeTrue();
});

it('tolerates a missing settings table on read', function () {
    Schema::drop(config('google-drive-backup-manager.settings_table'));

    expect(Setting::get('client_id'))->toBeNull();

    // Restore for tests that follow in the same run.
    Schema::create(config('google-drive-backup-manager.settings_table'), function (Blueprint $table): void {
        $table->id();
        $table->string('key')->unique();
        $table->longText('value')->nullable();
        $table->boolean('encrypted')->default(false);
        $table->timestamps();
    });
});

it('throws on JSON-incompatible values (JSON_THROW_ON_ERROR)', function () {
    $resource = fopen('php://memory', 'r');

    expect(fn () => Setting::set('boom', $resource))->toThrow(JsonException::class);

    fclose($resource);
});

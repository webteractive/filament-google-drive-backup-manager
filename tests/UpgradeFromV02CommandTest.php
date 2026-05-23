<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('drops the legacy users column and removes the orphan migrations row', function (): void {
    Schema::table('users', function (Blueprint $table): void {
        $table->text('google_backup')->nullable();
    });

    DB::table('migrations')->insert([
        'migration' => 'add_google_token_column_to_users_table',
        'batch' => 1,
    ]);

    expect(Schema::hasColumn('users', 'google_backup'))->toBeTrue();

    $this->artisan('gdbm:upgrade-from-0.2')
        ->expectsOutputToContain('Dropped users.google_backup column.')
        ->expectsOutputToContain('Removed 1 orphaned migrations row(s).')
        ->assertSuccessful();

    expect(Schema::hasColumn('users', 'google_backup'))->toBeFalse();
    expect(
        DB::table('migrations')
            ->where('migration', 'add_google_token_column_to_users_table')
            ->exists()
    )->toBeFalse();
});

it('is idempotent and safe to run on a clean install', function (): void {
    expect(Schema::hasColumn('users', 'google_backup'))->toBeFalse();

    $this->artisan('gdbm:upgrade-from-0.2')
        ->expectsOutputToContain('users.google_backup column already absent')
        ->expectsOutputToContain('No orphaned migrations row found')
        ->assertSuccessful();
});

it('respects a custom column override', function (): void {
    Schema::table('users', function (Blueprint $table): void {
        $table->text('legacy_drive_token')->nullable();
    });

    $this->artisan('gdbm:upgrade-from-0.2', ['--column' => 'legacy_drive_token'])
        ->expectsOutputToContain('Dropped users.legacy_drive_token column.')
        ->assertSuccessful();

    expect(Schema::hasColumn('users', 'legacy_drive_token'))->toBeFalse();
});

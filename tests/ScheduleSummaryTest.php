<?php

use Webteractive\GoogleDriveBackupManager\GoogleDriveBackupManagerServiceProvider;
use Webteractive\GoogleDriveBackupManager\Models\Setting;
use Webteractive\GoogleDriveBackupManager\Support\ScheduleSummary;

/**
 * A representative `schedule:list --json` payload: two of our tasks (in a
 * non-canonical order) plus an unrelated app command.
 */
function scheduleListJson(): string
{
    return json_encode([
        [
            'expression' => '0 3 * * *',
            'command' => 'gdbm:scheduled-cleanup',
            'description' => 'gdbm:scheduled-cleanup',
            'next_due_date' => '2026-07-11 03:00:00 +00:00',
            'next_due_date_human' => '3 hours from now',
            'timezone' => 'UTC',
        ],
        [
            'expression' => '0 9 * * *',
            'command' => 'php artisan payment-reminders:send',
            'description' => null,
            'next_due_date' => '2026-07-11 09:00:00 +00:00',
            'next_due_date_human' => '9 hours from now',
            'timezone' => 'UTC',
        ],
        [
            'expression' => '0 2 * * *',
            'command' => 'gdbm:scheduled-backup',
            'description' => 'gdbm:scheduled-backup',
            'next_due_date' => '2026-07-11 02:00:00 +00:00',
            'next_due_date_human' => '2 hours from now',
            'timezone' => 'UTC',
        ],
    ]);
}

it('parses only the package tasks, in canonical order', function () {
    $parsed = ScheduleSummary::parse(scheduleListJson());

    expect($parsed)->toHaveCount(2)
        ->and(array_column($parsed, 'key'))->toBe(['backup', 'cleanup'])
        ->and($parsed[0]['label'])->toBe('Backup')
        ->and($parsed[0]['cron'])->toBe('0 2 * * *')
        ->and($parsed[0]['next']->format('Y-m-d H:i'))->toBe('2026-07-11 02:00')
        ->and($parsed[0]['next_human'])->toBe('2 hours from now')
        ->and($parsed[1]['key'])->toBe('cleanup');
});

it('returns an empty list for empty or invalid JSON', function () {
    expect(ScheduleSummary::parse('[]'))->toBe([])
        ->and(ScheduleSummary::parse('not json'))->toBe([])
        ->and(ScheduleSummary::parse(''))->toBe([]);
});

it('carries a null next run when the date is missing', function () {
    $json = json_encode([[
        'expression' => 'bad',
        'command' => 'gdbm:scheduled-monitor',
        'description' => 'gdbm:scheduled-monitor',
        'next_due_date' => null,
        'next_due_date_human' => null,
    ]]);

    $parsed = ScheduleSummary::parse($json);

    expect($parsed)->toHaveCount(1)
        ->and($parsed[0]['key'])->toBe('monitor')
        ->and($parsed[0]['next'])->toBeNull();
});

it('reads live from schedule:list — empty when nothing is enabled', function () {
    foreach (['backup', 'cleanup', 'monitor'] as $task) {
        Setting::forget("schedule_{$task}_enabled");
    }

    expect(ScheduleSummary::enabled())->toBe([])
        ->and(ScheduleSummary::hasEnabled())->toBeFalse();
});

it('reads live from schedule:list — lists an enabled schedule', function () {
    Setting::set('schedule_cleanup_enabled', true);
    Setting::set('schedule_cleanup_cron', '0 3 * * *');

    // Register the schedule the way the framework does at boot.
    app()->getProvider(GoogleDriveBackupManagerServiceProvider::class)->packageBooted();

    $enabled = ScheduleSummary::enabled();

    expect($enabled)->toHaveCount(1)
        ->and($enabled[0]['key'])->toBe('cleanup')
        ->and($enabled[0]['cron'])->toBe('0 3 * * *')
        ->and(ScheduleSummary::hasEnabled())->toBeTrue();
});

<?php

namespace Webteractive\GoogleDriveBackupManager\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Summarises the package's registered schedules by parsing `schedule:list
 * --json`. That command is the source of truth: the service provider only
 * registers a `gdbm:scheduled-*` task when its schedule is enabled and has a
 * cron, and Laravel computes the next-due date for us — so we don't duplicate
 * cron maths and never list a disabled task.
 */
class ScheduleSummary
{
    /**
     * Registered task name => human label. Order defines display order.
     */
    private const TASKS = [
        'gdbm:scheduled-backup' => 'Backup',
        'gdbm:scheduled-cleanup' => 'Cleanup',
        'gdbm:scheduled-monitor' => 'Monitor',
    ];

    /**
     * The package's enabled schedules with their next run.
     *
     * @return array<int, array{key: string, label: string, cron: string, next: ?Carbon, next_human: ?string}>
     */
    public static function enabled(): array
    {
        Artisan::call('schedule:list', ['--json' => true, '--no-ansi' => true]);

        return self::parse(Artisan::output());
    }

    public static function hasEnabled(): bool
    {
        return self::enabled() !== [];
    }

    /**
     * Extract the package's tasks from `schedule:list --json` output. Pure so
     * it can be tested without booting the scheduler.
     *
     * @return array<int, array{key: string, label: string, cron: string, next: ?Carbon, next_human: ?string}>
     */
    public static function parse(string $json): array
    {
        $tasks = json_decode($json, true);

        if (! is_array($tasks)) {
            return [];
        }

        $byName = [];

        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }

            $name = $task['description'] ?? $task['command'] ?? null;

            if (! is_string($name) || ! isset(self::TASKS[$name])) {
                continue;
            }

            $byName[$name] = [
                'key' => str_replace('gdbm:scheduled-', '', $name),
                'label' => self::TASKS[$name],
                'cron' => is_string($task['expression'] ?? null) ? $task['expression'] : '',
                'next' => self::parseDate($task['next_due_date'] ?? null),
                'next_human' => is_string($task['next_due_date_human'] ?? null) ? $task['next_due_date_human'] : null,
            ];
        }

        // Return in the canonical Backup → Cleanup → Monitor order regardless
        // of how schedule:list happened to sort them.
        return array_values(array_filter(array_map(
            fn (string $name) => $byName[$name] ?? null,
            array_keys(self::TASKS),
        )));
    }

    private static function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}

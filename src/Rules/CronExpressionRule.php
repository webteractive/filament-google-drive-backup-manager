<?php

namespace Webteractive\GoogleDriveBackupManager\Rules;

use Closure;
use Cron\CronExpression;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

/**
 * Validates a 5-field cron expression. Uses dragonmantank/cron-expression,
 * which already ships transitively with Laravel.
 */
class CronExpressionRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail('The :attribute must be a cron expression string.');

            return;
        }

        try {
            new CronExpression(trim($value));
        } catch (Throwable $e) {
            $fail('The :attribute is not a valid cron expression.');
        }
    }
}

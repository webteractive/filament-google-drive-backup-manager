<?php

namespace Webteractive\GoogleDriveBackupManager\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a value is an absolute filesystem path that resolves on the
 * server running this validator. Assumes the admin UI and the queue worker
 * share a filesystem (Forge/Vapor/Herd-style setups); on split hosts the
 * existence check may be inaccurate.
 */
class AbsolutePath implements ValidationRule
{
    public function __construct(private bool $mustExist = true) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            // Let `required` (or its absence) decide whether empty is OK.
            return;
        }

        if (! is_string($value)) {
            $fail('The :attribute must be a string path.');

            return;
        }

        $normalized = trim($value);

        if ($normalized === '') {
            return;
        }

        if (! str_starts_with($normalized, '/')) {
            $fail('The :attribute must be an absolute path (start with /).');

            return;
        }

        if (str_contains($normalized, '..')) {
            $fail('The :attribute must not contain "..".');

            return;
        }

        if ($this->mustExist && ! file_exists($normalized)) {
            $fail('The :attribute does not exist on the server.');
        }
    }
}

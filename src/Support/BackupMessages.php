<?php

namespace Webteractive\GoogleDriveBackupManager\Support;

use Illuminate\Support\Str;

/**
 * Shared helpers for working with backup-related text that may contain
 * credentials or oversized payloads. Centralised so the same sanitization
 * runs on every code path that surfaces exception messages — DB persistence
 * (Backup::error_message), webhook fan-out, and any future logging.
 */
class BackupMessages
{
    /**
     * Strip likely-credential substrings and truncate to a sane length.
     * Safe to call repeatedly; idempotent.
     */
    public static function redact(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return $message;
        }

        $redacted = $message;

        // 1) `Authorization: Bearer <token>` / `Authorization: Basic <token>`
        //    — run FIRST so the generic `key=value` pass below doesn't
        //    greedily consume the keyword and leave the actual token behind.
        $redacted = preg_replace(
            '/(authorization)[\s:]+(bearer|basic)\s+[A-Za-z0-9._~+\/=-]+/i',
            '$1: $2 ***',
            $redacted,
        ) ?? $redacted;

        // 2) Bare `Bearer <token>` / `Basic <token>` without the leading
        //    Authorization keyword.
        $redacted = preg_replace(
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i',
            '$1 ***',
            $redacted,
        ) ?? $redacted;

        // 3) `key=value` / `key: value` shape — the most common in PDO and
        //    Spatie failure strings (e.g. `password=hunter2`).
        $redacted = preg_replace(
            '/(password|passwd|pwd|secret|token|api[_-]?key|authorization)\s*[=:]\s*("?[^"\s,;]+"?)/i',
            '$1=***',
            $redacted,
        ) ?? $redacted;

        // 4) Space-separated `key value` (e.g. `--password foo`, `api_key abc123`).
        //    Conservative: only fires for the canonical credential keywords
        //    followed by whitespace and a token-like value.
        $redacted = preg_replace(
            '/(--?)?(password|passwd|pwd|secret|token|api[_-]?key)\s+([A-Za-z0-9._~+\/=-]{4,})/i',
            '$1$2 ***',
            $redacted,
        ) ?? $redacted;

        return Str::limit($redacted, 500);
    }

    /**
     * Strip any URLs from an error message before logging. For webhook
     * failures the URL itself is the secret (Slack/Discord/Google Chat),
     * and Guzzle's RequestException embeds the full request URI.
     */
    public static function redactUrls(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return $message;
        }

        return Str::limit(
            (string) (preg_replace('#https?://\S+#i', '<redacted-url>', $message) ?? $message),
            300,
        );
    }
}

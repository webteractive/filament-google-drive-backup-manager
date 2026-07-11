# Changelog

All notable changes to `webteractive/filament-google-drive-backup-manager` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.4.0] - 2026-07-11

### Added
- **Run Cleanup** header action on the Backups resource — manually triggers the retention cleanup on demand, mirroring the Run Backup action. Respects the `jobs_run_sync` / `queue` settings and shows a confirmation modal since it permanently deletes Drive backups outside the retention window. Only visible once Google Drive is connected.
- **Row reconciliation** (`ReconcileBackupRows`) — after `backup:clean` deletes files from Drive per the retention strategy, any `gdbm_backups` row whose file no longer exists is dropped so the list reflects what is actually stored. In-progress rows are preserved, and a row is kept if its existence check fails (a transient Drive error must not wipe history). Runs as part of every cleanup, scheduled or manual.
- **Schedules overview** header action — a modal listing each enabled backup / cleanup / monitor schedule with its cron and next run, sourced from `schedule:list --json` so it always reflects what is actually registered. Hidden when no schedule is enabled.

### Changed
- The cleanup pipeline (re-resolve config → `backup:clean` → reconcile rows → prune old rows) now lives in a single `RunCleanup` job reused by both the scheduled cleanup and the new manual action, so the two paths can't drift. A non-zero `backup:clean` exit now surfaces as a thrown error (previously swallowed on the scheduled path).
- The Backups list now opens the **Details** modal on row click instead of navigating to the Drive file, and the Details modal renders as read-only infolist entries (two-column, clickable Drive URL) rather than disabled form fields.
- **Triggered by** now shows the user's name (via Filament's `HasName` contract or a `name` attribute, resolved against the host's auth model) instead of the raw user id.

## [0.3.2] - 2026-05-23

### Fixed
- File-target backups silently came out database-only when settings were saved AFTER a long-running queue worker had booted (the common Horizon `--max-time=0 --max-jobs=0` case). The provider previously wired Spatie's `backup.backup.source.files/databases`, `cleanup.default_strategy.*`, and notification map only inside `packageBooted()`, so workers held a stale snapshot. The settings-derived Spatie config is now re-applied on every `RunBackup` job, every scheduled cleanup, and every scheduled monitor — workers no longer need to be restarted after editing the Files / Databases / Cleanup / Notifications tabs. The renamed entry point is `GoogleDriveBackupManagerServiceProvider::applyBackupConfig()` (was `configureBackup()`).

## [0.3.1] - 2026-05-23

### Fixed
- `Setting` model no longer caches the settings array. Previously, any code path that bypassed Eloquent model events (mass delete via `Setting::query()->where(...)->delete()`, raw `DB::table(...)->delete()`, or external SQL) left the cache holding ghost rows, so `Setting::get()` could return stale values long after the row was gone. Reads now go straight to the database, which also removes the latent footgun on Laravel 11+ default `CACHE_STORE=database` installs that hadn't run `php artisan cache:table`.

### Changed
- Non-OAuth settings tabs (Backup, Databases, Files, Schedule, Cleanup, Notifications) now require both client credentials **and** an active OAuth refresh token to become visible — previously they unlocked as soon as the client ID/secret were saved, before the Google handshake completed. Backed by a new `GoogleDriveConnection::isReady()` (= `hasCredentials() && isConnected()`).

## [0.3.0] - 2026-05-23

### Added
- Custom Flysystem v3 Google Drive adapter (`Webteractive\GoogleDriveBackupManager\Filesystem\GoogleDriveAdapter`) — replaces `masbug/flysystem-google-drive-ext` with a purpose-built adapter that does resumable chunked uploads, lazy streaming downloads via `GuzzleHttp\Psr7\StreamWrapper`, and find-or-create folder semantics.
- Filament settings modal split into **Google OAuth**, **Backup**, **Databases**, **Files**, **Schedule**, **Cleanup**, and **Notifications** tabs. Non-OAuth tabs only appear once credentials are saved.
- Persistent settings table (`gdbm_settings`) with flat keys and per-row encryption flag. OAuth payload, client secret, and other sensitive values are stored encrypted via `Crypt::encryptString`.
- Real DB-backed `gdbm_backups` model with `pending → running → completed | failed` lifecycle, captured row metadata (filename, path, size, Drive file ID, triggered-by user, started/completed timestamps, error message).
- `Run Backup` header action triggers a queued `RunBackup` job. Optional `jobs_run_sync` setting bypasses the queue for hosts without a worker.
- Scheduling tab: separate **Backup**, **Cleanup**, **Monitor** schedules with cron presets + custom-cron escape hatch.
- Cleanup tab exposes Spatie's `cleanup.default_strategy` retention values and adds a `cleanup_prune_rows_after_days` setting that wipes settled `gdbm_backups` rows after a configurable window.
- Notifications tab with five channels — Email, Slack, Discord, Google Chat (Cards/markdown), generic JSON webhook. All channels render rich payloads (size, drive URL, duration). Per-event opt-in across `backup_*`, `healthy_*`, `unhealthy_*`, `cleanup_*` events.
- Always-on Filament bell notification on the user who triggered the backup (database notification + broadcast for live-push when Echo is configured).
- Dashboard widget (`BackupStatsWidget`) — last backup status, next scheduled fire, total stored size.
- Backup details modal showing all per-run metadata + redacted error trace.
- Bulk delete via toolbar action; both row + bulk delete queue Drive cleanup asynchronously (`DeleteDriveBackupFile` job).
- Empty-state copy is context-aware: distinguishes no-creds, unreadable OAuth (APP_KEY rotation), connected-but-no-backups, and post-disconnect states.
- Localization scaffolding via `hasTranslations()`; `resources/lang/en/google-drive-backup-manager.php` ships the English strings.
- One-time `php artisan gdbm:upgrade-from-0.2` command for hosts upgrading from v0.2.0 — drops the legacy `users.google_backup` column (if still present) and removes the orphaned `add_google_token_column_to_users_table` row from the `migrations` table. Idempotent; supports `--column=` for non-default column names. See README "Upgrading from 0.2.0".

### Security
- OAuth controller routes (`redirect`, `callback`, `disconnect`) require the `viewBackups` gate via controller middleware. Default-deny when the gate is undefined.
- Download route uses a user-bound, 5-minute TTL token (`encrypt(['path', 'user_id', 'expires_at'])`) verified in `BackupDownloadController`; route constrains `{path}` to base64 + URL-safe characters.
- `BackupResource::canAccess()` defaults to deny when the gate is missing.
- Client secret + OAuth row are never round-tripped through the Livewire form snapshot. Empty client-secret submissions are treated as "no change" instead of "delete".
- `SendBackupNotifications` is the single fan-out point — Spatie's native notification pipeline is disabled to avoid duplicate sends.
- Exception messages get `BackupMessages::redact()` (password / token / api-key / Authorization Bearer / `--password`-style CLI args) applied at the DB write side AND in the webhook payload. Webhook log failures additionally run through `BackupMessages::redactUrls()` so transport errors don't leak webhook URLs (which are the secret for Slack/Discord/Google Chat).
- Concurrent `RunBackup` workers fight for a single `gdbm:current-backup-id` cache key via `Cache::add`; the loser bails before calling `backup:run` to prevent cross-correlated `BackupWasSuccessful` events corrupting sibling rows.
- Database target names are constrained to `[A-Za-z0-9_\-]+` at both the form layer and the runtime config build, preventing PDO DSN injection via the `databases` repeater.
- `GoogleDriveAdapter::escapeQuery` (control-char strip + single-quote/backslash escape) is reused everywhere a Drive query is built.

### Changed
- `backup.backup.destination.disks` now additively includes `gdbm`; `backup.backup.name` defaults to the current `app()->environment()` so the same Drive folder can hold local + staging + production backups without collision.
- Spatie cleanup defaults are overridable from the settings table; `--disable-notifications` removed from package-triggered backup/cleanup/monitor schedules so Spatie's native events feed our own listeners.
- Migrations renamed with `2026_01_01_*` timestamp prefixes for deterministic ordering on fresh installs.
- Dropped `type` column from `gdbm_backups` and removed `BackupType` enum — the resource now distinguishes backups by inspecting the zip contents on Drive, not the row.

### Removed
- `masbug/flysystem-google-drive-ext` dependency (custom adapter replaces it).
- `calebporzio/sushi` dependency (model no longer Sushi-backed).
- Per-user `users.google_backup` column and `HasGoogleToken` trait — Google connection is now a single global token row in `gdbm_settings`.

### Tests
- Pest test suite covers 104 cases / 199 assertions including: `AbsolutePath` + `CronExpressionRule`, `Setting` encrypt/decrypt + cache, `Backup::queueRun`, `RunBackup` exit-code + cache correlation + concurrent-skip, `RecordBackupOutcome` correlation, `PruneBackupRows`, `DeleteDriveBackupFile` sync/queue, all four monitor/cleanup event handlers, `SendBackupNotifications::sanitizeError`, `GoogleDriveConnection` state + `withSocialiteConfig`, `GoogleController` gate enforcement, `BackupDownloadController` token verification.

## [0.2.0] - 2026-05-22

Previous published release. See git history for details prior to this overhaul.

[Unreleased]: https://github.com/webteractive/filament-google-drive-backup-manager/compare/v0.3.1...HEAD
[0.3.1]: https://github.com/webteractive/filament-google-drive-backup-manager/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/webteractive/filament-google-drive-backup-manager/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/webteractive/filament-google-drive-backup-manager/releases/tag/v0.2.0

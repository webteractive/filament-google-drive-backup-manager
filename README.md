# Filament Google Drive Backup Manager

[![Latest Version on Packagist](https://img.shields.io/packagist/v/webteractive/filament-google-drive-backup-manager.svg?style=flat-square)](https://packagist.org/packages/webteractive/filament-google-drive-backup-manager)
[![Total Downloads](https://img.shields.io/packagist/dt/webteractive/filament-google-drive-backup-manager.svg?style=flat-square)](https://packagist.org/packages/webteractive/filament-google-drive-backup-manager)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE.md)

A [Filament](https://filamentphp.com) plugin that ships your [Spatie Laravel Backup](https://github.com/spatie/laravel-backup) zips to Google Drive — with a settings UI, scheduling, multi-channel notifications, retention, and a dashboard widget. Everything is configurable from the admin panel; the host app needs no Spatie or filesystem config beyond enabling the plugin and registering a gate.

## Features

- **Custom Drive adapter** — purpose-built Flysystem v3 adapter (resumable chunked uploads, lazy streaming downloads, find-or-create folders) so you don't depend on third-party Drive Flysystem packages.
- **All settings in one modal** — Google OAuth credentials, Drive folder, database/file targets, schedule, retention, notifications. Stored encrypted-where-it-matters in a `gdbm_settings` table.
- **Tracked backup runs** — every run is a row in `gdbm_backups` with status lifecycle, file size, Drive URL, the user who triggered it, and a sanitized error trace on failure.
- **Multi-channel notifications** — Email, Slack, Discord, Google Chat, generic JSON webhook + an always-on Filament bell. Rich payloads with Drive links + size + duration.
- **Scheduling** — separate backup / cleanup / monitor schedules, each with cron presets or custom expressions.
- **Cleanup** — Spatie's retention strategy is configurable from the UI; an optional pruner trims old `gdbm_backups` rows so the table doesn't grow forever.
- **Dashboard widget** — last backup status, next scheduled fire, total stored size.
- **Bulk delete** with queued Drive cleanup; in-progress backups can't be deleted accidentally.
- **Security-first** — `viewBackups` gate fail-closed everywhere, user-bound + TTL-bound download tokens, sanitized error messages, PDO-DSN injection blocked, OAuth payload encrypted at rest.

## Requirements

- PHP 8.4+
- Laravel 12 or 13
- Filament 4 or 5
- Spatie Laravel Backup 9 or 10

## Installation

```bash
composer require webteractive/filament-google-drive-backup-manager
```

Migrations run automatically via the package service provider. To publish the config file (optional — defaults are sensible):

```bash
php artisan vendor:publish --tag="google-drive-backup-manager-config"
```

## Host-app setup

### 1. Register the gate

The package is gated by `viewBackups` everywhere (resource, controller, download). Define it in `app/Providers/AppServiceProvider.php`:

```php
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('viewBackups', fn ($user) => $user?->is_admin);
    // or whatever predicate fits your app
}
```

> If you skip this step, the resource and routes will return 403 by design — they fail closed when no gate is registered.

### 2. Add the plugin to your Filament panel

In `app/Providers/Filament/AdminPanelProvider.php`:

```php
use Webteractive\GoogleDriveBackupManager\GoogleDriveBackupManagerPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugins([
            GoogleDriveBackupManagerPlugin::make(),
        ]);
}
```

The plugin registers a `Google Drive Backups` resource + a dashboard widget, both gated by `viewBackups`.

### 3. Wire the Laravel scheduler

If you want scheduled backups, make sure your host crontab has the Laravel scheduler entry:

```
* * * * * cd /path/to/your/app && php artisan schedule:run >> /dev/null 2>&1
```

(Forge / Vapor / Laravel Cloud users get this automatically.)

### 4. Create a Google Cloud OAuth client

The package needs an OAuth 2.0 Client ID with the **Google Drive API** scope.

1. Open [Google Cloud Console → APIs & Services → Credentials](https://console.cloud.google.com/apis/credentials).
2. Create an **OAuth client ID** of type **Web application**.
3. Open the `Settings → Google OAuth` tab of the backup resource in your admin panel. Copy the **Redirect URI** shown there.
4. Paste the URI into your OAuth client's **Authorized redirect URIs** list. Save in Google Cloud.
5. Back in your admin panel, paste the **Client ID** and **Client Secret** from Google Cloud into the same form.
6. Click **Save and Authenticate** — you'll be redirected to Google to grant access, then back to the admin panel.

After this, the other tabs (Backup, Databases, Files, Schedule, Cleanup, Notifications) become available.

## Upgrading from 0.2.0

v0.3.0 is a substantial rewrite. The biggest change is architectural: v0.2.0 stored a Google OAuth token per user in `users.google_backup`; v0.3.0 stores a **single global token** in `gdbm_settings`. There is no clean automatic data migration — an admin reconnects Drive once via the Settings tab and the whole panel uses that connection.

### Steps

1. **Update the package**

   ```bash
   composer update webteractive/filament-google-drive-backup-manager
   ```

2. **Run the new migrations**

   ```bash
   php artisan migrate
   ```

   This creates `gdbm_settings` + `gdbm_backups` and drops the legacy `users.google_backup` column (the drop is guarded by `Schema::hasColumn`, so it's safe whether or not v0.2.0 was installed).

3. **Run the one-time upgrade command**

   ```bash
   php artisan gdbm:upgrade-from-0.2
   ```

   This belt-and-suspenders command does two things:
   - Drops `users.google_backup` if any earlier step left it behind.
   - Removes the orphaned `add_google_token_column_to_users_table` row from your `migrations` table (v0.2.0's migration file no longer exists in v0.3.0, so the row points at nothing).

   The command is idempotent — running it on a fresh install or twice in a row is a harmless no-op. Pass `--column=` if your v0.2.0 install used a non-default column name via `config('google-drive-backup-manager.google_token_column')`.

4. **Reconnect Google Drive**

   Open the backup resource in your admin panel → **Settings → Google OAuth**, paste your existing Client ID + Client Secret (or create new ones), and click **Save and Authenticate**. The old per-user tokens are gone — only the admin who completes this flow needs to do it.

5. **Review the new tabs**

   v0.3.0 ships new Settings tabs for **Backup**, **Databases**, **Files**, **Schedule**, **Cleanup**, and **Notifications**. Old behavior (default backup destination = Drive) carries over, but everything else (schedules, retention, notifications) starts blank — set them up to match your previous Spatie config.

### What's not migrated

- **OAuth tokens** — must be re-entered (one admin, once)
- **Spatie config customizations** — if you published `config/backup.php` in v0.2.0, the package now treats that file's presence as a hard opt-out and won't touch any Spatie config. Either re-publish + edit, or delete `config/backup.php` and configure everything via the Settings tabs.

## Configuration tour

| Tab | What lives here |
|---|---|
| **Google OAuth** | Client ID, Client Secret (encrypted), Redirect URI (read-only, derived from your app URL), Save and Authenticate / Disconnect actions |
| **Backup** | Drive folder name (find-or-create), queue name, "run sync" toggle for hosts without a queue worker |
| **Databases** | Per-connection database targets. Empty `Specific databases` = dump the connection's default DB. Names constrained to `[A-Za-z0-9_-]+` for PDO-DSN safety. |
| **Files** | Optional include/exclude paths per target (Repeater). Empty = backup contains DB dumps only. Paths validated via `AbsolutePath` rule. |
| **Schedule** | Backup, Cleanup, Monitor — independent toggles + cron expression (with presets) |
| **Cleanup** | Spatie retention values (keep-all days, daily, weekly, monthly, yearly, max megabytes) + optional `gdbm_backups` row pruning |
| **Notifications** | Email To/From, Slack/Discord/Google Chat webhook URLs, generic JSON webhook URL, plus a checklist of which events trigger sends |

## Operational notes

- **Drive folder name** — when you enter a name we look it up in your Drive root; if absent we create it on save. The resolved Drive folder ID is stored alongside the name so subsequent runs go to the same folder even if the name changes.
- **Backup destination disk** — the package additively appends `gdbm` to `backup.backup.destination.disks` at boot. Package-triggered backups also pass `--only-to-disk=gdbm` so they go to Drive only; any other Spatie backup setups you have keep their original destinations.
- **`APP_KEY` rotation** — OAuth payload is encrypted; if `APP_KEY` rotates without re-encryption, the package detects this and surfaces an "unreadable credentials" banner directing the user to re-authenticate.
- **Concurrent runs** — `RunBackup` jobs claim a cache key before invoking `backup:run`; a losing concurrent worker bails with a meaningful error rather than corrupting another row's correlation. Combined with `withoutOverlapping()` on the schedule, this prevents both scheduled+manual and double-worker scenarios.

## Security model

| Surface | Protection |
|---|---|
| Backup resource | `viewBackups` gate; fail-closed when gate not registered |
| OAuth controller | Same `viewBackups` gate enforced via controller middleware |
| Download endpoint | Encrypted token bundle `{path, user_id, expires_at}` with 5-minute TTL; fail-closed on missing gate |
| Client secret | Encrypted at rest, never round-tripped through the form snapshot (empty form value = "no change") |
| OAuth refresh token | Stored encrypted as the full Socialite payload (id, email, refresh_token, scopes, raw provider response) |
| Error messages | Sanitized via `BackupMessages::redact()` at both DB-write time and notification fan-out (password, token, api-key, Authorization Bearer, `--password` CLI args) |
| Webhook URL log lines | URLs scrubbed via `BackupMessages::redactUrls()` so transport failures don't leak Slack/Discord webhook secrets |
| Database name input | Constrained to `[A-Za-z0-9_-]+` at both form and runtime; blocks PDO DSN injection |

## Localization

UI strings are loaded from `resources/lang/{locale}/google-drive-backup-manager.php`. To customize, publish the language files:

```bash
php artisan vendor:publish --tag="google-drive-backup-manager-translations"
```

Then edit `lang/vendor/google-drive-backup-manager/{locale}/google-drive-backup-manager.php` in your host app.

## Testing

```bash
composer install
./vendor/bin/pest
```

The package ships 100+ Pest tests covering the validation rules, models, jobs, listeners, controllers, and the Drive adapter (mocked).

## Contributing

Issues + PRs welcome at [the GitHub repo](https://github.com/webteractive/filament-google-drive-backup-manager).

Before opening a PR:

```bash
./vendor/bin/pint --dirty   # auto-format
./vendor/bin/pest           # all tests must pass
```

## License

MIT — see [LICENSE.md](LICENSE.md).

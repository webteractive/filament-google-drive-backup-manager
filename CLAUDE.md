# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Is

A Filament plugin (`webteractive/filament-google-drive-backup-manager`) that adds a Google Drive backup management UI to Laravel Filament panels. Users can view, download, and delete backups stored on Google Drive, and trigger full or database-only backups via Spatie Laravel Backup.

## Commands

```bash
composer test            # Run Pest tests
composer test-coverage   # Run tests with coverage
composer analyse         # Run PHPstan (level 5)
composer format          # Run Laravel Pint
vendor/bin/pest tests/ExampleTest.php           # Run a single test file
vendor/bin/pest --filter="test name"            # Run a single test by name
```

## Architecture

**Namespace:** `Webteractive\GoogleDriveBackupManager\`

### Core Components

- **`GoogleDriveBackupManagerServiceProvider`** — Registers the `google` Storage driver using `GoogleDriveAdapter` with Google OAuth. Resolves refresh tokens from config or a custom callable resolver.
- **`GoogleDriveBackupManagerPlugin`** — Filament Plugin that registers `ManageBackups` page with a panel.
- **`Models/Backup`** — Virtual Eloquent model using Sushi (no database table). `getRows()` fetches files from Google Drive via `Storage::disk()`. Returns empty array on failure.
- **`Filament/Pages/ManageBackups`** — Single custom Page implementing `HasTable`. Contains the table (columns, download/delete record actions) and header actions (full backup, database-only backup). Access controlled by a configurable Laravel Gate (default: `viewBackups`). Uses `EmbeddedTable` in `content()` to render the table.

### Key Design Decisions

- **No migrations** — The Backup model uses Sushi to populate from Google Drive at runtime; there is no database table.
- **Storage abstraction** — All Google Drive interaction goes through Laravel's `Storage::disk()` facade, never directly through the Google API.
- **Config-driven** — Navigation group, gate, disk name, download route, and token resolver are all configurable via `config/google-drive-backup-manager.php`.

## Testing

- **Framework:** Pest 4 with Laravel and Architecture plugins
- **Base class:** `tests/TestCase.php` extends Orchestra Testbench, registers the package service provider
- **Architecture tests:** `tests/ArchTest.php` enforces no `dd`, `dump`, or `ray` calls in source code

## Static Analysis & Formatting

- **PHPstan:** Level 5, scans `src/`, `config/`, `database/`. Config in `phpstan.neon.dist` with `phpstan-baseline.neon`.
- **Pint:** Laravel preset. Run `composer format` — also cleans up unused imports.

## Requirements

- PHP 8.4+
- Filament 4.0+ or 5.0+
- Laravel 11+ or 12+

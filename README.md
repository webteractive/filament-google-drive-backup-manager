# Filament Google Drive Backup Manager

[![Latest Version on Packagist](https://img.shields.io/packagist/v/webteractive/filament-google-drive-backup-manager.svg?style=flat-square)](https://packagist.org/packages/webteractive/filament-google-drive-backup-manager)
[![Total Downloads](https://img.shields.io/packagist/dt/webteractive/filament-google-drive-backup-manager.svg?style=flat-square)](https://packagist.org/packages/webteractive/filament-google-drive-backup-manager)

A [Filament](https://filamentphp.com) plugin for managing [Spatie Laravel Backup](https://github.com/spatie/laravel-backup) files stored on Google Drive. View, download, delete, and trigger backups directly from your Filament admin panel.

## Requirements

- PHP 8.4+
- Laravel 11+
- Filament 4.0+
- [Spatie Laravel Backup](https://github.com/spatie/laravel-backup) 9.0+

## Installation

```bash
composer require webteractive/filament-google-drive-backup-manager
```

Publish the config file:

```bash
php artisan vendor:publish --tag="google-drive-backup-manager-config"
```

## Google Drive Setup

Add your Google OAuth credentials to the published config file (`config/google-drive-backup-manager.php`):

```php
'google' => [
    'client_id' => env('GOOGLE_DRIVE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
    'redirect' => env('GOOGLE_DRIVE_REDIRECT_URI'),
],
```

Then add a `google` disk to your `config/filesystems.php`:

```php
'disks' => [
    'google' => [
        'driver' => 'google',
        'folder' => env('GOOGLE_DRIVE_FOLDER', '/'),
    ],
],
```

Add the corresponding values to your `.env` file:

```env
GOOGLE_DRIVE_CLIENT_ID=your-client-id
GOOGLE_DRIVE_CLIENT_SECRET=your-client-secret
GOOGLE_DRIVE_REDIRECT_URI=https://yourapp.com/google-drive-backup-manager-oauth/callback
GOOGLE_DRIVE_FOLDER=/
```

The refresh token is automatically resolved from the user who connected their Google account via the admin panel. No manual token configuration needed.

## Filament Panel Registration

Register the plugin in your Filament panel provider:

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

## Authorization

Access to the backup manager is controlled by a Laravel Gate. By default, the gate name is `viewBackups`. Define it in your `AppServiceProvider`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewBackups', function ($user) {
    return $user->is_admin;
});
```

You can change the gate name in the config:

```php
'gate' => 'yourCustomGate',
```

## Configuration

All settings are in `config/google-drive-backup-manager.php`:

```php
return [
    // Storage disk name (must match a disk in config/filesystems.php)
    'disk' => 'google',

    // Laravel Gate for authorization
    'gate' => 'viewBackups',

    // Filament sidebar navigation group
    'navigation_group' => 'System',

    // Sidebar sort order
    'navigation_sort' => 5,

    // Named route for downloading backups
    'download_route' => 'backup.download',

    // Column on users table for storing Google OAuth tokens
    'google_token_column' => 'google_backup',

    // Queue name for backup jobs (null = default queue)
    'queue' => null,

    // Google OAuth credentials (kept within this package's config)
    'google' => [
        'client_id' => env('GOOGLE_DRIVE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_DRIVE_REDIRECT_URI'),
    ],

    // Route middleware
    'middleware' => ['web', 'auth'],

    // Base path for OAuth routes
    'oauth_base_path' => 'google-drive-backup-manager-oauth',

];
```

## Scheduling Backups

This plugin provides an on-demand UI for triggering backups, but does **not** schedule them automatically. To run backups on a schedule, add the Spatie backup commands to your application's `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('backup:run')->daily()->at('02:00');
Schedule::command('backup:clean')->daily()->at('03:00');
```

Refer to the [Spatie Laravel Backup docs](https://spatie.be/docs/laravel-backup/v9/introduction) for scheduling options, notification setup, cleanup strategies, and other configuration.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Glen Bangkila](https://github.com/webteractive)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

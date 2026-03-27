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

Add a `google` disk to your `config/filesystems.php`:

```php
'disks' => [
    'google' => [
        'driver' => 'google',
        'clientId' => env('GOOGLE_DRIVE_CLIENT_ID'),
        'clientSecret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
        'refreshToken' => env('GOOGLE_DRIVE_REFRESH_TOKEN'),
        'folder' => env('GOOGLE_DRIVE_FOLDER', '/'),
    ],
],
```

Then add the corresponding values to your `.env` file.

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

## Download Route

The plugin requires a named route for downloading backup files. The route receives an encrypted file path. Add it to your routes:

```php
use Illuminate\Support\Facades\Storage;

Route::get('/backup/download/{path}', function (string $path) {
    $decryptedPath = decrypt($path);
    $disk = config('google-drive-backup-manager.disk', 'google');

    return Storage::disk($disk)->download($decryptedPath);
})->name('backup.download')->middleware('auth');
```

## Configuration

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

    // Optional: resolve refresh token dynamically (e.g., per-user)
    'refresh_token_resolver' => null,
];
```

### Dynamic Refresh Token

If you store Google OAuth tokens per-user, provide a resolver:

```php
'refresh_token_resolver' => function () {
    $user = \App\Models\User::where('email', config('app.admin_email'))->first();
    return $user?->google['refresh_token'] ?? null;
},
```

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

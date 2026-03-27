<?php

namespace Webteractive\GoogleDriveBackupManager;

use Google\Client;
use Google\Service\Drive;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use Masbug\Flysystem\GoogleDriveAdapter;
use RuntimeException;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class GoogleDriveBackupManagerServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('google-drive-backup-manager')
            ->hasConfigFile()
            ->hasRoute('web')
            ->hasMigration('add_google_token_column_to_users_table');
    }

    public function packageBooted(): void
    {
        Storage::extend('google', function ($app, $config) {
            $refreshToken = $this->resolveRefreshToken($config);

            if (! $refreshToken) {
                throw new RuntimeException(
                    'Google Drive is not configured. Please connect a Google account via the admin panel or set GOOGLE_DRIVE_REFRESH_TOKEN in your environment.'
                );
            }

            $client = new Client;
            $client->setClientId($config['client_id']);
            $client->setClientSecret($config['client_secret']);
            $client->refreshToken($refreshToken);

            $service = new Drive($client);
            $adapter = new GoogleDriveAdapter($service, $config['folder'] ?? '/');
            $driver = new Filesystem($adapter);

            return new FilesystemAdapter($driver, $adapter);
        });
    }

    protected function resolveRefreshToken(array $config): ?string
    {
        $resolver = config('google-drive-backup-manager.refresh_token_resolver');

        if (is_callable($resolver)) {
            return $resolver() ?: $config['refreshToken'] ?? null;
        }

        return $config['refresh_token'] ?? null;
    }
}

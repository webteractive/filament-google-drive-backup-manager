<?php

namespace Webteractive\GoogleDriveBackupManager;

use Google\Client;
use Google\Service\Drive;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use Masbug\Flysystem\GoogleDriveAdapter;
use RuntimeException;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Webteractive\GoogleDriveBackupManager\Models\Backup;

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
        $this->configureSocialite();

        Event::listen(BackupWasSuccessful::class, fn () => Backup::clearCache());

        Storage::extend('google', $this->createGoogleDriver(...));
    }

    public function createGoogleDriver($app, $config): FilesystemAdapter
    {
        $refreshToken = $this->resolveRefreshToken();

        if (! $refreshToken) {
            throw new RuntimeException(
                'Google Drive is not configured. Please connect a Google account via the admin panel.'
            );
        }

        $google = config('google-drive-backup-manager.google', []);

        $client = new Client;
        $client->setClientId($google['client_id']);
        $client->setClientSecret($google['client_secret']);
        $client->refreshToken($refreshToken);

        $service = new Drive($client);
        $adapter = new GoogleDriveAdapter($service, $config['folder'] ?? '/');
        $driver = new Filesystem($adapter);

        return new FilesystemAdapter($driver, $adapter);
    }

    protected function resolveRefreshToken(): ?string
    {
        $user = Auth::user();

        if ($user && method_exists($user, 'getGoogleToken')) {
            return $user->getGoogleToken()['refresh_token'] ?? null;
        }

        // Fallback for queued jobs (no auth user): find any user with a connected token
        $column = config('google-drive-backup-manager.google_token_column', 'google_backup');
        $userModel = config('auth.providers.users.model');

        $fallbackUser = $userModel::whereNotNull($column)->first();

        return $fallbackUser?->getGoogleToken()['refresh_token'] ?? null;
    }

    protected function configureSocialite(): void
    {
        $google = config('google-drive-backup-manager.google', []);

        if (! empty($google['client_id']) && ! empty($google['client_secret'])) {
            config()->set('services.google', array_merge(
                config('services.google', []),
                $google,
            ));
        }
    }
}

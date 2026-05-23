<?php

namespace Webteractive\GoogleDriveBackupManager\Services;

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Illuminate\Support\Facades\Config;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use RuntimeException;
use Throwable;
use Webteractive\GoogleDriveBackupManager\Filesystem\GoogleDriveAdapter;
use Webteractive\GoogleDriveBackupManager\Models\Setting;

class GoogleDriveConnection
{
    public const KEY_CLIENT_ID = 'client_id';

    public const KEY_CLIENT_SECRET = 'client_secret';

    public const KEY_OAUTH = 'oauth';

    public const KEY_FOLDER_NAME = 'folder_name';

    public const KEY_FOLDER_ID = 'folder_id';

    public function isConnected(): bool
    {
        return $this->getRefreshToken() !== null;
    }

    /**
     * True when an OAuth row exists in the settings table but its encrypted
     * payload can no longer be decoded — typically because APP_KEY changed
     * since it was saved. UI surfaces this as a re-authenticate prompt.
     */
    public function hasUnreadableOauth(): bool
    {
        return Setting::exists(self::KEY_OAUTH) && $this->getOauthData() === null;
    }

    public function getRefreshToken(): ?string
    {
        $oauth = $this->getOauthData();

        $refresh = $oauth['refresh_token'] ?? null;

        return is_string($refresh) && $refresh !== '' ? $refresh : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOauthData(): ?array
    {
        $data = Setting::get(self::KEY_OAUTH);

        return is_array($data) ? $data : null;
    }

    public function store(SocialiteUser $googleUser): void
    {
        Setting::set(self::KEY_OAUTH, [
            'id' => $googleUser->getId(),
            'nickname' => $googleUser->getNickname(),
            'name' => $googleUser->getName(),
            'email' => $googleUser->getEmail(),
            'avatar' => $googleUser->getAvatar(),
            'token' => $googleUser->token,
            'refresh_token' => $googleUser->refreshToken,
            'expires_in' => $googleUser->expiresIn,
            'approved_scopes' => $googleUser->approvedScopes ?? null,
            'raw' => $googleUser->user ?? null,
        ], encrypted: true);

        // If a folder name was saved before auth, resolve it now that we have a Drive client.
        $name = $this->getFolderName();
        if ($name !== null && $this->getFolderId() === null) {
            try {
                Setting::set(self::KEY_FOLDER_ID, $this->resolveFolderId($name));
            } catch (Throwable) {
                // Drive call failed; the user can re-save the folder to retry.
            }
        }
    }

    public function disconnect(): void
    {
        Setting::forget(self::KEY_OAUTH);
    }

    public function hasCredentials(): bool
    {
        return ! empty(Setting::get(self::KEY_CLIENT_ID))
            && ! empty(Setting::get(self::KEY_CLIENT_SECRET));
    }

    /**
     * @return array{client_id: ?string, client_secret: ?string, redirect: string}
     */
    public function getCredentials(): array
    {
        return [
            'client_id' => Setting::get(self::KEY_CLIENT_ID),
            'client_secret' => Setting::get(self::KEY_CLIENT_SECRET),
            'redirect' => $this->getRedirectUri(),
        ];
    }

    public function getRedirectUri(): string
    {
        return route('google-drive-backup-manager.google.callback');
    }

    public function getFolderName(): ?string
    {
        $name = Setting::get(self::KEY_FOLDER_NAME);

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function getFolderId(): ?string
    {
        $id = Setting::get(self::KEY_FOLDER_ID);

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function setFolder(?string $name): void
    {
        $name = $name !== null ? trim($name) : null;

        if ($name === null || $name === '') {
            Setting::forget(self::KEY_FOLDER_NAME);
            Setting::forget(self::KEY_FOLDER_ID);

            return;
        }

        if ($name === $this->getFolderName() && $this->getFolderId() !== null) {
            return;
        }

        Setting::set(self::KEY_FOLDER_NAME, $name);

        if ($this->isConnected()) {
            Setting::set(self::KEY_FOLDER_ID, $this->resolveFolderId($name));
        } else {
            Setting::forget(self::KEY_FOLDER_ID);
        }
    }

    public function resolveFolderId(string $name): string
    {
        $service = $this->makeDriveService();

        $escaped = GoogleDriveAdapter::escapeQuery($name);
        $query = "name = '{$escaped}' and mimeType = 'application/vnd.google-apps.folder' and trashed = false and 'root' in parents";

        $response = $service->files->listFiles([
            'q' => $query,
            'fields' => 'files(id, name)',
            'pageSize' => 1,
            'spaces' => 'drive',
        ]);

        if (count($response->files) > 0) {
            return $response->files[0]->id;
        }

        $created = $service->files->create(
            new DriveFile([
                'name' => $name,
                'mimeType' => 'application/vnd.google-apps.folder',
            ]),
            ['fields' => 'id'],
        );

        return $created->id;
    }

    public function makeDriveService(): Drive
    {
        if (! $this->isConnected() || ! $this->hasCredentials()) {
            throw new RuntimeException('Google Drive is not connected.');
        }

        $credentials = $this->getCredentials();

        $client = new Client;
        $client->setClientId((string) $credentials['client_id']);
        $client->setClientSecret((string) $credentials['client_secret']);
        $client->refreshToken($this->getRefreshToken());

        return new Drive($client);
    }

    /**
     * Temporarily swap `services.google` with our OAuth credentials for the
     * duration of $callback, then restore the host app's original config.
     * Prevents poisoning Socialite for any other "Sign in with Google" flow
     * the host app might run alongside.
     */
    public function withSocialiteConfig(callable $callback): mixed
    {
        if (! $this->hasCredentials()) {
            return $callback();
        }

        $original = config('services.google');

        Config::set('services.google', array_merge(
            (array) ($original ?? []),
            $this->getCredentials(),
        ));

        try {
            return $callback();
        } finally {
            Config::set('services.google', $original);
        }
    }
}

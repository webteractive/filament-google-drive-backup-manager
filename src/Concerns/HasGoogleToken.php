<?php

namespace Webteractive\GoogleDriveBackupManager\Concerns;

use Illuminate\Contracts\Encryption\DecryptException;

trait HasGoogleToken
{
    public function initializeHasGoogleToken(): void
    {
        $column = $this->googleTokenColumn();

        $this->mergeFillable([$column]);
        $this->makeHidden([$column]);
        $this->mergeCasts([$column => 'encrypted:array']);
    }

    public function hasGoogleToken(): bool
    {
        $token = $this->getGoogleToken();

        return ! empty($token['refresh_token']);
    }

    public function disconnectGoogle(): void
    {
        $column = $this->googleTokenColumn();

        $this->update([$column => null]);
    }

    /** @return array<string, mixed>|null */
    public function getGoogleToken(): ?array
    {
        $column = $this->googleTokenColumn();

        try {
            return $this->{$column};
        } catch (DecryptException) {
            return null;
        }
    }

    protected function googleTokenColumn(): string
    {
        return config('google-drive-backup-manager.google_token_column', 'google_backup');
    }
}

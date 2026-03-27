<?php

namespace Webteractive\GoogleDriveBackupManager\Concerns;

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
        $column = $this->googleTokenColumn();

        return ! empty($this->{$column}['refresh_token']);
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

        return $this->{$column};
    }

    protected function googleTokenColumn(): string
    {
        return config('google-drive-backup-manager.google_token_column', 'google_backup');
    }
}

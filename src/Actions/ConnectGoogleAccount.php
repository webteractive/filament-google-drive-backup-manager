<?php

namespace Webteractive\GoogleDriveBackupManager\Actions;

use Illuminate\Database\Eloquent\Model;
use Laravel\Socialite\Two\User as SocialiteUser;

class ConnectGoogleAccount
{
    public function handle(Model $user, SocialiteUser $googleUser): void
    {
        $column = config('google-drive-backup-manager.google_token_column', 'google_backup');

        $user->update([
            $column => [
                'id' => $googleUser->getId(),
                'token' => $googleUser->token,
                'refresh_token' => $googleUser->refreshToken,
                'name' => $googleUser->getName(),
                'email' => $googleUser->getEmail(),
                'avatar' => $googleUser->getAvatar(),
            ],
        ]);
    }
}

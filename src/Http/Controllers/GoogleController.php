<?php

namespace Webteractive\GoogleDriveBackupManager\Http\Controllers;

use Exception;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Webteractive\GoogleDriveBackupManager\Actions\ConnectGoogleAccount;
use Webteractive\GoogleDriveBackupManager\Filament\Resources\Backups\BackupResource;

class GoogleController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')
            ->scopes(['https://www.googleapis.com/auth/drive.file'])
            ->with(['access_type' => 'offline', 'prompt' => 'consent'])
            ->redirect();
    }

    public function callback(ConnectGoogleAccount $connectGoogleAccount): RedirectResponse
    {
        $backupRoute = $this->backupResourceRoute();

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Exception) {
            Notification::make()
                ->title('Google authentication failed. Please try again.')
                ->danger()
                ->send();

            return redirect()->to($backupRoute);
        }

        $user = Auth::user();

        $connectGoogleAccount->handle($user, $googleUser);

        Notification::make()
            ->title('Google account connected successfully.')
            ->success()
            ->send();

        return redirect()->to($backupRoute);
    }

    public function disconnect(): RedirectResponse
    {
        $user = Auth::user();
        $user->disconnectGoogle();

        Notification::make()
            ->title('Google account disconnected.')
            ->success()
            ->send();

        return redirect()->to($this->backupResourceRoute());
    }

    protected function backupResourceRoute(): string
    {
        return BackupResource::getUrl();
    }
}

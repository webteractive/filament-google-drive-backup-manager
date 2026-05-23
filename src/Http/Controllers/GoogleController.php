<?php

namespace Webteractive\GoogleDriveBackupManager\Http\Controllers;

use Exception;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Laravel\Socialite\Facades\Socialite;
use Webteractive\GoogleDriveBackupManager\Filament\Resources\Backups\BackupResource;
use Webteractive\GoogleDriveBackupManager\Services\GoogleDriveConnection;

class GoogleController extends Controller
{
    public function __construct(protected GoogleDriveConnection $connection)
    {
        // Connecting/disconnecting Google must be gated to the same authority
        // that manages backups. Without this, any authenticated host-app user
        // could overwrite the global OAuth token with their own account.
        $this->middleware(function ($request, $next) {
            $gate = config('google-drive-backup-manager.gate', 'viewBackups');

            if (! Gate::has($gate) || ! Gate::allows($gate)) {
                abort(403);
            }

            return $next($request);
        });
    }

    public function redirect(): RedirectResponse
    {
        return $this->connection->withSocialiteConfig(fn (): RedirectResponse => Socialite::driver('google')
            ->scopes(['https://www.googleapis.com/auth/drive.file'])
            ->with(['access_type' => 'offline', 'prompt' => 'consent'])
            ->redirect());
    }

    public function callback(): RedirectResponse
    {
        $backupRoute = $this->backupResourceRoute();

        try {
            $googleUser = $this->connection->withSocialiteConfig(
                fn () => Socialite::driver('google')->user(),
            );
        } catch (Exception) {
            Notification::make()
                ->title(__('google-drive-backup-manager::google-drive-backup-manager.notifications.google_auth_failed'))
                ->danger()
                ->send();

            return redirect()->to($backupRoute);
        }

        $this->connection->store($googleUser);

        Notification::make()
            ->title(__('google-drive-backup-manager::google-drive-backup-manager.notifications.google_connected_title'))
            ->body(__('google-drive-backup-manager::google-drive-backup-manager.notifications.google_connected_body'))
            ->success()
            ->persistent()
            ->send();

        return redirect()->to($backupRoute);
    }

    public function disconnect(): RedirectResponse
    {
        $this->connection->disconnect();

        Notification::make()
            ->title(__('google-drive-backup-manager::google-drive-backup-manager.notifications.google_disconnected'))
            ->success()
            ->send();

        return redirect()->to($this->backupResourceRoute());
    }

    protected function backupResourceRoute(): string
    {
        return BackupResource::getUrl();
    }
}

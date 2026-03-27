<?php

namespace Webteractive\GoogleDriveBackupManager\Filament\Resources\Backups\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Auth;
use Webteractive\GoogleDriveBackupManager\Filament\Resources\Backups\BackupResource;
use Webteractive\GoogleDriveBackupManager\Jobs\RunBackup;

class ManageBackups extends ManageRecords
{
    protected static string $resource = BackupResource::class;

    protected function getHeaderActions(): array
    {
        $googleConfigured = config('services.google.client_id')
            && config('services.google.client_secret')
            && config('services.google.redirect');

        if (! $googleConfigured) {
            return [];
        }

        $isConnected = Auth::user()->hasGoogleToken();

        $actions = [
            $this->connectGoogleAction($isConnected),
        ];

        if ($isConnected) {
            $actions[] = $this->backupAction('backupAll', 'Run Full Backup', 'heroicon-o-arrow-up-tray', 'This will backup the database and all uploaded files to Google Drive.', onlyDb: false);
            $actions[] = $this->backupAction('backupDb', 'Backup Database Only', 'heroicon-o-server-stack', 'This will backup only the database to Google Drive.', onlyDb: true);
        }

        return $actions;
    }

    private function connectGoogleAction(bool $isConnected): Action
    {
        $action = Action::make('connectGoogle')
            ->color('success');

        if ($isConnected) {
            return $action
                ->label('Google Drive Connected')
                ->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->modalHeading('Disconnect Google Account')
                ->modalDescription('This will disconnect your Google account from the backup system. Backups will no longer work until a Google account is reconnected.')
                ->modalSubmitActionLabel('Disconnect')
                ->action(function (): void {
                    Auth::user()->disconnectGoogle();

                    Notification::make()
                        ->success()
                        ->title('Google account disconnected.')
                        ->send();
                });
        }

        return $action
            ->label('Connect to Google Drive')
            ->icon('heroicon-o-link')
            ->outlined()
            ->url(route('google-drive-backup-manager.google.redirect'));
    }

    private function backupAction(string $name, string $label, string $icon, string $description, bool $onlyDb): Action
    {
        $queue = config('google-drive-backup-manager.queue');

        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->requiresConfirmation()
            ->modalDescription($description)
            ->action(function () use ($onlyDb, $queue): void {
                $job = new RunBackup(onlyDb: $onlyDb);

                if ($queue) {
                    $job->onQueue($queue);
                }

                dispatch($job);

                $body = $onlyDb
                    ? 'A database-only backup has been queued and will run shortly.'
                    : 'A full backup has been queued and will run shortly.';

                Notification::make()
                    ->success()
                    ->title('Backup queued')
                    ->body($body)
                    ->send();
            });
    }
}

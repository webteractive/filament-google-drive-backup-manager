<?php

namespace Webteractive\GoogleDriveBackupManager\Listeners;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\BackupDestination\Backup as SpatieBackup;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Throwable;
use Webteractive\GoogleDriveBackupManager\Enums\BackupStatus;
use Webteractive\GoogleDriveBackupManager\Filesystem\GoogleDriveAdapter;
use Webteractive\GoogleDriveBackupManager\Jobs\RunBackup;
use Webteractive\GoogleDriveBackupManager\Models\Backup;
use Webteractive\GoogleDriveBackupManager\Support\BackupMessages;

class RecordBackupOutcome
{
    public function handleSuccess(BackupWasSuccessful $event): void
    {
        $newest = $this->newestBackupFile($event->diskName, $event->backupName);

        $record = $this->claimInProgress() ?? $this->makeFreshRecord($event->diskName);

        $record->fill([
            'disk' => $event->diskName,
            'path' => $newest?->path(),
            'filename' => $newest ? basename($newest->path()) : null,
            'drive_file_id' => $newest ? $this->driveFileId($event->diskName, $newest->path()) : null,
            'size_bytes' => $this->safeSize($newest),
            'status' => BackupStatus::Completed,
            'completed_at' => now(),
        ])->save();
    }

    public function handleFailure(BackupHasFailed $event): void
    {
        $disk = $event->diskName ?? config('google-drive-backup-manager.disk', 'gdbm');

        $record = $this->claimInProgress() ?? $this->makeFreshRecord($disk);

        $record->fill([
            'disk' => $disk,
            'status' => BackupStatus::Failed,
            'error_message' => BackupMessages::redact($event->exception->getMessage()),
            'completed_at' => now(),
        ])->save();
    }

    private function newestBackupFile(string $diskName, string $backupName): ?SpatieBackup
    {
        try {
            return BackupDestination::create($diskName, $backupName)->newestBackup();
        } catch (Throwable) {
            return null;
        }
    }

    private function claimInProgress(): ?Backup
    {
        // RunBackup stashes the in-flight ID before calling Spatie. Correlate
        // by ID so overlapping runs (scheduled + manual + retry) finalize the
        // right row instead of grabbing whichever happens to sort newest.
        $id = Cache::get(RunBackup::CURRENT_BACKUP_CACHE_KEY);

        if ($id) {
            $byId = Backup::query()->find($id);
            if ($byId && in_array($byId->status, [BackupStatus::Pending, BackupStatus::Running], true)) {
                return $byId;
            }
        }

        // Fallback for scheduled/CLI runs that didn't go through RunBackup
        // (no cache key set) — still useful for direct `php artisan backup:run`.
        return Backup::query()->inProgress()->orderByDesc('id')->first();
    }

    private function makeFreshRecord(string $disk): Backup
    {
        return Backup::query()->create([
            'disk' => $disk,
            'status' => BackupStatus::Running,
            'started_at' => now(),
        ]);
    }

    private function driveFileId(string $diskName, string $path): ?string
    {
        try {
            $adapter = Storage::disk($diskName)->getAdapter();

            return $adapter instanceof GoogleDriveAdapter
                ? $adapter->getDriveFileId($path)
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function safeSize(?SpatieBackup $backup): ?int
    {
        if (! $backup) {
            return null;
        }

        try {
            return (int) $backup->sizeInBytes();
        } catch (Throwable) {
            return null;
        }
    }
}

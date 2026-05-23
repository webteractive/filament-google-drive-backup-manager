<?php

namespace Webteractive\GoogleDriveBackupManager\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Webteractive\GoogleDriveBackupManager\Enums\BackupStatus;
use Webteractive\GoogleDriveBackupManager\Jobs\RunBackup;

/**
 * @property int $id
 * @property string $disk
 * @property ?string $path
 * @property ?string $filename
 * @property ?string $drive_file_id
 * @property ?int $triggered_by_user_id
 * @property BackupStatus $status
 * @property ?int $size_bytes
 * @property ?string $error_message
 * @property ?Carbon $started_at
 * @property ?Carbon $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Backup extends Model
{
    protected $fillable = [
        'disk',
        'triggered_by_user_id',
        'path',
        'filename',
        'drive_file_id',
        'status',
        'size_bytes',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'status' => BackupStatus::class,
        'size_bytes' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('google-drive-backup-manager.backups_table', 'gdbm_backups');
    }

    public function getFormattedSizeAttribute(): ?string
    {
        return $this->size_bytes ? Number::fileSize($this->size_bytes) : null;
    }

    public function getDateAttribute(): string
    {
        return ($this->completed_at ?? $this->created_at)->diffForHumans();
    }

    public function getDriveUrlAttribute(): ?string
    {
        return $this->drive_file_id
            ? "https://drive.google.com/file/d/{$this->drive_file_id}/view"
            : null;
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', BackupStatus::Completed->value);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', BackupStatus::Failed->value);
    }

    public function scopeInProgress(Builder $query): Builder
    {
        return $query->whereIn('status', [BackupStatus::Pending->value, BackupStatus::Running->value]);
    }

    /**
     * @param  array<int, string>|null  $databases  Limit DB dump to these connection names. Null = all configured.
     */
    public static function queueRun(bool $onlyDb = false, ?array $databases = null): self
    {
        $record = self::query()->create([
            'disk' => config('google-drive-backup-manager.disk', 'gdbm'),
            'triggered_by_user_id' => auth()->id(),
            'status' => BackupStatus::Pending,
        ]);

        $job = new RunBackup(
            backupId: $record->id,
            onlyDb: $onlyDb,
            databases: $databases,
        );

        if ((bool) Setting::get('jobs_run_sync')) {
            dispatch_sync($job);

            return $record;
        }

        $queue = Setting::get('queue') ?: config('google-drive-backup-manager.queue');

        if ($queue) {
            $job->onQueue($queue);
        }

        dispatch($job);

        return $record;
    }
}

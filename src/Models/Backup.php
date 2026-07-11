<?php

namespace Webteractive\GoogleDriveBackupManager\Models;

use Filament\Models\Contracts\HasName;
use Illuminate\Contracts\Auth\Authenticatable;
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

    /**
     * Human label for who kicked off the run. Resolves the triggering user
     * against the host app's configured auth model — the package can't assume
     * a User class or column, so it prefers Filament's HasName contract, then a
     * `name` attribute, and falls back to the id when the user can't be
     * resolved (deleted account, no configured model). Scheduled/CLI runs have
     * no user.
     */
    public function getTriggeredByLabelAttribute(): string
    {
        if (! $this->triggered_by_user_id) {
            return 'Scheduled / CLI';
        }

        $user = $this->triggeringUser();

        if ($user === null) {
            return "User #{$this->triggered_by_user_id}";
        }

        if ($user instanceof HasName) {
            return $user->getFilamentName();
        }

        $name = $user->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : "User #{$this->triggered_by_user_id}";
    }

    /**
     * The user who triggered this run, resolved against the host's configured
     * auth provider model. Null for scheduled/CLI runs or when unresolvable.
     */
    public function triggeringUser(): ?Authenticatable
    {
        if (! $this->triggered_by_user_id) {
            return null;
        }

        /** @var class-string<Authenticatable>|null $userClass */
        $userClass = config('auth.providers.users.model');

        if (! is_string($userClass) || ! class_exists($userClass)) {
            return null;
        }

        return $userClass::query()->find($this->triggered_by_user_id);
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

<?php

namespace Webteractive\GoogleDriveBackupManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Sushi\Sushi;
use Throwable;

/**
 * @property string $name
 * @property string $path
 * @property int $size
 * @property int $last_modified
 */
class Backup extends Model
{
    use Sushi;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'size' => 'integer',
        'last_modified' => 'integer',
    ];

    public function getFormattedSizeAttribute(): string
    {
        return Number::fileSize($this->size);
    }

    public function getDateAttribute(): string
    {
        return now()->createFromTimestamp($this->last_modified)->diffForHumans();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRows(): array
    {
        $disk = config('google-drive-backup-manager.disk', 'google');

        try {
            $storage = Storage::disk($disk);
            $files = $storage->allFiles();

            return collect($files)->map(fn (string $file) => [
                'name' => basename($file),
                'path' => $file,
                'size' => $storage->size($file),
                'last_modified' => $storage->lastModified($file),
            ])->values()->all();
        } catch (Throwable) {
            return [];
        }
    }
}

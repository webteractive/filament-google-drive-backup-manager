<?php

namespace Webteractive\GoogleDriveBackupManager\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'encrypted',
    ];

    protected $casts = [
        'encrypted' => 'boolean',
    ];

    public function getTable(): string
    {
        return config('google-drive-backup-manager.settings_table', 'gdbm_settings');
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = self::fetchRow($key);

        return $row === null
            ? $default
            : self::decode($row['value'], $row['encrypted'], $default);
    }

    public static function set(string $key, mixed $value, bool $encrypted = false): self
    {
        // JSON_THROW_ON_ERROR surfaces encode failures (recursive structures,
        // resources, malformed UTF-8) instead of silently writing the string
        // "false" to the DB.
        $encoded = json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if ($encrypted) {
            $encoded = Crypt::encryptString($encoded);
        }

        return self::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $encoded, 'encrypted' => $encrypted],
        );
    }

    public static function forget(string $key): void
    {
        self::query()->where('key', $key)->delete();
    }

    public static function exists(string $key): bool
    {
        return self::fetchRow($key) !== null;
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    public static function getMany(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        try {
            $rows = self::query()
                ->whereIn('key', $keys)
                ->get(['key', 'value', 'encrypted'])
                ->keyBy('key');
        } catch (QueryException) {
            return array_fill_keys($keys, null);
        }

        $result = [];

        foreach ($keys as $key) {
            $row = $rows->get($key);
            $result[$key] = $row === null
                ? null
                : self::decode($row->value, (bool) $row->encrypted);
        }

        return $result;
    }

    /**
     * @return array{value: ?string, encrypted: bool}|null
     */
    private static function fetchRow(string $key): ?array
    {
        try {
            $row = self::query()
                ->where('key', $key)
                ->first(['value', 'encrypted']);
        } catch (QueryException) {
            // Table may not exist yet (e.g. during initial migrate).
            return null;
        }

        if ($row === null) {
            return null;
        }

        return [
            'value' => $row->value,
            'encrypted' => (bool) $row->encrypted,
        ];
    }

    private static function decode(?string $raw, bool $encrypted, mixed $default = null): mixed
    {
        if ($raw === null) {
            return $default;
        }

        if ($encrypted) {
            try {
                $raw = Crypt::decryptString($raw);
            } catch (DecryptException $e) {
                Log::warning('gdbm setting decrypt failed', ['exception' => $e->getMessage()]);

                return $default;
            }
        }

        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }
}

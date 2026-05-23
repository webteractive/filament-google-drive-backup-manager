<?php

namespace Webteractive\GoogleDriveBackupManager\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
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

    public const CACHE_KEY = 'gdbm_settings:all';

    public function getTable(): string
    {
        return config('google-drive-backup-manager.settings_table', 'gdbm_settings');
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $rows = self::allCached();

        if (! array_key_exists($key, $rows)) {
            return $default;
        }

        return self::decode($rows[$key]['value'], (bool) $rows[$key]['encrypted'], $default);
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

        // Mass deletes don't fire model events, so bust the cache manually.
        Cache::forget(self::CACHE_KEY);
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::allCached());
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    public static function getMany(array $keys): array
    {
        $rows = self::allCached();

        $out = [];

        foreach ($keys as $key) {
            $out[$key] = array_key_exists($key, $rows)
                ? self::decode($rows[$key]['value'], (bool) $rows[$key]['encrypted'])
                : null;
        }

        return $out;
    }

    /**
     * @return array<string, array{value: ?string, encrypted: bool}>
     */
    private static function allCached(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHour(), function (): array {
            try {
                return self::query()
                    ->get(['key', 'value', 'encrypted'])
                    ->mapWithKeys(fn (self $row) => [$row->key => [
                        'value' => $row->value,
                        'encrypted' => (bool) $row->encrypted,
                    ]])
                    ->all();
            } catch (QueryException) {
                // Table may not exist yet (e.g. during initial migrate).
                return [];
            }
        });
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

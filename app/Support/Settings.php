<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Key/value platform settings backed by the `settings` table.
 *
 * Two cache layers sit in front of the table: a request-level memo of decoded values, and the
 * `database` cache store (1h). That store has no tags, so cache keys carry a version segment
 * that flush() bumps — one write invalidates every entry, negative lookups included.
 *
 * Only ciphertext ever reaches the shared cache: encrypted settings are cached exactly as
 * stored and decrypted per request, so no secret is persisted in plaintext anywhere.
 *
 * Reads degrade to their default when the table is missing — the installer runs before
 * migrations. Writes deliberately do not: a silently lost write would be worse than a fault.
 */
final class Settings
{
    private const TABLE = 'settings';

    private const CACHE_STORE = 'database';

    private const CACHE_PREFIX = 'planvio:settings';

    private const CACHE_VERSION_KEY = 'planvio:settings:version';

    private const CACHE_TTL = 3600;

    /** Cached marker for "this key is known not to exist". */
    private const ABSENT = false;

    /**
     * Decoded values for this request. `null` marks a key resolved as absent.
     *
     * @var array<string, array{value: mixed}|null>
     */
    private array $memo = [];

    private ?int $version = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $entry = $this->entry($key);

        return $entry === null ? $default : $entry['value'];
    }

    /**
     * @throws JsonException when $value cannot be represented as JSON
     */
    public function set(string $key, mixed $value, bool $encrypted = false): void
    {
        $payload = $this->encode($value, $encrypted);
        $now = Carbon::now();

        DB::table(self::TABLE)->upsert(
            [[
                'key' => $key,
                'value' => $payload,
                'is_encrypted' => $encrypted,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['key'],
            ['value', 'is_encrypted', 'updated_at'],
        );

        $this->memo[$key] = ['value' => $value];

        $this->cachePut($this->cacheKey($key), ['raw' => $payload, 'encrypted' => $encrypted]);
        $this->cacheForget($this->allCacheKey());
    }

    public function forget(string $key): void
    {
        try {
            DB::table(self::TABLE)->where('key', $key)->delete();
        } catch (QueryException) {
            // Nothing is stored yet; dropping the caches below is still correct.
        }

        unset($this->memo[$key]);

        $this->cacheForget($this->cacheKey($key));
        $this->cacheForget($this->allCacheKey());
    }

    /**
     * Every setting, decoded. Encrypted values come back in plaintext — never log the result.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $rows = $this->cacheGet($this->allCacheKey());

        if (! is_array($rows)) {
            try {
                $records = DB::table(self::TABLE)->get(['key', 'value', 'is_encrypted']);
            } catch (QueryException) {
                return [];
            }

            $rows = [];

            foreach ($records as $record) {
                $rows[(string) $record->key] = [
                    'raw' => $record->value === null ? null : (string) $record->value,
                    'encrypted' => (bool) $record->is_encrypted,
                ];
            }

            $this->cachePut($this->allCacheKey(), $rows);
        }

        $values = [];

        foreach ($rows as $key => $payload) {
            $value = $this->decode($payload['raw'], $payload['encrypted']);

            $values[$key] = $value;
            $this->memo[$key] = ['value' => $value];
        }

        return $values;
    }

    /**
     * Drop both cache layers. Stored settings are untouched.
     */
    public function flush(): void
    {
        $this->memo = [];

        $next = $this->version() + 1;

        $this->cacheForever(self::CACHE_VERSION_KEY, $next);

        $this->version = $next;
    }

    /**
     * @param array<int|string, mixed> $keys a list of keys, or key => default pairs
     * @return array<string, mixed>
     */
    public function many(array $keys): array
    {
        $values = [];

        foreach ($keys as $key => $default) {
            if (is_int($key)) {
                $values[(string) $default] = $this->get((string) $default);

                continue;
            }

            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    /**
     * @return array{value: mixed}|null
     */
    private function entry(string $key): ?array
    {
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $cached = $this->cacheGet($this->cacheKey($key));

        if ($cached === self::ABSENT) {
            return $this->memo[$key] = null;
        }

        if (is_array($cached) && array_key_exists('raw', $cached)) {
            return $this->memo[$key] = [
                'value' => $this->decode($cached['raw'], (bool) $cached['encrypted']),
            ];
        }

        try {
            $row = DB::table(self::TABLE)->where('key', $key)->first(['value', 'is_encrypted']);
        } catch (QueryException) {
            // The table may still be created later in this same request (installer).
            return null;
        }

        if ($row === null) {
            $this->cachePut($this->cacheKey($key), self::ABSENT);

            return $this->memo[$key] = null;
        }

        $payload = [
            'raw' => $row->value === null ? null : (string) $row->value,
            'encrypted' => (bool) $row->is_encrypted,
        ];

        $this->cachePut($this->cacheKey($key), $payload);

        return $this->memo[$key] = ['value' => $this->decode($payload['raw'], $payload['encrypted'])];
    }

    /**
     * @throws JsonException
     */
    private function encode(mixed $value, bool $encrypted): string
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! $encrypted) {
            return $json;
        }

        return json_encode(Crypt::encryptString($json), JSON_THROW_ON_ERROR);
    }

    private function decode(?string $raw, bool $encrypted): mixed
    {
        if ($raw === null) {
            return null;
        }

        if (! $encrypted) {
            return $this->fromJson($raw);
        }

        $ciphertext = $this->fromJson($raw);

        if (! is_string($ciphertext)) {
            return null;
        }

        try {
            return $this->fromJson(Crypt::decryptString($ciphertext));
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * Hand-edited rows are not guaranteed to be JSON, so undecodable text is returned as-is
     * rather than swallowed.
     */
    private function fromJson(string $json): mixed
    {
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $json;
        }
    }

    private function cacheKey(string $key): string
    {
        return self::CACHE_PREFIX.':v'.$this->version().':key:'.$key;
    }

    private function allCacheKey(): string
    {
        return self::CACHE_PREFIX.':v'.$this->version().':all';
    }

    private function version(): int
    {
        if ($this->version !== null) {
            return $this->version;
        }

        $stored = $this->cacheGet(self::CACHE_VERSION_KEY);

        return $this->version = is_int($stored) && $stored > 0 ? $stored : 1;
    }

    private function cache(): Repository
    {
        return Cache::store(self::CACHE_STORE);
    }

    private function cacheGet(string $key): mixed
    {
        try {
            return $this->cache()->get($key);
        } catch (QueryException) {
            return null;
        }
    }

    private function cachePut(string $key, mixed $value): void
    {
        try {
            $this->cache()->put($key, $value, self::CACHE_TTL);
        } catch (QueryException) {
            // The cache table is not migrated yet; the memo still serves this request.
        }
    }

    private function cacheForever(string $key, mixed $value): void
    {
        try {
            $this->cache()->forever($key, $value);
        } catch (QueryException) {
            // Without a cache table there is nothing stale to invalidate.
        }
    }

    private function cacheForget(string $key): void
    {
        try {
            $this->cache()->forget($key);
        } catch (QueryException) {
            // Nothing cached to drop.
        }
    }
}

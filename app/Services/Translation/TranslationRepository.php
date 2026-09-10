<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Support\Settings;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Translation\Translator;

/**
 * The `translations` table, read and written through the query builder.
 *
 * Deliberately not an Eloquent model. This is resolved by
 * {@see DatabaseTranslationLoader} during boot — earlier than model events, global scopes
 * or the morph map mean anything — and it has to keep working on an installation whose
 * migrations have not run yet.
 *
 * ## Two cache tiers, one version stamp
 *
 * A per-request memo answers the repeated `load()` calls a single page makes, and the
 * `database` cache store holds the rows between requests so a page render is not one query
 * per translation group. That store has no tags, so keys carry a version segment which
 * {@see flush()} bumps: one write invalidates every cached group for every locale, in every
 * process, which is what makes an administrator's edit visible on the next page load
 * without a manual cache clear.
 *
 * ## Degrading rather than throwing
 *
 * `__()` is reached from the installer, from `artisan` before `migrate`, and from the error
 * page that renders when the database is unreachable. A missing table must therefore mean
 * "no overrides", not a fatal error. The first {@see QueryException} latches the repository
 * closed for the rest of the process, so an uninstalled Planvio does not pay for a failed
 * query on every string it renders.
 */
final class TranslationRepository
{
    private const TABLE = 'translations';

    /**
     * The shared store, chosen explicitly rather than through the default binding: the
     * version stamp only invalidates other processes if every process reads the same store,
     * and an installation configured with the `array` driver would otherwise serve stale
     * text until it was restarted. {@see Settings} does the same, for the same
     * reason.
     */
    private const CACHE_STORE = 'database';

    private const CACHE_PREFIX = 'planvio:translations';

    private const CACHE_VERSION_KEY = 'planvio:translations:version';

    private const CACHE_TTL = 3600;

    /**
     * Loaded lines for this request, keyed `locale|group`.
     *
     * @var array<string, array<string, string>>
     */
    private array $memo = [];

    private ?int $version = null;

    /** Set false by the first failed query; nothing is attempted afterwards. */
    private bool $available = true;

    /**
     * Stored overrides for one catalogue, as key => value.
     *
     * Rows with a null value are omitted: `lang:sync` writes those to record that a key
     * exists and is not translated yet, and merging them would blank the shipped text.
     *
     * @return array<string, string>
     */
    public function lines(string $locale, ?string $group): array
    {
        $memoKey = $this->memoKey($locale, $group);

        if (array_key_exists($memoKey, $this->memo)) {
            return $this->memo[$memoKey];
        }

        if (! $this->available) {
            return $this->memo[$memoKey] = [];
        }

        $cached = $this->cacheGet($this->cacheKey($locale, $group));

        if (is_array($cached)) {
            /** @var array<string, string> $cached */
            return $this->memo[$memoKey] = $cached;
        }

        try {
            $rows = DB::table(self::TABLE)
                ->where('locale', $locale)
                ->when($group === null,
                    static fn ($query) => $query->whereNull('group'),
                    static fn ($query) => $query->where('group', $group),
                )
                ->whereNotNull('value')
                ->get(['key', 'value']);
        } catch (QueryException) {
            $this->available = false;

            return $this->memo[$memoKey] = [];
        }

        $lines = [];

        foreach ($rows as $row) {
            $lines[(string) $row->key] = (string) $row->value;
        }

        $this->cachePut($this->cacheKey($locale, $group), $lines);

        return $this->memo[$memoKey] = $lines;
    }

    /**
     * Write one translation, creating the row when it is not there yet.
     *
     * Not an upsert. The unique index cannot enforce itself over a null `group` — MySQL
     * treats NULLs as distinct — so the existing row is resolved first and the JSON
     * namespace stays as single-valued as every other group.
     */
    public function put(
        string $locale,
        ?string $group,
        string $key,
        ?string $value,
        ?int $updatedBy = null,
        bool $reviewed = false,
    ): void {
        $now = Carbon::now();
        $hash = self::hash($group, $key);

        $existing = $this->row($locale, $group, $hash);

        if ($existing === null) {
            DB::table(self::TABLE)->insert([
                'locale' => $locale,
                'group' => $group,
                'key' => $key,
                'key_hash' => $hash,
                'value' => $value,
                'is_reviewed' => $reviewed,
                'updated_by' => $updatedBy,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table(self::TABLE)->where('id', $existing)->update([
                'key' => $key,
                'value' => $value,
                'is_reviewed' => $reviewed,
                'updated_by' => $updatedBy,
                'updated_at' => $now,
            ]);
        }

        $this->flush();
    }

    /**
     * Merge a set of translations into one catalogue.
     *
     * @param array<string, string|null> $values key => translation
     * @return array{written: int, added: int}
     */
    public function putMany(
        string $locale,
        ?string $group,
        array $values,
        ?int $updatedBy = null,
        bool $reviewed = false,
    ): array {
        if ($values === []) {
            return ['written' => 0, 'added' => 0];
        }

        $now = Carbon::now();
        $existing = $this->rowIds($locale, $group);

        $insert = [];
        $written = 0;
        $added = 0;

        foreach ($values as $key => $value) {
            $key = (string) $key;
            $hash = self::hash($group, $key);
            $written++;

            if (isset($existing[$hash])) {
                DB::table(self::TABLE)->where('id', $existing[$hash])->update([
                    'key' => $key,
                    'value' => $value,
                    'is_reviewed' => $reviewed,
                    'updated_by' => $updatedBy,
                    'updated_at' => $now,
                ]);

                continue;
            }

            $added++;

            $insert[$hash] = [
                'locale' => $locale,
                'group' => $group,
                'key' => $key,
                'key_hash' => $hash,
                'value' => $value,
                'is_reviewed' => $reviewed,
                'updated_by' => $updatedBy,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk(array_values($insert), 500) as $chunk) {
            DB::table(self::TABLE)->insert($chunk);
        }

        $this->flush();

        return ['written' => $written, 'added' => $added];
    }

    /**
     * Record keys that have no row in this catalogue yet, untranslated.
     *
     * This is what turns "the code calls `__('Create project')`" into something an
     * administrator can see and fill in. Existing rows are never touched — a sync must not
     * overwrite work somebody has already done.
     *
     * @param iterable<string> $keys
     */
    public function addMissing(string $locale, ?string $group, iterable $keys): int
    {
        $existing = $this->rowIds($locale, $group);
        $now = Carbon::now();
        $insert = [];

        foreach ($keys as $key) {
            $hash = self::hash($group, $key);

            if (isset($existing[$hash]) || isset($insert[$hash])) {
                continue;
            }

            $insert[$hash] = [
                'locale' => $locale,
                'group' => $group,
                'key' => $key,
                'key_hash' => $hash,
                'value' => null,
                'is_reviewed' => false,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk(array_values($insert), 500) as $chunk) {
            DB::table(self::TABLE)->insert($chunk);
        }

        if ($insert !== []) {
            $this->flush();
        }

        return count($insert);
    }

    /**
     * Every stored row for a locale, translated or not, as `group => [key => value]`.
     *
     * The JSON namespace is keyed `*`, matching the way Laravel addresses it internally and
     * the shape `lang:export` writes.
     *
     * @return array<string, array<string, string|null>>
     */
    public function all(string $locale): array
    {
        try {
            $rows = DB::table(self::TABLE)
                ->where('locale', $locale)
                ->orderBy('group')
                ->orderBy('id')
                ->get(['group', 'key', 'value']);
        } catch (QueryException) {
            $this->available = false;

            return [];
        }

        $catalogues = [];

        foreach ($rows as $row) {
            $group = $row->group === null ? '*' : (string) $row->group;

            $catalogues[$group][(string) $row->key] = $row->value === null ? null : (string) $row->value;
        }

        return $catalogues;
    }

    /**
     * Drop both cache tiers and invalidate every other process's copy. Rows are untouched.
     */
    public function flush(): void
    {
        $this->memo = [];

        $next = $this->version() + 1;

        $this->cacheForever(self::CACHE_VERSION_KEY, $next);

        $this->version = $next;

        $this->forgetLoadedLines();
    }

    /**
     * Drop the translator's own copy of everything it has already loaded.
     *
     * Without this, "immediately" would mean "next request". `Translator` keeps the lines it
     * has loaded for the life of the process and never asks the loader twice, so a component
     * that saves a translation and then re-renders — which is precisely what a translation
     * screen does — would show the old text back to the person who just changed it.
     *
     * Guarded on `resolved()` so this cannot pull the translator into existence early: this
     * class is constructed by the loader, and the loader is constructed by the translator.
     */
    private function forgetLoadedLines(): void
    {
        if (! app()->resolved('translator')) {
            return;
        }

        $translator = app('translator');

        if ($translator instanceof Translator) {
            $translator->setLoaded([]);
        }
    }

    /**
     * The stamp cache keys are built from. Consumers that memoise merged results — the
     * loader does — key on it so a write invalidates them too.
     */
    public function version(): int
    {
        if ($this->version !== null) {
            return $this->version;
        }

        $stored = $this->cacheGet(self::CACHE_VERSION_KEY);

        return $this->version = is_int($stored) && $stored > 0 ? $stored : 1;
    }

    /**
     * sha256 of `group|key`.
     *
     * The group is inside the hash so `enums.priority` and a literal string that happens to
     * read the same cannot collide, and an empty prefix marks the JSON namespace.
     */
    public static function hash(?string $group, string $key): string
    {
        return hash('sha256', ($group ?? '').'|'.$key);
    }

    /**
     * @return array<string, int> key_hash => id
     */
    private function rowIds(string $locale, ?string $group): array
    {
        $rows = DB::table(self::TABLE)
            ->where('locale', $locale)
            ->when($group === null,
                static fn ($query) => $query->whereNull('group'),
                static fn ($query) => $query->where('group', $group),
            )
            ->get(['id', 'key_hash']);

        $ids = [];

        foreach ($rows as $row) {
            $ids[(string) $row->key_hash] = (int) $row->id;
        }

        return $ids;
    }

    private function row(string $locale, ?string $group, string $hash): ?int
    {
        $row = DB::table(self::TABLE)
            ->where('locale', $locale)
            ->where('key_hash', $hash)
            ->when($group === null,
                static fn ($query) => $query->whereNull('group'),
                static fn ($query) => $query->where('group', $group),
            )
            ->first(['id']);

        return $row === null ? null : (int) $row->id;
    }

    private function memoKey(string $locale, ?string $group): string
    {
        return $locale.'|'.($group ?? '*');
    }

    private function cacheKey(string $locale, ?string $group): string
    {
        return self::CACHE_PREFIX.':v'.$this->version().':'.$this->memoKey($locale, $group);
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
            // No cache table yet; the request memo still serves this page.
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
}

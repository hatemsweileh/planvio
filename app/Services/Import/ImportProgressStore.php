<?php

declare(strict_types=1);

namespace App\Services\Import;

use Illuminate\Contracts\Cache\Repository;

/**
 * Where a queued import writes how far it has got, and where the screen reads it.
 *
 * The cache rather than the database, because this is the one piece of state that has to be
 * visible from the queue worker and from the web process at the same time and is worthless
 * an hour later. Planvio's default store is the database (`config/cache.php`), so this works
 * on the shared hosting the product targets: no Redis, no shared memory, nothing to install.
 *
 * Tokens are opaque and are generated per run. Nothing in a progress record identifies a
 * workspace, so a leaked token reveals a row count and nothing else — but the screen still
 * only ever asks for a token it created itself.
 */
final readonly class ImportProgressStore
{
    /** Long enough to watch a big import finish and read the report afterwards. */
    private const TTL_SECONDS = 6 * 3600;

    public function __construct(private Repository $cache) {}

    public function get(string $token): ?ImportProgress
    {
        $data = $this->cache->get($this->key($token));

        return is_array($data) ? ImportProgress::fromArray($data) : null;
    }

    public function put(string $token, ImportProgress $progress): void
    {
        $this->cache->put($this->key($token), $progress->toArray(), self::TTL_SECONDS);
    }

    public function forget(string $token): void
    {
        $this->cache->forget($this->key($token));
    }

    private function key(string $token): string
    {
        // The token is hashed into the key so a value that arrived from a form cannot shape
        // the cache key itself — some stores treat separators structurally.
        return 'planvio:import:'.sha1($token);
    }
}

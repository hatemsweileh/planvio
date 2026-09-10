<?php

declare(strict_types=1);

namespace App\Support;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The configured request budgets, and the two places that have to charge one by hand.
 *
 * Most of Planvio's limits are enforced by `throttle:planvio-*`, which resolves a named
 * limiter registered in {@see AppServiceProvider}. Those limiters read their
 * numbers from here, so `config/planvio.php` is the only place a number appears.
 *
 * Two of the expensive operations have no route of their own. Searching from the command
 * palette and starting an AI run from the chat composer are Livewire actions, and every
 * Livewire action in the application arrives on the same `POST /livewire/update` — a route
 * that cannot tell a search from a checkbox. Those two charge their bucket from inside the
 * component instead, through {@see self::attempt()}, which is the same counter the named
 * limiters use and therefore the same allowance whether the request came from the product
 * UI or from the REST API.
 *
 * A refusal is never an exception here. A palette that throws is a broken palette; the
 * caller is told the allowance is spent and says so in its own words.
 */
final class RateLimits
{
    /** Signed-in page requests, everything in the `web` group. */
    public const WEB = 'web';

    /** The same, for a request with nobody signed in. */
    public const GUEST = 'guest';

    /** Command palette and `GET /api/v1/search`. */
    public const SEARCH = 'search';

    /** Starting an agent run, from the composer or from `POST /api/v1/ai/runs`. */
    public const AI_RUNS = 'ai_runs';

    /** CSV downloads from the export controller. */
    public const EXPORTS = 'exports';

    /** The printable project status report. */
    public const REPORTS = 'reports';

    /**
     * Used when the configured value is missing or nonsensical. Every one of these is
     * generous on purpose: a limiter that has lost its configuration should slow an abusive
     * caller down, not lock a working installation out.
     *
     * @var array<string, int>
     */
    private const FALLBACKS = [
        self::WEB => 300,
        self::GUEST => 120,
        self::SEARCH => 60,
        self::AI_RUNS => 10,
        self::EXPORTS => 10,
        self::REPORTS => 20,
    ];

    private const WINDOW_SECONDS = 60;

    /**
     * Requests a minute allowed in this bucket.
     */
    public static function perMinute(string $bucket): int
    {
        $configured = config('planvio.security.rate_limits.'.$bucket);

        $limit = is_numeric($configured) ? (int) $configured : self::FALLBACKS[$bucket] ?? 60;

        // Zero or a negative number in a hand-edited config would refuse every request,
        // which is a lockout rather than a limit.
        return max(1, $limit);
    }

    /**
     * Charge one request against $bucket for $key. False once the allowance is spent.
     *
     * The hit is only recorded when there was room for it, so a caller that keeps trying
     * after a refusal does not extend its own lockout indefinitely.
     */
    public static function attempt(string $bucket, string $key): bool
    {
        $cacheKey = self::cacheKey($bucket, $key);

        if (RateLimiter::tooManyAttempts($cacheKey, self::perMinute($bucket))) {
            return false;
        }

        RateLimiter::hit($cacheKey, self::WINDOW_SECONDS);

        return true;
    }

    /**
     * Seconds until the bucket refills, for the sentence shown to the person who hit it.
     */
    public static function availableIn(string $bucket, string $key): int
    {
        return RateLimiter::availableIn(self::cacheKey($bucket, $key));
    }

    /**
     * Drop everything charged to one bucket and key — used after an operation that turned
     * out not to cost anything, and by tests.
     */
    public static function clear(string $bucket, string $key): void
    {
        RateLimiter::clear(self::cacheKey($bucket, $key));
    }

    private static function cacheKey(string $bucket, string $key): string
    {
        return 'planvio:'.$bucket.':'.$key;
    }
}

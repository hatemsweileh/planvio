<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiDriver;
use Database\Factories\AiProviderFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A configured model endpoint: driver, credentials and per-provider defaults.
 *
 * Platform-level, not tenant-scoped — a workspace points at one through `ai_settings`.
 *
 * `api_key` is encrypted at rest and hidden from array/JSON output. Nothing but the provider
 * driver itself should read the attribute; everything user-facing goes through
 * {@see self::maskedApiKey()} (CLAUDE.md rule 4).
 */
final class AiProvider extends Model
{
    /** @use HasFactory<AiProviderFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'driver',
        'base_url',
        'api_key',
        'model',
        'fallback_model',
        'temperature',
        'max_tokens',
        'timeout_seconds',
        'headers',
        'options',
        'is_active',
        'is_default',
    ];

    /** @var list<string> */
    protected $hidden = [
        'api_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver' => AiDriver::class,
            'api_key' => 'encrypted',
            'temperature' => 'decimal:2',
            'max_tokens' => 'integer',
            'timeout_seconds' => 'integer',
            'headers' => 'array',
            'options' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /* ---------------------------------------------------------------- *
     * Relations
     * ---------------------------------------------------------------- */

    /**
     * @return HasMany<AiSetting, $this>
     */
    public function settings(): HasMany
    {
        return $this->hasMany(AiSetting::class);
    }

    /**
     * @return HasMany<AiRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(AiRun::class);
    }

    /**
     * @return HasMany<AiUsageDaily, $this>
     */
    public function usage(): HasMany
    {
        return $this->hasMany(AiUsageDaily::class);
    }

    /* ---------------------------------------------------------------- *
     * Credentials
     * ---------------------------------------------------------------- */

    /**
     * A display-safe fingerprint of the key, e.g. `sk-...9f2a`, or null when none is stored.
     *
     * Never returns plaintext: only the vendor prefix and the last four characters survive,
     * and a key too short to redact meaningfully is replaced entirely.
     */
    public function maskedApiKey(): ?string
    {
        $key = $this->plaintextApiKey();

        if ($key === null) {
            return null;
        }

        $length = mb_strlen($key);

        if ($length < 12) {
            return str_repeat('•', 8);
        }

        $separator = mb_strpos($key, '-');

        $prefix = $separator !== false && $separator > 0 && $separator <= 6
            ? mb_substr($key, 0, $separator + 1)
            : mb_substr($key, 0, 2);

        return $prefix.'...'.mb_substr($key, -4);
    }

    public function hasApiKey(): bool
    {
        return $this->plaintextApiKey() !== null;
    }

    /**
     * A key encrypted under a rotated APP_KEY can no longer be read; treat it as absent
     * rather than letting a decryption failure escape into a page render.
     */
    private function plaintextApiKey(): ?string
    {
        try {
            $key = $this->api_key;
        } catch (DecryptException) {
            return null;
        }

        if (! is_string($key)) {
            return null;
        }

        $key = trim($key);

        return $key === '' ? null : $key;
    }

    /* ---------------------------------------------------------------- *
     * Scopes and lookups
     * ---------------------------------------------------------------- */

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_active'), true);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeWithDriver(Builder $query, AiDriver $driver): Builder
    {
        return $query->where($this->qualifyColumn('driver'), $driver->value);
    }

    /**
     * The provider to use when nothing more specific is configured.
     *
     * Only an active provider can be the default: a disabled one would fail every call. When
     * the flagged default is inactive the oldest active provider stands in, so turning a
     * provider off degrades instead of taking the AI layer down.
     */
    public static function defaultProvider(): ?self
    {
        return self::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }
}

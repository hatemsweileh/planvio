<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\WorkspaceScope;
use Database\Factories\AiUsageDailyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One day's usage bucket, keyed by date + workspace + user + provider + model.
 *
 * Null `workspace_id` / `user_id` mark platform-wide buckets, so the model does not register
 * {@see WorkspaceScope} — the ambient scope would hide them from `/admin`.
 * Callers narrow explicitly with {@see self::scopeForWorkspace()}.
 */
final class AiUsageDaily extends Model
{
    /** @use HasFactory<AiUsageDailyFactory> */
    use HasFactory;

    /** The table is already singular-by-date; Eloquent would otherwise look for `ai_usage_dailies`. */
    protected $table = 'ai_usage_daily';

    /** @var list<string> */
    protected $fillable = [
        'date',
        'workspace_id',
        'user_id',
        'ai_provider_id',
        'model',
        'runs',
        'tool_calls',
        'tokens_in',
        'tokens_out',
        'errors',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'runs' => 'integer',
            'tool_calls' => 'integer',
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
            'errors' => 'integer',
        ];
    }

    /* ---------------------------------------------------------------- *
     * Relations
     * ---------------------------------------------------------------- */

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<AiProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    /* ---------------------------------------------------------------- *
     * Derived reads
     * ---------------------------------------------------------------- */

    public function totalTokens(): int
    {
        return (int) $this->tokens_in + (int) $this->tokens_out;
    }

    /* ---------------------------------------------------------------- *
     * Scopes
     * ---------------------------------------------------------------- */

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForDate(Builder $query, Carbon|string $date): Builder
    {
        return $query->whereDate(
            $this->qualifyColumn('date'),
            $date instanceof Carbon ? $date->toDateString() : $date,
        );
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeBetweenDates(Builder $query, Carbon|string $from, Carbon|string $to): Builder
    {
        return $query->whereBetween($this->qualifyColumn('date'), [
            $from instanceof Carbon ? $from->toDateString() : $from,
            $to instanceof Carbon ? $to->toDateString() : $to,
        ]);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForWorkspace(Builder $query, Workspace|int $workspace): Builder
    {
        return $query->where(
            $this->qualifyColumn('workspace_id'),
            $workspace instanceof Workspace ? $workspace->getKey() : $workspace,
        );
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where(
            $this->qualifyColumn('user_id'),
            $user instanceof User ? $user->getKey() : $user,
        );
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForProvider(Builder $query, AiProvider|int $provider): Builder
    {
        return $query->where(
            $this->qualifyColumn('ai_provider_id'),
            $provider instanceof AiProvider ? $provider->getKey() : $provider,
        );
    }

    /**
     * Buckets that belong to no tenant.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopePlatform(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('workspace_id'));
    }

    /**
     * Newest day first.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query
            ->orderByDesc($this->qualifyColumn('date'))
            ->orderByDesc($this->qualifyColumn('id'));
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\RecentItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * The last time a user opened a record, one row per user and record.
 */
final class RecentItem extends Model
{
    /** @use HasFactory<RecentItemFactory> */
    use BelongsToWorkspace;

    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'workspace_id',
        'viewable_id',
        'viewable_type',
        'viewed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
        ];
    }

    /* ---------------------------------------------------------------- *
     * Relations
     * ---------------------------------------------------------------- */

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function viewable(): MorphTo
    {
        return $this->morphTo();
    }

    /* ---------------------------------------------------------------- *
     * Scopes
     * ---------------------------------------------------------------- */

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
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where($this->qualifyColumn('viewable_type'), $type);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeFor(Builder $query, Model $viewable): Builder
    {
        return $query
            ->where($this->qualifyColumn('viewable_type'), $viewable->getMorphClass())
            ->where($this->qualifyColumn('viewable_id'), $viewable->getKey());
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeSince(Builder $query, Carbon $since): Builder
    {
        return $query->where($this->qualifyColumn('viewed_at'), '>=', $since);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query
            ->orderByDesc($this->qualifyColumn('viewed_at'))
            ->orderByDesc($this->qualifyColumn('id'));
    }
}

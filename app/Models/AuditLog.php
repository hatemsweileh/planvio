<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\WorkspaceScope;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A security or configuration event — sign-ins, permission changes, settings writes.
 *
 * Distinct from `activities`, which records domain changes for the product timeline. Both
 * `user_id` and `workspace_id` are nullable and null out when their subject is deleted, so
 * the trail survives the account or tenant it describes. That is also why the model does not
 * register {@see WorkspaceScope}: platform-level entries carry no workspace
 * and must stay readable in `/admin`. Callers narrow explicitly with
 * {@see self::scopeForWorkspace()}.
 *
 * `properties` must never carry a secret (CLAUDE.md rule 4).
 */
final class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'workspace_id',
        'event',
        'description',
        'ip',
        'user_agent',
        'properties',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
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
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /* ---------------------------------------------------------------- *
     * Scopes
     * ---------------------------------------------------------------- */

    /**
     * @param Builder<static> $query
     * @param string|array<int, string> $event
     * @return Builder<static>
     */
    public function scopeForEvent(Builder $query, string|array $event): Builder
    {
        return is_array($event)
            ? $query->whereIn($this->qualifyColumn('event'), $event)
            : $query->where($this->qualifyColumn('event'), $event);
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
    public function scopeForWorkspace(Builder $query, Workspace|int $workspace): Builder
    {
        return $query->where(
            $this->qualifyColumn('workspace_id'),
            $workspace instanceof Workspace ? $workspace->getKey() : $workspace,
        );
    }

    /**
     * Entries that belong to no tenant — installer, platform admin and system events.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopePlatform(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('workspace_id'));
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeSince(Builder $query, Carbon $since): Builder
    {
        return $query->where($this->qualifyColumn('created_at'), '>=', $since);
    }

    /**
     * Newest first, along the `(event, created_at)` / `(user_id, created_at)` indexes.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query
            ->orderByDesc($this->qualifyColumn('created_at'))
            ->orderByDesc($this->qualifyColumn('id'));
    }
}

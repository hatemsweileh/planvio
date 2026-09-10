<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkspaceRole;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\WorkspaceMemberFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The authoritative record of who may act inside a workspace and with what role.
 */
final class WorkspaceMember extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<WorkspaceMemberFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'user_id',
        'role',
        'title',
        'joined_at',
        'last_active_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'joined_at' => 'datetime',
            'last_active_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
     * @param WorkspaceRole|list<WorkspaceRole> $role
     * @return Builder<static>
     */
    public function scopeWithRole(Builder $query, WorkspaceRole|array $role): Builder
    {
        $roles = is_array($role) ? $role : [$role];

        return $query->whereIn(
            $this->qualifyColumn('role'),
            array_map(static fn (WorkspaceRole $case): string => $case->value, $roles),
        );
    }

    /**
     * Everyone who can act on workspace content — that is, everybody but guests.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeNotGuest(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('role'), '!=', WorkspaceRole::Guest->value);
    }
}

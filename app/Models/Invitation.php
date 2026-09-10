<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkspaceRole;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pending offer of workspace membership. A `project_id` narrows the invitation to a
 * single project, which is how guests are brought in.
 */
final class Invitation extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<InvitationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'email',
        'role',
        'token',
        'invited_by',
        'project_id',
        'expires_at',
        'accepted_at',
    ];

    /**
     * The token is a bearer credential: whoever holds it can join the workspace, so it
     * never travels in an API response or a log line.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------------ *
     * Relationships
     * ------------------------------------------------------------------ */

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /* ------------------------------------------------------------------ *
     * Accessors
     * ------------------------------------------------------------------ */

    /**
     * Invitations are matched to a signing-in user by address, so the address is stored in
     * one canonical form rather than compared case-insensitively at every call site.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::set(fn (string $value): string => mb_strtolower(trim($value)));
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function isAccepted(): Attribute
    {
        return Attribute::get(fn (): bool => $this->accepted_at !== null);
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function isExpired(): Attribute
    {
        return Attribute::get(fn (): bool => $this->expires_at !== null && $this->expires_at->isPast());
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function isPending(): Attribute
    {
        return Attribute::get(fn (): bool => ! $this->is_accepted && ! $this->is_expired);
    }

    /* ------------------------------------------------------------------ *
     * Scopes
     * ------------------------------------------------------------------ */

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query
            ->whereNull($this->qualifyColumn('accepted_at'))
            ->where(fn (Builder $sub): Builder => $sub
                ->whereNull($this->qualifyColumn('expires_at'))
                ->orWhere($this->qualifyColumn('expires_at'), '>', now()));
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeAccepted(Builder $query): Builder
    {
        return $query->whereNotNull($this->qualifyColumn('accepted_at'));
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query
            ->whereNull($this->qualifyColumn('accepted_at'))
            ->whereNotNull($this->qualifyColumn('expires_at'))
            ->where($this->qualifyColumn('expires_at'), '<=', now());
    }

    /**
     * Matches the index(email) / index(workspace_id, email) pair.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForEmail(Builder $query, string $email): Builder
    {
        return $query->where($this->qualifyColumn('email'), mb_strtolower(trim($email)));
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForProject(Builder $query, Project|int $project): Builder
    {
        return $query->where(
            $this->qualifyColumn('project_id'),
            $project instanceof Project ? $project->getKey() : $project,
        );
    }
}

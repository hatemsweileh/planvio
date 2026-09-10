<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkspaceRole;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The tenant root. Everything else in the product hangs off this record, so it is the one
 * model that deliberately does not use BelongsToWorkspace.
 */
final class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'logo_path',
        'accent_color',
        'timezone',
        'locale',
        'currency',
        'date_format',
        'week_starts_on',
        'owner_id',
        'settings',
        'is_suspended',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'week_starts_on' => 'integer',
            'settings' => 'array',
            'is_suspended' => 'boolean',
        ];
    }

    /* ------------------------------------------------------------------ *
     * Relationships
     * ------------------------------------------------------------------ */

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')
            ->withPivot('role', 'title', 'joined_at', 'last_active_at')
            ->withTimestamps();
    }

    /**
     * @return HasMany<WorkspaceMember, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    /**
     * @return HasMany<Team, $this>
     */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Every task in the workspace, across all of its projects.
     *
     * Tasks are normally reached through their project. This relation exists because tasks
     * are also addressed directly — `/w/{workspace}/tasks/{task}` — and Laravel's scoped
     * route binding resolves a child through the relation named after its parameter. Going
     * through it is what makes a task id from another tenant a 404 rather than a hit that
     * only a policy catches.
     *
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return HasMany<Tag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /**
     * @return HasMany<ProjectStatus, $this>
     */
    public function projectStatuses(): HasMany
    {
        return $this->hasMany(ProjectStatus::class);
    }

    /**
     * @return HasMany<Invitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /**
     * @return HasOne<AiSetting, $this>
     */
    public function aiSetting(): HasOne
    {
        return $this->hasOne(AiSetting::class);
    }

    /**
     * @return HasMany<AiPolicy, $this>
     */
    public function aiPolicies(): HasMany
    {
        return $this->hasMany(AiPolicy::class);
    }

    /**
     * @return HasMany<Webhook, $this>
     */
    public function webhooks(): HasMany
    {
        return $this->hasMany(Webhook::class);
    }

    /**
     * @return HasMany<CustomField, $this>
     */
    public function customFields(): HasMany
    {
        return $this->hasMany(CustomField::class);
    }

    /**
     * @return HasMany<SavedView, $this>
     */
    public function savedViews(): HasMany
    {
        return $this->hasMany(SavedView::class);
    }

    /* ------------------------------------------------------------------ *
     * Scopes
     * ------------------------------------------------------------------ */

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_suspended'), false);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeSuspended(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_suspended'), true);
    }

    /**
     * Workspaces the user belongs to. A single join, so it stays usable as a list query.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForMember(Builder $query, User|int $user): Builder
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        return $query->whereExists(
            fn (QueryBuilder $sub): QueryBuilder => $sub->selectRaw('1')
                ->from('workspace_members')
                ->whereColumn('workspace_members.workspace_id', $this->qualifyColumn('id'))
                ->where('workspace_members.user_id', $userId),
        );
    }

    /* ------------------------------------------------------------------ *
     * Membership helpers
     * ------------------------------------------------------------------ */

    public function memberRole(User $user): ?WorkspaceRole
    {
        return $user->roleIn($this);
    }

    public function hasMember(User $user): bool
    {
        return $this->memberRole($user) !== null;
    }

    /**
     * Add or re-role a member. `joined_at` is stamped once and never moved, so re-roling
     * an existing member does not rewrite their tenure.
     */
    public function addMember(User $user, WorkspaceRole $role = WorkspaceRole::Member): WorkspaceMember
    {
        $member = WorkspaceMember::withoutWorkspaceScope()
            ->firstOrNew([
                'workspace_id' => $this->getKey(),
                'user_id' => $user->getKey(),
            ]);

        $member->role = $role;
        $member->joined_at ??= now();
        $member->save();

        $user->flushRoleCache();

        return $member;
    }
}

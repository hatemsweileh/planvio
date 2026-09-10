<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Priority;
use App\Enums\ProjectHealth;
use App\Enums\ProjectRole;
use App\Enums\ProjectType;
use App\Enums\WorkspaceRole;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

final class Project extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * `task_number_seq` is absent on purpose: the per-project task counter is advanced
     * atomically by the task-creation action and must never be set from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'name',
        'key',
        'slug',
        'description',
        'icon',
        'logo_path',
        'color',
        'type',
        'status_id',
        'health',
        'health_note',
        'health_set_manually',
        'priority',
        'owner_id',
        'manager_id',
        'client_name',
        'department',
        'start_date',
        'target_date',
        'completed_at',
        'budget',
        'currency',
        'progress',
        'settings',
        'ai_settings',
        'is_archived',
        'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProjectType::class,
            'health' => ProjectHealth::class,
            'priority' => Priority::class,
            'health_set_manually' => 'boolean',
            'start_date' => 'date',
            'target_date' => 'date',
            'completed_at' => 'datetime',
            'budget' => 'decimal:2',
            'progress' => 'integer',
            'task_number_seq' => 'integer',
            'settings' => 'array',
            'ai_settings' => 'array',
            'is_archived' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------------ *
     * Relationships
     * ------------------------------------------------------------------ */

    /**
     * @return BelongsTo<ProjectStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(ProjectStatus::class, 'status_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return HasMany<ProjectMember, $this>
     */
    public function projectMembers(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return HasMany<Milestone, $this>
     */
    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class);
    }

    /**
     * @return HasMany<TaskStatus, $this>
     */
    public function taskStatuses(): HasMany
    {
        return $this->hasMany(TaskStatus::class);
    }

    /**
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * @return HasMany<Expense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * @return HasMany<WikiPage, $this>
     */
    public function wikiPages(): HasMany
    {
        return $this->hasMany(WikiPage::class);
    }

    /**
     * @return HasMany<SavedView, $this>
     */
    public function savedViews(): HasMany
    {
        return $this->hasMany(SavedView::class);
    }

    /**
     * @return MorphMany<Activity, $this>
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    /**
     * @return MorphToMany<Tag, $this>
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->withTimestamps();
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /* ------------------------------------------------------------------ *
     * Accessors
     * ------------------------------------------------------------------ */

    /**
     * The project key as it is shown in the UI and in task keys.
     *
     * @return Attribute<string, never>
     */
    protected function displayKey(): Attribute
    {
        return Attribute::get(fn (): string => mb_strtoupper((string) $this->key));
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function isOverdue(): Attribute
    {
        return Attribute::get(fn (): bool => $this->target_date !== null
            && $this->completed_at === null
            && ! $this->is_archived
            && $this->target_date->lt(Carbon::today()));
    }

    /**
     * Uses a `completed_tasks_count` aggregate when the caller loaded one, so a project
     * list can avoid a query per row.
     *
     * @return Attribute<int, never>
     */
    protected function completedTaskCount(): Attribute
    {
        return Attribute::get(function (): int {
            if (array_key_exists('completed_tasks_count', $this->attributes)) {
                return (int) $this->attributes['completed_tasks_count'];
            }

            return $this->tasks()
                ->whereHas('status', fn (Builder $query): Builder => $query->where('is_completed', true))
                ->count();
        })->shouldCache();
    }

    /**
     * @return Attribute<int, never>
     */
    protected function openTaskCount(): Attribute
    {
        return Attribute::get(function (): int {
            if (array_key_exists('open_tasks_count', $this->attributes)) {
                return (int) $this->attributes['open_tasks_count'];
            }

            return $this->tasks()
                ->whereHas('status', fn (Builder $query): Builder => $query->where('is_completed', false))
                ->count();
        })->shouldCache();
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    public function taskKey(int $number): string
    {
        return $this->key.'-'.$number;
    }

    public function memberRole(User $user): ?ProjectRole
    {
        return $user->projectRoleIn($this);
    }

    public function hasMember(User $user): bool
    {
        return $this->memberRole($user) !== null;
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
        return $query->where($this->qualifyColumn('is_archived'), false);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_archived'), true);
    }

    /**
     * Projects the user is allowed to see: any workspace member above guest sees every
     * project in their workspace; everyone else sees only the projects they were added to.
     *
     * Expressed as two correlated EXISTS subqueries so a project list stays one query and
     * both lookups hit the unique indexes on the membership tables.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $userId = $user->getKey();
        $workspaceColumn = $this->qualifyColumn('workspace_id');
        $keyColumn = $this->qualifyColumn($this->getKeyName());

        return $query->where(function (Builder $query) use ($userId, $workspaceColumn, $keyColumn): void {
            $query
                ->whereExists(
                    fn (QueryBuilder $sub): QueryBuilder => $sub->selectRaw('1')
                        ->from('workspace_members')
                        ->whereColumn('workspace_members.workspace_id', $workspaceColumn)
                        ->where('workspace_members.user_id', $userId)
                        ->where('workspace_members.role', '!=', WorkspaceRole::Guest->value),
                )
                ->orWhereExists(
                    fn (QueryBuilder $sub): QueryBuilder => $sub->selectRaw('1')
                        ->from('project_members')
                        ->whereColumn('project_members.project_id', $keyColumn)
                        ->where('project_members.user_id', $userId),
                );
        });
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->where($this->qualifyColumn('is_archived'), false)
            ->whereNull($this->qualifyColumn('completed_at'))
            ->whereNotNull($this->qualifyColumn('target_date'))
            ->where($this->qualifyColumn('target_date'), '<', Carbon::today()->toDateString());
    }
}

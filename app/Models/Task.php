<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Priority;
use App\Enums\StatusCategory;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\TaskFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
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

/**
 * The unit of work (ARCHITECTURE.md §5.3).
 *
 * Every derived value here is an accessor over data that is already in memory. Boards and
 * lists render hundreds of tasks at a time, so an accessor that lazily loaded a relation
 * would turn one screen into hundreds of queries: each guards on `relationLoaded()` and
 * falls back to a denormalised column instead.
 */
final class Task extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'project_id',
        'number',
        'title',
        'description',
        'status_id',
        'priority',
        'assignee_id',
        'reporter_id',
        'parent_id',
        'milestone_id',
        'start_date',
        'due_date',
        'completed_at',
        'estimate_minutes',
        'position',
        'progress',
        'recurring_task_id',
        'created_by',
        'ai_generated',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'priority' => Priority::class,
            'start_date' => 'date',
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'estimate_minutes' => 'integer',
            'position' => 'decimal:10',
            'progress' => 'integer',
            'ai_generated' => 'boolean',
        ];
    }

    /* ------------------------------------------------------------------ *
     * Relationships
     * ------------------------------------------------------------------ */

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<TaskStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(TaskStatus::class, 'status_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function subtasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return BelongsTo<Milestone, $this>
     */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    /**
     * @return HasMany<TaskChecklistItem, $this>
     */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class)
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * Rows declaring what this task depends on.
     *
     * @return HasMany<TaskDependency, $this>
     */
    public function dependencies(): HasMany
    {
        return $this->hasMany(TaskDependency::class, 'task_id');
    }

    /**
     * Rows declaring what depends on this task.
     *
     * @return HasMany<TaskDependency, $this>
     */
    public function dependents(): HasMany
    {
        return $this->hasMany(TaskDependency::class, 'depends_on_task_id');
    }

    /**
     * The tasks this one depends on, resolved through the dependency table.
     *
     * Every declared dependency is returned whatever its type; the pivot carries `type`,
     * so a caller wanting only the gating ones narrows with
     * wherePivotIn('type', [...]) using DependencyType::isBlocking() as the guide.
     *
     * @return BelongsToMany<Task, $this>
     */
    public function blockedBy(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'task_dependencies',
            'task_id',
            'depends_on_task_id',
        )->withPivot(['id', 'workspace_id', 'type'])->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_watchers')->withTimestamps();
    }

    /**
     * @return MorphMany<Comment, $this>
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
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
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * @return BelongsTo<RecurringTask, $this>
     */
    public function recurringTask(): BelongsTo
    {
        return $this->belongsTo(RecurringTask::class);
    }

    /* ------------------------------------------------------------------ *
     * Derived attributes
     * ------------------------------------------------------------------ */

    /**
     * The human-facing identifier, e.g. "WEB-42".
     *
     * Falls back to "#42" when the project is not loaded rather than issuing a query for
     * the sake of a label.
     *
     * @return Attribute<string, never>
     */
    protected function key(): Attribute
    {
        return Attribute::get(function (): string {
            $project = $this->relationLoaded('project') ? $this->getRelation('project') : null;
            $prefix = $project instanceof Project ? $project->key : null;

            return is_string($prefix) && $prefix !== ''
                ? $prefix.'-'.$this->number
                : '#'.$this->number;
        });
    }

    /**
     * A loaded status is authoritative; without one the denormalised `completed_at`
     * answers the question without touching the database.
     *
     * @return Attribute<bool, never>
     */
    protected function isCompleted(): Attribute
    {
        return Attribute::get(function (): bool {
            if ($this->relationLoaded('status')) {
                $status = $this->getRelation('status');

                if ($status instanceof TaskStatus) {
                    return $status->is_completed === true
                        || ($status->category instanceof StatusCategory && $status->category->isClosed());
                }
            }

            return $this->completed_at !== null;
        });
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function isOverdue(): Attribute
    {
        return Attribute::get(function (): bool {
            $due = $this->due_date;

            if (! $due instanceof DateTimeInterface) {
                return false;
            }

            return ! $this->is_completed
                && Carbon::instance($due)->startOfDay()->isBefore(Carbon::now()->startOfDay());
        });
    }

    /**
     * Percentage of checklist items ticked, 0..100.
     *
     * Reads the loaded relation, then a withCount aggregate pair
     * (`checklist_items_count` / `checklist_items_done_count`), then gives up at 0.
     *
     * @return Attribute<int, never>
     */
    protected function checklistProgress(): Attribute
    {
        return Attribute::get(function (): int {
            if ($this->relationLoaded('checklistItems')) {
                $items = $this->getRelation('checklistItems');

                if ($items instanceof Collection) {
                    return self::percentage(
                        $items->filter(static fn (TaskChecklistItem $item): bool => $item->is_done)->count(),
                        $items->count(),
                    );
                }
            }

            if (array_key_exists('checklist_items_count', $this->attributes)
                && array_key_exists('checklist_items_done_count', $this->attributes)) {
                return self::percentage(
                    (int) $this->attributes['checklist_items_done_count'],
                    (int) $this->attributes['checklist_items_count'],
                );
            }

            return 0;
        });
    }

    /**
     * Percentage of subtasks completed, 0..100.
     *
     * @return Attribute<int, never>
     */
    protected function subtaskProgress(): Attribute
    {
        return Attribute::get(function (): int {
            if (! $this->relationLoaded('subtasks')) {
                return 0;
            }

            $subtasks = $this->getRelation('subtasks');

            if (! $subtasks instanceof Collection) {
                return 0;
            }

            return self::percentage(
                $subtasks->filter(static fn (self $subtask): bool => $subtask->is_completed)->count(),
                $subtasks->count(),
            );
        });
    }

    /**
     * Minutes logged against this task.
     *
     * Reads the loaded relation, then a withSum('timeEntries', 'minutes') aggregate.
     *
     * @return Attribute<int, never>
     */
    protected function loggedMinutes(): Attribute
    {
        return Attribute::get(function (): int {
            if ($this->relationLoaded('timeEntries')) {
                $entries = $this->getRelation('timeEntries');

                if ($entries instanceof Collection) {
                    return (int) $entries->sum('minutes');
                }
            }

            if (array_key_exists('time_entries_sum_minutes', $this->attributes)) {
                return (int) $this->attributes['time_entries_sum_minutes'];
            }

            return 0;
        });
    }

    private static function percentage(int $done, int $total): int
    {
        if ($total < 1) {
            return 0;
        }

        return (int) min(100, max(0, round($done / $total * 100)));
    }

    /* ------------------------------------------------------------------ *
     * Scopes
     * ------------------------------------------------------------------ */

    /**
     * Past its due date and not yet closed.
     *
     * Compares against the raw date column so index(workspace_id, due_date) stays usable;
     * `completed_at` is the close marker written when a task moves into a completed status.
     * A strict `<` against a bare date is exact whether the driver stored the column as a
     * date (MySQL) or as "Y-m-d H:i:s" (SQLite): the time suffix can only make a stored
     * value larger, so today's tasks are excluded either way.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeOverdue(Builder $query, DateTimeInterface|string|null $asOf = null): Builder
    {
        $date = $asOf === null ? Carbon::now() : Carbon::parse($asOf);

        return $query->whereNull($this->qualifyColumn('completed_at'))
            ->whereNotNull($this->qualifyColumn('due_date'))
            ->where($this->qualifyColumn('due_date'), '<', $date->toDateString());
    }

    /**
     * Tasks due on any day in the inclusive range.
     *
     * Expressed as a half-open interval rather than BETWEEN. MySQL stores a `date` column
     * as a bare date, SQLite stores whatever Eloquent's date cast writes — a full
     * "Y-m-d H:i:s" — so an inclusive upper bound of "2026-09-30" would silently drop
     * everything due that day under SQLite. `< 2026-10-01` is exact on both, and still
     * reads straight off index(workspace_id, due_date).
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeDueBetween(
        Builder $query,
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
    ): Builder {
        return $query->whereNotNull($this->qualifyColumn('due_date'))
            ->where($this->qualifyColumn('due_date'), '>=', Carbon::parse($from)->toDateString())
            ->where($this->qualifyColumn('due_date'), '<', Carbon::parse($to)->addDay()->toDateString());
    }

    /**
     * @param Builder<static> $query
     * @param User|int|iterable<int, User|int> $users
     * @return Builder<static>
     */
    public function scopeAssignedTo(Builder $query, User|int|iterable $users): Builder
    {
        $ids = [];

        foreach (is_iterable($users) ? $users : [$users] as $user) {
            $ids[] = $user instanceof User ? (int) $user->getKey() : (int) $user;
        }

        return $query->whereIn($this->qualifyColumn('assignee_id'), $ids);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('assignee_id'));
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('completed_at'));
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereNotNull($this->qualifyColumn('completed_at'));
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('parent_id'));
    }

    /**
     * @param Builder<static> $query
     * @param Priority|iterable<int, Priority|string> $priorities
     * @return Builder<static>
     */
    public function scopeWithPriority(Builder $query, Priority|iterable $priorities): Builder
    {
        $values = [];

        foreach (is_iterable($priorities) ? $priorities : [$priorities] as $priority) {
            $values[] = $priority instanceof Priority ? $priority->value : (string) $priority;
        }

        return $query->whereIn($this->qualifyColumn('priority'), $values);
    }

    /**
     * Filter by the category of the task's status without joining: a keyed subquery on
     * task_statuses composes with the other scopes and leaves the board index intact.
     *
     * @param Builder<static> $query
     * @param StatusCategory|iterable<int, StatusCategory|string> $categories
     * @return Builder<static>
     */
    public function scopeInStatusCategory(Builder $query, StatusCategory|iterable $categories): Builder
    {
        $values = [];

        foreach (is_iterable($categories) ? $categories : [$categories] as $category) {
            $values[] = $category instanceof StatusCategory ? $category->value : (string) $category;
        }

        return $query->whereIn(
            $this->qualifyColumn('status_id'),
            static function (QueryBuilder $sub) use ($values): void {
                $sub->select('id')->from('task_statuses')->whereIn('category', $values);
            },
        );
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

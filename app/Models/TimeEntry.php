<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\TimeEntryFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Time logged against a project, optionally against one of its tasks
 * (ARCHITECTURE.md §5.5).
 *
 * Durations are stored as whole minutes, never as hours: hours are a presentation concern
 * and floating-point sums of them do not add up.
 */
final class TimeEntry extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<TimeEntryFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'project_id',
        'task_id',
        'user_id',
        'minutes',
        'description',
        'spent_on',
        'started_at',
        'ended_at',
        'is_running',
        'is_billable',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'minutes' => 'integer',
            'spent_on' => 'date',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'is_running' => 'boolean',
            'is_billable' => 'boolean',
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
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /* ------------------------------------------------------------------ *
     * Derived attributes
     * ------------------------------------------------------------------ */

    /**
     * The stored minutes as decimal hours, for display and export only.
     *
     * @return Attribute<float, never>
     */
    protected function hours(): Attribute
    {
        return Attribute::get(fn (): float => round((int) $this->minutes / 60, 2));
    }

    /* ------------------------------------------------------------------ *
     * Scopes
     * ------------------------------------------------------------------ */

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_running'), true);
    }

    /**
     * Entries whose `spent_on` day falls inside the inclusive range.
     *
     * Expressed as a half-open interval rather than BETWEEN. MySQL stores a `date` column
     * as a bare date, SQLite stores whatever Eloquent's date cast writes — a full
     * "Y-m-d H:i:s" — so an inclusive upper bound would silently drop the last day of the
     * range under SQLite. The exclusive next-day bound is exact on both drivers and still
     * reads off index(workspace_id, user_id, spent_on).
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeBetween(
        Builder $query,
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
    ): Builder {
        return $query->where($this->qualifyColumn('spent_on'), '>=', Carbon::parse($from)->toDateString())
            ->where($this->qualifyColumn('spent_on'), '<', Carbon::parse($to)->addDay()->toDateString());
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
    public function scopeForProject(Builder $query, Project|int $project): Builder
    {
        return $query->where(
            $this->qualifyColumn('project_id'),
            $project instanceof Project ? $project->getKey() : $project,
        );
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForTask(Builder $query, Task|int $task): Builder
    {
        return $query->where(
            $this->qualifyColumn('task_id'),
            $task instanceof Task ? $task->getKey() : $task,
        );
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeBillable(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_billable'), true);
    }
}

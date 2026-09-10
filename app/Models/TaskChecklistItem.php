<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TaskChecklistItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tick box inside a task (ARCHITECTURE.md §5.3).
 *
 * The row carries no `workspace_id`: it is reachable only through its task, and deleting
 * the task cascades. Tenancy is therefore enforced one level up, on the task.
 */
final class TaskChecklistItem extends Model
{
    /** @use HasFactory<TaskChecklistItemFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'task_id',
        'title',
        'is_done',
        'position',
        'completed_at',
        'completed_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_done' => 'boolean',
            'position' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------------ *
     * Relationships
     * ------------------------------------------------------------------ */

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
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /* ------------------------------------------------------------------ *
     * Scopes
     * ------------------------------------------------------------------ */

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
    public function scopeDone(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_done'), true);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_done'), false);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy($this->qualifyColumn('position'))
            ->orderBy($this->qualifyColumn('id'));
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TaskWatcherFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user following a task's activity (ARCHITECTURE.md §5.3).
 *
 * Task::watchers() reads the same table as a BelongsToMany; this model exists for the
 * cases that need the row itself — notification fan-out and audit.
 */
final class TaskWatcher extends Model
{
    /** @use HasFactory<TaskWatcherFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'task_id',
        'user_id',
    ];

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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where(
            $this->qualifyColumn('user_id'),
            $user instanceof User ? $user->getKey() : $user,
        );
    }
}

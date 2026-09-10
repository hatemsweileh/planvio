<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DependencyType;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\TaskDependencyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A directed edge between two tasks (ARCHITECTURE.md §5.3): `task_id` depends on
 * `depends_on_task_id`.
 */
final class TaskDependency extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<TaskDependencyFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'task_id',
        'depends_on_task_id',
        'type',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DependencyType::class,
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
     * @return BelongsTo<Task, $this>
     */
    public function dependsOnTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'depends_on_task_id');
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
     * @param DependencyType|iterable<int, DependencyType|string> $types
     * @return Builder<static>
     */
    public function scopeOfType(Builder $query, DependencyType|iterable $types): Builder
    {
        $values = [];

        foreach (is_iterable($types) ? $types : [$types] as $type) {
            $values[] = $type instanceof DependencyType ? $type->value : (string) $type;
        }

        return $query->whereIn($this->qualifyColumn('type'), $values);
    }

    /**
     * Only the edges that gate scheduling — `relates_to` documents a link but blocks
     * nothing (see DependencyType::isBlocking()).
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeBlocking(Builder $query): Builder
    {
        $values = [];

        foreach (DependencyType::cases() as $type) {
            if ($type->isBlocking()) {
                $values[] = $type->value;
            }
        }

        return $query->whereIn($this->qualifyColumn('type'), $values);
    }
}

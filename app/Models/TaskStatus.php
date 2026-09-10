<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatusCategory;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\TaskStatusFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A column on a project's board (ARCHITECTURE.md §5.3).
 *
 * Statuses are per-project rows seeded from the workspace defaults when the project is
 * created, so renaming or reordering one project's board never disturbs another.
 */
final class TaskStatus extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<TaskStatusFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'project_id',
        'name',
        'color',
        'category',
        'position',
        'is_default',
        'is_completed',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => StatusCategory::class,
            'position' => 'integer',
            'is_default' => 'boolean',
            'is_completed' => 'boolean',
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
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'status_id');
    }

    /* ------------------------------------------------------------------ *
     * Scopes
     * ------------------------------------------------------------------ */

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
     * Board order: `position` then `id`, matching index(project_id, position).
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy($this->qualifyColumn('position'))
            ->orderBy($this->qualifyColumn('id'));
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_default'), true);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_completed'), true);
    }

    /**
     * @param Builder<static> $query
     * @param StatusCategory|iterable<int, StatusCategory|string> $categories
     * @return Builder<static>
     */
    public function scopeInCategory(Builder $query, StatusCategory|iterable $categories): Builder
    {
        $values = [];

        foreach (is_iterable($categories) ? $categories : [$categories] as $category) {
            $values[] = $category instanceof StatusCategory ? $category->value : (string) $category;
        }

        return $query->whereIn($this->qualifyColumn('category'), $values);
    }
}

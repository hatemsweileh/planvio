<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ViewType;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\SavedViewFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stored list/board/calendar/timeline configuration.
 *
 * A null `user_id` marks a view that belongs to the workspace rather than to a person.
 */
final class SavedView extends Model
{
    /** @use HasFactory<SavedViewFactory> */
    use BelongsToWorkspace;

    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id',
        'project_id',
        'user_id',
        'name',
        'type',
        'filters',
        'sorts',
        'columns',
        'group_by',
        'is_shared',
        'is_pinned',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ViewType::class,
            'filters' => 'array',
            'sorts' => 'array',
            'columns' => 'array',
            'is_shared' => 'boolean',
            'is_pinned' => 'boolean',
            'position' => 'integer',
        ];
    }

    /* ---------------------------------------------------------------- *
     * Relations
     * ---------------------------------------------------------------- */

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /* ---------------------------------------------------------------- *
     * Derived reads
     * ---------------------------------------------------------------- */

    public function isPersonal(): bool
    {
        return $this->user_id !== null && $this->is_shared === false;
    }

    /* ---------------------------------------------------------------- *
     * Scopes
     * ---------------------------------------------------------------- */

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
     * Views that belong to the workspace rather than to a project.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeWorkspaceLevel(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('project_id'));
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeOwnedBy(Builder $query, User|int $user): Builder
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
    public function scopeShared(Builder $query): Builder
    {
        return $query->where(function (Builder $shared): void {
            $shared
                ->where($this->qualifyColumn('is_shared'), true)
                ->orWhereNull($this->qualifyColumn('user_id'));
        });
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopePinned(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_pinned'), true);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeOfType(Builder $query, ViewType $type): Builder
    {
        return $query->where($this->qualifyColumn('type'), $type->value);
    }

    /**
     * A user sees their own views plus everything shared with the workspace.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $visible) use ($user): void {
            $visible
                ->where($this->qualifyColumn('user_id'), $user->getKey())
                ->orWhere($this->qualifyColumn('is_shared'), true)
                ->orWhereNull($this->qualifyColumn('user_id'));
        });
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderByDesc($this->qualifyColumn('is_pinned'))
            ->orderBy($this->qualifyColumn('position'))
            ->orderBy($this->qualifyColumn('name'));
    }
}

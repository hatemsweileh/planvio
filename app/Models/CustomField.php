<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomFieldType;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\CustomFieldFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user-defined attribute attached to tasks or projects.
 *
 * A null `project_id` makes the field available across the whole workspace.
 */
final class CustomField extends Model
{
    /** @use HasFactory<CustomFieldFactory> */
    use BelongsToWorkspace;

    use HasFactory;

    /** Entity discriminators accepted by the `entity` column. */
    public const ENTITY_TASK = 'task';

    public const ENTITY_PROJECT = 'project';

    /** @var list<string> */
    protected $fillable = [
        'workspace_id',
        'project_id',
        'entity',
        'name',
        'key',
        'type',
        'options',
        'is_required',
        'position',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CustomFieldType::class,
            'options' => 'array',
            'is_required' => 'boolean',
            'position' => 'integer',
            'is_active' => 'boolean',
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
     * @return HasMany<CustomFieldValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    /* ---------------------------------------------------------------- *
     * Derived reads
     * ---------------------------------------------------------------- */

    /**
     * The `custom_field_values` column this field's answers are stored in.
     */
    public function valueColumn(): string
    {
        return ($this->type ?? CustomFieldType::Text)->valueColumn();
    }

    /**
     * @return array<int, string>
     */
    public function choices(): array
    {
        $options = $this->options;

        if (! is_array($options)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $option): string => (string) $option, $options));
    }

    /* ---------------------------------------------------------------- *
     * Scopes
     * ---------------------------------------------------------------- */

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_active'), true);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForEntity(Builder $query, string $entity): Builder
    {
        return $query->where($this->qualifyColumn('entity'), $entity);
    }

    /**
     * Strictly the fields defined on one project.
     *
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
    public function scopeWorkspaceWide(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('project_id'));
    }

    /**
     * Every field a project sees: its own plus the workspace-wide ones.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeAvailableForProject(Builder $query, Project|int|null $project): Builder
    {
        $projectId = $project instanceof Project ? $project->getKey() : $project;

        if ($projectId === null) {
            return $query->whereNull($this->qualifyColumn('project_id'));
        }

        return $query->where(function (Builder $available) use ($projectId): void {
            $available
                ->whereNull($this->qualifyColumn('project_id'))
                ->orWhere($this->qualifyColumn('project_id'), $projectId);
        });
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeOfType(Builder $query, CustomFieldType $type): Builder
    {
        return $query->where($this->qualifyColumn('type'), $type->value);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy($this->qualifyColumn('position'))
            ->orderBy($this->qualifyColumn('name'));
    }
}

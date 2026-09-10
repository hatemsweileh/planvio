<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatusCategory;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\ProjectStatusFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A workspace-level project lifecycle stage, reused across every project in the workspace.
 */
final class ProjectStatus extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<ProjectStatusFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'name',
        'color',
        'category',
        'position',
        'is_default',
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
        ];
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'status_id');
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy($this->qualifyColumn('position'))
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
     * @param StatusCategory|list<StatusCategory> $category
     * @return Builder<static>
     */
    public function scopeInCategory(Builder $query, StatusCategory|array $category): Builder
    {
        $categories = is_array($category) ? $category : [$category];

        return $query->whereIn(
            $this->qualifyColumn('category'),
            array_map(static fn (StatusCategory $case): string => $case->value, $categories),
        );
    }
}

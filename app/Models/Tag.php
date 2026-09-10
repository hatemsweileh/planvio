<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * A free-form label shared across a workspace (ARCHITECTURE.md §5.3).
 *
 * Tags attach polymorphically through `taggables`, so the same tag can mark both tasks
 * and projects.
 */
final class Tag extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<TagFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'name',
        'slug',
        'color',
        'description',
    ];

    /* ------------------------------------------------------------------ *
     * Relationships
     * ------------------------------------------------------------------ */

    /**
     * @return MorphToMany<Task, $this>
     */
    public function tasks(): MorphToMany
    {
        return $this->morphedByMany(Task::class, 'taggable')->withTimestamps();
    }

    /**
     * @return MorphToMany<Project, $this>
     */
    public function projects(): MorphToMany
    {
        return $this->morphedByMany(Project::class, 'taggable')->withTimestamps();
    }

    /* ------------------------------------------------------------------ *
     * Routing
     * ------------------------------------------------------------------ */

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /* ------------------------------------------------------------------ *
     * Scopes
     * ------------------------------------------------------------------ */

    /**
     * @param Builder<static> $query
     * @param string|iterable<int, string> $slugs
     * @return Builder<static>
     */
    public function scopeWithSlug(Builder $query, string|iterable $slugs): Builder
    {
        $values = [];

        foreach (is_iterable($slugs) ? $slugs : [$slugs] as $slug) {
            $values[] = (string) $slug;
        }

        return $query->whereIn($this->qualifyColumn('slug'), $values);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy($this->qualifyColumn('name'));
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectType;
use App\Models\Scopes\WorkspaceScope;
use Database\Factories\ProjectTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reusable project blueprint: statuses, milestones, tasks, tags and views as JSON.
 *
 * `workspace_id` is nullable — a null row is a system template shipped with Planvio and is
 * visible to every workspace. That global tier is why the model does not register
 * {@see WorkspaceScope}: the ambient scope would hide system templates
 * inside a bound workspace. Callers narrow with {@see self::scopeAvailableIn()} and the
 * policy remains the authority (ARCHITECTURE.md §3).
 */
final class ProjectTemplate extends Model
{
    /** @use HasFactory<ProjectTemplateFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id',
        'name',
        'slug',
        'description',
        'icon',
        'color',
        'type',
        'definition',
        'is_system',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProjectType::class,
            'definition' => 'array',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /* ---------------------------------------------------------------- *
     * Relations
     * ---------------------------------------------------------------- */

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /* ---------------------------------------------------------------- *
     * Derived reads
     * ---------------------------------------------------------------- */

    public function isGlobal(): bool
    {
        return $this->workspace_id === null;
    }

    /**
     * One section of the blueprint — `statuses`, `milestones`, `tasks`, `tags`, `views`.
     *
     * @return array<array-key, mixed>
     */
    public function section(string $key): array
    {
        $definition = $this->definition;

        if (! is_array($definition) || ! is_array($definition[$key] ?? null)) {
            return [];
        }

        return $definition[$key];
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
     * Templates shipped with Planvio rather than authored by a workspace.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('workspace_id'));
    }

    /**
     * Strictly the templates a workspace authored.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForWorkspace(Builder $query, Workspace|int $workspace): Builder
    {
        return $query->where(
            $this->qualifyColumn('workspace_id'),
            $workspace instanceof Workspace ? $workspace->getKey() : $workspace,
        );
    }

    /**
     * Everything a workspace may start a project from: its own templates plus the system ones.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeAvailableIn(Builder $query, Workspace|int $workspace): Builder
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->getKey() : $workspace;

        return $query->where(function (Builder $available) use ($workspaceId): void {
            $available
                ->whereNull($this->qualifyColumn('workspace_id'))
                ->orWhere($this->qualifyColumn('workspace_id'), $workspaceId);
        });
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeOfType(Builder $query, ProjectType $type): Builder
    {
        return $query->where($this->qualifyColumn('type'), $type->value);
    }
}

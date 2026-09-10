<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiMode;
use App\Enums\AiToolRisk;
use App\Models\Scopes\WorkspaceScope;
use Database\Factories\AiPolicyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An allow/deny/approval rule applied to a run's tool calls.
 *
 * A null `project_id` makes the rule cover the whole workspace; a null `workspace_id` makes it
 * platform-wide. Those global tiers are why the model does not register
 * {@see WorkspaceScope} — the ambient scope would drop platform rules from
 * the stack. Resolution always goes through {@see self::scopeApplicableTo()}, which names its
 * workspace explicitly.
 *
 * Evaluating the stack (merging allow/deny lists, resolving approvals) is the agent layer's
 * job, not the model's.
 */
final class AiPolicy extends Model
{
    /** @use HasFactory<AiPolicyFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id',
        'project_id',
        'name',
        'mode',
        'allowed_tools',
        'denied_tools',
        'approval_required_tools',
        'max_risk',
        'allowed_roles',
        'priority',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => AiMode::class,
            'allowed_tools' => 'array',
            'denied_tools' => 'array',
            'approval_required_tools' => 'array',
            'max_risk' => AiToolRisk::class,
            'allowed_roles' => 'array',
            'priority' => 'integer',
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

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /* ---------------------------------------------------------------- *
     * Derived reads
     * ---------------------------------------------------------------- */

    public function isProjectScoped(): bool
    {
        return $this->project_id !== null;
    }

    public function isPlatformWide(): bool
    {
        return $this->workspace_id === null;
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
     * The active policy stack governing $workspace, and $project when one is in play.
     *
     * Rows are returned most-significant first: higher priority wins, then the more specific
     * rule — project before workspace-wide, workspace before platform-wide — so a caller can
     * fold the stack in iteration order. Ties break on id so the order is stable.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeApplicableTo(Builder $query, Workspace $workspace, ?Project $project = null): Builder
    {
        $workspaceColumn = $this->qualifyColumn('workspace_id');
        $projectColumn = $this->qualifyColumn('project_id');

        return $query
            ->where($this->qualifyColumn('is_active'), true)
            ->where(function (Builder $tenant) use ($workspace, $workspaceColumn): void {
                $tenant
                    ->where($workspaceColumn, $workspace->getKey())
                    ->orWhereNull($workspaceColumn);
            })
            ->where(function (Builder $scope) use ($project, $projectColumn): void {
                $scope->whereNull($projectColumn);

                if ($project !== null) {
                    $scope->orWhere($projectColumn, $project->getKey());
                }
            })
            ->orderByDesc($this->qualifyColumn('priority'))
            ->orderByRaw("({$projectColumn} is null) asc")
            ->orderByRaw("({$workspaceColumn} is null) asc")
            ->orderBy($this->qualifyColumn('id'));
    }
}

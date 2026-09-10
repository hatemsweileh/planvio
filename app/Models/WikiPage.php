<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WikiVisibility;
use App\Enums\WorkspaceRole;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\WikiPageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * A wiki page: a node in a per-project (or workspace-wide) documentation tree.
 */
final class WikiPage extends Model
{
    /** @use HasFactory<WikiPageFactory> */
    use BelongsToWorkspace;

    use HasFactory;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id',
        'project_id',
        'parent_id',
        'title',
        'slug',
        'content',
        'excerpt',
        'position',
        'visibility',
        'author_id',
        'last_edited_by',
        'ai_generated',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'visibility' => WikiVisibility::class,
            'ai_generated' => 'boolean',
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
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderBy('position')
            ->orderBy('title');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /* ---------------------------------------------------------------- *
     * Derived reads
     * ---------------------------------------------------------------- */

    /**
     * Ancestors root-first, ending with this page.
     *
     * @return array<int, self>
     */
    public function breadcrumb(): array
    {
        $trail = [];
        $seen = [];
        $page = $this;

        while ($page instanceof self) {
            $id = (int) $page->getKey();

            // The tree is user-editable, so a parent cycle is reachable; stop instead of looping.
            if (isset($seen[$id])) {
                break;
            }

            $seen[$id] = true;
            array_unshift($trail, $page);

            $page = $page->parent;
        }

        return $trail;
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /* ---------------------------------------------------------------- *
     * Scopes
     * ---------------------------------------------------------------- */

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('parent_id'));
    }

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
     * Workspace-level pages: those that belong to no project.
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
    public function scopeWithVisibility(Builder $query, WikiVisibility $visibility): Builder
    {
        return $query->where($this->qualifyColumn('visibility'), $visibility->value);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy($this->qualifyColumn('position'))
            ->orderBy($this->qualifyColumn('title'));
    }

    /**
     * Narrow to the pages $user may read. Convenience only — WikiPagePolicy remains the
     * authority (ARCHITECTURE.md §3): workspace membership gates everything, project pages
     * additionally require project membership or a workspace role above guest, and private
     * pages stay with their author.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $userId = $user->getKey();
        $workspaceColumn = $this->qualifyColumn('workspace_id');
        $projectColumn = $this->qualifyColumn('project_id');

        return $query
            ->whereExists(function (QueryBuilder $member) use ($userId, $workspaceColumn): void {
                $member->select(DB::raw('1'))
                    ->from('workspace_members')
                    ->whereColumn('workspace_members.workspace_id', $workspaceColumn)
                    ->where('workspace_members.user_id', $userId);
            })
            ->where(function (Builder $visible) use ($userId, $workspaceColumn, $projectColumn): void {
                $visible
                    ->where($this->qualifyColumn('visibility'), WikiVisibility::Workspace->value)
                    ->orWhere(function (Builder $scoped) use ($userId, $workspaceColumn, $projectColumn): void {
                        $scoped
                            ->where($this->qualifyColumn('visibility'), WikiVisibility::Project->value)
                            ->where(function (Builder $reach) use ($userId, $workspaceColumn, $projectColumn): void {
                                $reach
                                    ->whereNull($projectColumn)
                                    ->orWhereExists(function (QueryBuilder $projectMember) use ($userId, $projectColumn): void {
                                        $projectMember->select(DB::raw('1'))
                                            ->from('project_members')
                                            ->whereColumn('project_members.project_id', $projectColumn)
                                            ->where('project_members.user_id', $userId);
                                    })
                                    ->orWhereExists(function (QueryBuilder $workspaceMember) use ($userId, $workspaceColumn): void {
                                        $workspaceMember->select(DB::raw('1'))
                                            ->from('workspace_members')
                                            ->whereColumn('workspace_members.workspace_id', $workspaceColumn)
                                            ->where('workspace_members.user_id', $userId)
                                            ->where('workspace_members.role', '!=', WorkspaceRole::Guest->value);
                                    });
                            });
                    })
                    ->orWhere(function (Builder $own) use ($userId): void {
                        $own
                            ->where($this->qualifyColumn('visibility'), WikiVisibility::Private->value)
                            ->where($this->qualifyColumn('author_id'), $userId);
                    });
            });
    }
}

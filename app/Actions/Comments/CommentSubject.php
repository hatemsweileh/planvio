<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Exceptions\InvalidComment;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\WikiPage;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;

/**
 * What the comment actions need to know about the thing being commented on.
 *
 * `comments` is polymorphic, so every action that touches one has the same three questions:
 * which tenant does this belong to, which project (if any) does it sit in, and what should
 * a notification call it. Resolving that once, here, keeps the answer consistent between
 * creating a comment, editing it and notifying about it — and makes the tenancy check a
 * single place rather than a repeated `?? null`.
 *
 * A subject with no `workspace_id` is refused outright: a comment on it could not be scoped
 * to a tenant, and an unscoped comment is a leak (ARCHITECTURE.md §3).
 */
final readonly class CommentSubject
{
    private function __construct(
        public Model $commentable,
        public Workspace $workspace,
        public ?Project $project,
        public string $title,
    ) {}

    public static function for(Model $commentable): self
    {
        $workspace = self::workspaceOf($commentable);
        $project = self::projectOf($commentable, $workspace);

        return new self(
            commentable: $commentable,
            workspace: $workspace,
            project: $project,
            title: self::titleOf($commentable),
        );
    }

    public function workspaceId(): int
    {
        return (int) $this->workspace->getKey();
    }

    public function projectId(): ?int
    {
        return $this->project === null ? null : (int) $this->project->getKey();
    }

    private static function workspaceOf(Model $commentable): Workspace
    {
        if ($commentable instanceof Workspace) {
            return $commentable;
        }

        if ($commentable->relationLoaded('workspace')) {
            $loaded = $commentable->getRelation('workspace');

            if ($loaded instanceof Workspace) {
                return $loaded;
            }
        }

        $workspaceId = $commentable->getAttribute('workspace_id');

        if ($workspaceId === null) {
            throw InvalidComment::notCommentable($commentable);
        }

        $workspace = Workspace::query()->find($workspaceId);

        if (! $workspace instanceof Workspace) {
            throw InvalidComment::notCommentable($commentable);
        }

        return $workspace;
    }

    private static function projectOf(Model $commentable, Workspace $workspace): ?Project
    {
        if ($commentable instanceof Project) {
            return $commentable;
        }

        if ($commentable->relationLoaded('project')) {
            $loaded = $commentable->getRelation('project');

            if ($loaded instanceof Project) {
                return $loaded;
            }
        }

        $projectId = $commentable->getAttribute('project_id');

        if ($projectId === null) {
            return null;
        }

        // Read past the tenant scope, then verify the tenant here: the scope is inert in a
        // queued job and would silently return null instead of the project (§3).
        $project = Project::withoutWorkspaceScope()->find($projectId);

        if (! $project instanceof Project) {
            return null;
        }

        return (int) $project->workspace_id === (int) $workspace->getKey() ? $project : null;
    }

    private static function titleOf(Model $commentable): string
    {
        $title = match (true) {
            $commentable instanceof Task => (string) $commentable->title,
            $commentable instanceof Project => (string) $commentable->name,
            $commentable instanceof Milestone => (string) $commentable->name,
            $commentable instanceof WikiPage => (string) $commentable->title,
            default => (string) ($commentable->getAttribute('title') ?? $commentable->getAttribute('name') ?? ''),
        };

        $title = trim($title);

        return $title === ''
            ? class_basename($commentable).' #'.$commentable->getKey()
            : mb_substr($title, 0, 191);
    }
}

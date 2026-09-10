<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AuthorType;
use App\Enums\Permission;
use App\Models\Comment;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceAccess;
use Illuminate\Database\Eloquent\Model;

/**
 * Comments hang off tasks, projects, milestones and wiki pages, so the matrix cell that
 * governs them is `task.comment` whatever the parent is — including the guest `*`, which is
 * what keeps a guest's reading inside the projects they belong to.
 *
 * The parent lookup is deferred: it runs only for a guest, whose cell is conditional. Every
 * other role reads a thread without a single extra query.
 */
final class CommentPolicy
{
    use ChecksWorkspaceAccess;

    public function viewAny(User $user, ?Model $commentable = null): bool
    {
        return $this->permitsSomewhere(
            $user,
            $this->contextWorkspace($commentable?->getAttribute('workspace_id')),
            Permission::TaskComment,
        );
    }

    public function view(User $user, Comment $comment): bool
    {
        return $this->permits(
            $user,
            $comment->workspace_id,
            Permission::TaskComment,
            fn (): ?int => $this->parentProjectId($comment),
        );
    }

    public function create(User $user, ?Model $commentable = null): bool
    {
        if ($commentable === null) {
            return $this->permitsSomewhere($user, $this->currentWorkspace(), Permission::TaskComment);
        }

        $workspaceId = $commentable->getAttribute('workspace_id');

        return $this->permits(
            $user,
            $workspaceId,
            Permission::TaskComment,
            fn (): ?int => $this->relatedProjectId($commentable, $workspaceId),
        );
    }

    public function reply(User $user, Comment $comment): bool
    {
        return $this->view($user, $comment);
    }

    public function react(User $user, Comment $comment): bool
    {
        return $this->view($user, $comment);
    }

    /**
     * Rewriting somebody else's words is never delegated: an author edits their own comment
     * and nobody else's, however senior. A comment the agent wrote has no human author and
     * is therefore immutable — the audit trail in `ai_tool_runs` refers to it.
     */
    public function update(User $user, Comment $comment): bool
    {
        if ($comment->author_type === AuthorType::Ai || $comment->user_id === null) {
            return false;
        }

        if ((int) $comment->user_id !== (int) $user->getKey()) {
            return false;
        }

        return $this->view($user, $comment);
    }

    /**
     * Deletion is moderation as well as authorship: the author may retract, and so may
     * anybody who administers the workspace or manages the project the thread lives in.
     */
    public function delete(User $user, Comment $comment): bool
    {
        if ($comment->user_id !== null
            && (int) $comment->user_id === (int) $user->getKey()
            && $this->view($user, $comment)) {
            return true;
        }

        if ($this->permits($user, $comment->workspace_id, Permission::WorkspaceManage)) {
            return true;
        }

        return $this->permits(
            $user,
            $comment->workspace_id,
            Permission::TaskDelete,
            fn (): ?int => $this->parentProjectId($comment),
        );
    }

    public function restore(User $user, Comment $comment): bool
    {
        return $this->delete($user, $comment);
    }

    public function forceDelete(User $user, Comment $comment): bool
    {
        return $this->permits($user, $comment->workspace_id, Permission::WorkspaceManage);
    }

    private function parentProjectId(Comment $comment): ?int
    {
        return $this->morphedProjectId(
            $comment->relationLoaded('commentable') ? $comment->commentable : null,
            $comment->commentable_type,
            $comment->commentable_id,
            $comment->workspace_id,
        );
    }
}

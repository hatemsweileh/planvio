<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\WikiVisibility;
use App\Models\Project;
use App\Models\User;
use App\Models\WikiPage;
use App\Policies\Concerns\ChecksWorkspaceAccess;

/**
 * Wiki access is the matrix cell narrowed by the page's own visibility:
 *
 *   private   — the author's alone, whatever anybody's workspace role says;
 *   workspace — every workspace member with `wiki.view`, so a guest (whose cell is `*` and
 *               has no project to satisfy it with) is out;
 *   project   — `wiki.view` refined by the page's project, which is exactly the `*` cell.
 */
final class WikiPagePolicy
{
    use ChecksWorkspaceAccess;

    public function viewAny(User $user, ?Project $project = null): bool
    {
        return $this->permitsSomewhere(
            $user,
            $this->contextWorkspace($project),
            Permission::WikiView,
        );
    }

    public function view(User $user, WikiPage $page): bool
    {
        if (! $this->passesVisibility($user, $page)) {
            return false;
        }

        return $this->permits(
            $user,
            $page->workspace_id,
            Permission::WikiView,
            $this->scopeProjectId($page),
        );
    }

    public function create(User $user, ?Project $project = null): bool
    {
        return $this->permits(
            $user,
            $this->contextWorkspace($project),
            Permission::WikiManage,
            $project,
        );
    }

    public function update(User $user, WikiPage $page): bool
    {
        return $this->manages($user, $page);
    }

    public function delete(User $user, WikiPage $page): bool
    {
        return $this->manages($user, $page);
    }

    public function restore(User $user, WikiPage $page): bool
    {
        return $this->manages($user, $page);
    }

    public function forceDelete(User $user, WikiPage $page): bool
    {
        return $this->manages($user, $page);
    }

    public function move(User $user, WikiPage $page): bool
    {
        return $this->manages($user, $page);
    }

    private function manages(User $user, WikiPage $page): bool
    {
        if (! $this->passesVisibility($user, $page)) {
            return false;
        }

        return $this->permits(
            $user,
            $page->workspace_id,
            Permission::WikiManage,
            $this->scopeProjectId($page),
        );
    }

    /**
     * A private page never leaves its author, not even to a workspace owner: the visibility
     * is a promise the product made to the person who wrote it.
     */
    private function passesVisibility(User $user, WikiPage $page): bool
    {
        if ($page->visibility !== WikiVisibility::Private) {
            return true;
        }

        return $page->author_id !== null && (int) $page->author_id === (int) $user->getKey();
    }

    /**
     * A workspace-visible page is deliberately not refined by its project — that is what
     * "workspace" means. Every other visibility refines against the page's project, so a
     * guest reaches it only from inside.
     */
    private function scopeProjectId(WikiPage $page): ?int
    {
        return $page->visibility === WikiVisibility::Workspace ? null : $this->projectIdOf($page);
    }
}

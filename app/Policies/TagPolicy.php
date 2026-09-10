<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\Concerns\ChecksWorkspaceAccess;

/**
 * Tags are shared workspace vocabulary. Adding one to the list is as ordinary as creating a
 * task, but renaming or removing one rewrites every record already carrying it, so those
 * stay with `settings.manage`.
 */
final class TagPolicy
{
    use ChecksWorkspaceAccess;

    public function viewAny(User $user, Workspace|Project|null $context = null): bool
    {
        return $this->permitsSomewhere(
            $user,
            $this->contextWorkspace($context),
            Permission::ProjectView,
        );
    }

    /**
     * A tag is workspace-level, so a guest's `*` cell has no project to refine against.
     * Reading is nonetheless allowed on membership alone: the label is already visible on
     * every task they can open, and hiding the vocabulary would only break their filters.
     */
    public function view(User $user, Tag $tag): bool
    {
        return $this->permitsSomewhere($user, $tag->workspace_id, Permission::ProjectView);
    }

    public function create(User $user, Workspace|Project|null $context = null): bool
    {
        return $this->permitsSomewhere(
            $user,
            $this->contextWorkspace($context),
            Permission::TaskCreate,
        );
    }

    public function update(User $user, Tag $tag): bool
    {
        return $this->permits($user, $tag->workspace_id, Permission::SettingsManage);
    }

    public function delete(User $user, Tag $tag): bool
    {
        return $this->permits($user, $tag->workspace_id, Permission::SettingsManage);
    }

    /**
     * Applying an existing tag to a record is a change to that record, so the record's own
     * policy decides; all this needs is that the tag is readable.
     */
    public function attach(User $user, Tag $tag): bool
    {
        return $this->view($user, $tag);
    }
}

<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceAccess;

/**
 * Two cells govern a timesheet: `time.log`, which everybody above guest holds for their own
 * entries, and `time.view_all`, which reaches other people's — unconditionally for an owner
 * or admin, and only inside a managed project for a workspace manager (`+`).
 */
final class TimeEntryPolicy
{
    use ChecksWorkspaceAccess;

    public function viewAny(User $user, ?Project $project = null): bool
    {
        return $this->permitsSomewhere(
            $user,
            $this->contextWorkspace($project),
            Permission::TimeLog,
        );
    }

    public function view(User $user, TimeEntry $entry): bool
    {
        if ($this->isOwnEntry($user, $entry)
            && $this->permits($user, $entry->workspace_id, Permission::TimeLog)) {
            return true;
        }

        return $this->viewOthers($user, $entry);
    }

    public function create(User $user, ?Project $project = null): bool
    {
        return $this->permits(
            $user,
            $this->contextWorkspace($project),
            Permission::TimeLog,
            $project,
        );
    }

    /**
     * Correcting somebody else's timesheet is the reporting side of the same authority that
     * lets a manager read it, so it rides on `time.view_all` rather than inventing a cell.
     */
    public function update(User $user, TimeEntry $entry): bool
    {
        if ($this->isOwnEntry($user, $entry)
            && $this->permits($user, $entry->workspace_id, Permission::TimeLog)) {
            return true;
        }

        return $this->viewOthers($user, $entry);
    }

    public function delete(User $user, TimeEntry $entry): bool
    {
        return $this->update($user, $entry);
    }

    public function stop(User $user, TimeEntry $entry): bool
    {
        return $this->update($user, $entry);
    }

    /**
     * The team-wide timesheet, as opposed to one's own.
     */
    public function viewAll(User $user, ?Project $project = null): bool
    {
        return $this->permits(
            $user,
            $this->contextWorkspace($project),
            Permission::TimeViewAll,
            $project,
        );
    }

    private function viewOthers(User $user, TimeEntry $entry): bool
    {
        return $this->permits(
            $user,
            $entry->workspace_id,
            Permission::TimeViewAll,
            $this->projectIdOf($entry),
        );
    }

    private function isOwnEntry(User $user, TimeEntry $entry): bool
    {
        return $entry->user_id !== null && (int) $entry->user_id === (int) $user->getKey();
    }
}

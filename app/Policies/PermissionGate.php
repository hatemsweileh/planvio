<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\Concerns\ChecksWorkspaceAccess;
use App\Providers\AuthServiceProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * The capability matrix answered as a Gate ability, for the product chrome.
 *
 * Registered for every {@see Permission} case by
 * {@see AuthServiceProvider::registerPermissionAbilities()}, so Blade can ask
 * `@can('ai.use', $workspace)` and get the same answer a policy would give — this class runs
 * the identical {@see ChecksWorkspaceAccess} sequence, not a parallel copy of the rules.
 *
 * It is a *capability* check, never a record check. Given a record it refines the way a
 * policy does; given only a workspace it answers "could this role ever". Nothing in the
 * product authorises a write through this — writes go through the model's own policy.
 */
final class PermissionGate
{
    use ChecksWorkspaceAccess;

    /**
     * @param array<int, mixed> $arguments whatever `@can`/`Gate::allows` was handed
     */
    public function __invoke(User $user, Permission $permission, array $arguments = []): bool
    {
        $record = $this->record($arguments);
        $workspace = $this->workspaceFor($arguments, $record);

        if ($workspace === null) {
            return false;
        }

        // No record to judge: this is "may this role, somewhere in this workspace", which is
        // the question a menu item is asking.
        if ($record === null) {
            return $this->permitsSomewhere($user, $workspace, $permission);
        }

        return $this->permits(
            $user,
            $record instanceof Workspace ? $record : $record->getAttribute('workspace_id'),
            $permission,
            fn (): ?int => $this->projectIdOf($record),
        );
    }

    /**
     * The first model argument that is not the workspace itself — the thing being judged.
     *
     * @param array<int, mixed> $arguments
     */
    private function record(array $arguments): ?Model
    {
        foreach ($arguments as $argument) {
            if ($argument instanceof Model && ! $argument instanceof Workspace && $argument->exists) {
                return $argument;
            }
        }

        return null;
    }

    /**
     * The tenant the question concerns: an explicit Workspace argument, then the record's
     * own `workspace_id`, then the workspace bound for this request.
     *
     * @param array<int, mixed> $arguments
     */
    private function workspaceFor(array $arguments, ?Model $record): Workspace|int|null
    {
        foreach ($arguments as $argument) {
            if ($argument instanceof Workspace && $argument->exists) {
                return $argument;
            }
        }

        if ($record instanceof Project) {
            return $this->contextWorkspace($record);
        }

        if ($record !== null) {
            $workspaceId = $record->getAttribute('workspace_id');

            if (is_numeric($workspaceId) && (int) $workspaceId > 0) {
                return (int) $workspaceId;
            }
        }

        return $this->currentWorkspace();
    }
}

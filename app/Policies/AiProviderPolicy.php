<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AiProvider;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceAccess;

/**
 * Providers are install-wide: one row, one API key, every tenant. There is no `workspace_id`
 * to check membership against, so the tenancy question does not arise — the question is who
 * on the install may hold the credential.
 *
 * Anyone administering AI in the workspace they are browsing may see *which* provider is
 * configured (`api_key` is encrypted and hidden from serialisation), because that is what
 * makes the model and limits legible. Creating, editing, deleting or dialling out with the
 * credential is platform administration: it happens in `/admin`, where no workspace is bound
 * and `Gate::before` admits a platform admin.
 */
final class AiProviderPolicy
{
    use ChecksWorkspaceAccess;

    public function viewAny(User $user): bool
    {
        return $this->permitsSomewhere($user, $this->currentWorkspace(), Permission::AiManage);
    }

    public function view(User $user, AiProvider $provider): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AiProvider $provider): bool
    {
        return false;
    }

    public function delete(User $user, AiProvider $provider): bool
    {
        return false;
    }

    /**
     * A connection test spends the credential against a remote endpoint, so it is a write in
     * everything but name.
     */
    public function test(User $user, AiProvider $provider): bool
    {
        return false;
    }

    public function setDefault(User $user, AiProvider $provider): bool
    {
        return false;
    }
}

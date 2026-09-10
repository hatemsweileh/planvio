<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;
use App\Policies\Concerns\ChecksWorkspaceAccess;

/**
 * A webhook carries a signing secret and pushes workspace data to a third party, so every
 * method — reading included — sits behind `webhooks.manage`.
 */
final class WebhookPolicy
{
    use ChecksWorkspaceAccess;

    public function viewAny(User $user, Workspace|Project|null $context = null): bool
    {
        return $this->permitsSomewhere(
            $user,
            $this->contextWorkspace($context),
            Permission::WebhooksManage,
        );
    }

    public function view(User $user, Webhook $webhook): bool
    {
        return $this->permits($user, $webhook->workspace_id, Permission::WebhooksManage);
    }

    public function create(User $user, Workspace|Project|null $context = null): bool
    {
        return $this->permitsSomewhere(
            $user,
            $this->contextWorkspace($context),
            Permission::WebhooksManage,
        );
    }

    public function update(User $user, Webhook $webhook): bool
    {
        return $this->view($user, $webhook);
    }

    public function delete(User $user, Webhook $webhook): bool
    {
        return $this->view($user, $webhook);
    }

    public function test(User $user, Webhook $webhook): bool
    {
        return $this->view($user, $webhook);
    }

    public function viewDeliveries(User $user, Webhook $webhook): bool
    {
        return $this->view($user, $webhook);
    }

    public function rotateSecret(User $user, Webhook $webhook): bool
    {
        return $this->view($user, $webhook);
    }
}

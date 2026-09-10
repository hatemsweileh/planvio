<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which workspace roles must hold a second factor, and who that currently catches.
 *
 * There are two sources and they are not alternatives.
 *
 * ## `config/planvio.php` is the floor
 *
 * `security.two_factor.required_for_roles` is set by whoever controls the filesystem — the
 * person who installed Planvio. It applies to every workspace and it cannot be unticked
 * from a screen inside the product: a workspace administrator turning off a policy the
 * server's owner set would be a downgrade granted to the wrong person.
 *
 * ## The settings row is per workspace, and can only add
 *
 * The screen in workspace settings writes its own list to the `settings` table, keyed by
 * workspace id, and the effective requirement is the **union** of the two. A workspace can
 * therefore be stricter than the installation, never looser.
 *
 * Per workspace rather than installation-wide because the screen sits behind
 * `workspace.manage`, which is held by the owner and administrators *of one tenant*. A
 * global key edited from there would let the owner of one workspace force every member of
 * every other workspace to enrol — a cross-tenant effect from a tenant-scoped screen, which
 * is the shape of bug ARCHITECTURE.md §3 exists to prevent.
 *
 * Platform super-admins are governed separately by
 * `security.two_factor.required_for_platform_admins`, because they are not workspace
 * members and no workspace's settings should be able to reach them.
 */
final class TwoFactorRequirement
{
    private const SETTING_PREFIX = 'security.two_factor.required_for_roles.workspace.';

    public function __construct(private readonly Settings $settings) {}

    /**
     * The setting key holding one workspace's own list.
     */
    public static function settingKey(Workspace|int $workspace): string
    {
        return self::SETTING_PREFIX.($workspace instanceof Workspace ? $workspace->getKey() : $workspace);
    }

    /**
     * Roles the installation requires everywhere, from `config/planvio.php`.
     *
     * @return list<string>
     */
    public function configuredRoles(): array
    {
        return self::clean(config('planvio.security.two_factor.required_for_roles', []));
    }

    /**
     * Roles this workspace has added on top, from the settings table.
     *
     * @return list<string>
     */
    public function storedRoles(Workspace|int $workspace): array
    {
        return self::clean($this->settings->get(self::settingKey($workspace)));
    }

    /**
     * What is actually enforced in this workspace: the union, in role order.
     *
     * @return list<string>
     */
    public function rolesFor(Workspace|int $workspace): array
    {
        $required = array_merge($this->configuredRoles(), $this->storedRoles($workspace));

        return self::inRoleOrder($required);
    }

    /**
     * Replace this workspace's own list.
     *
     * Roles the installation already requires are dropped before writing rather than stored
     * again: the row then says what the workspace chose, and a later change to
     * `config/planvio.php` is not silently pinned by a copy of the old value.
     *
     * @param list<string> $roles
     */
    public function store(Workspace $workspace, array $roles): void
    {
        $chosen = array_values(array_diff(self::clean($roles), $this->configuredRoles()));

        $this->settings->set(self::settingKey($workspace), self::inRoleOrder($chosen));
    }

    /**
     * Whether this installation makes a second factor mandatory for this account.
     *
     * With a workspace bound the question is about the role held *there*. Without one — the
     * profile screens, the workspace picker, anything before a tenant is resolved — any
     * membership carrying a role that workspace requires is enough, because the credential
     * being protected is the account, not the page.
     */
    public function appliesTo(User $user, ?Workspace $workspace = null): bool
    {
        if (! filter_var(config('planvio.security.two_factor.enabled', true), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        if ($user->isPlatformAdmin()
            && filter_var(config('planvio.security.two_factor.required_for_platform_admins', false), FILTER_VALIDATE_BOOL)) {
            return true;
        }

        if ($workspace instanceof Workspace) {
            // Resolved before the membership is looked up, because this runs on every
            // request and an installation that requires nothing must not pay a query for
            // the answer. `rolesFor()` is a memoised settings read; `roleIn()` is not
            // always one.
            $roles = $this->rolesFor($workspace);

            if ($roles === []) {
                return false;
            }

            $role = $user->roleIn($workspace)?->value;

            return $role !== null && in_array($role, $roles, true);
        }

        return $this->anyMembershipRequiresIt($user);
    }

    /**
     * How many people in this workspace would be held at enrolment if $roles were saved.
     *
     * Counted against the roles as they are actually held today and against who has already
     * enrolled, because that is the question the person about to save is asking: not "how
     * many owners are there" but "how many colleagues am I about to lock out of their
     * morning until they find their phone".
     *
     * @param list<string> $roles
     */
    public function membersWithoutTwoFactor(Workspace $workspace, array $roles): int
    {
        $roles = self::clean($roles);

        if ($roles === []) {
            return 0;
        }

        return WorkspaceMember::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('role', $roles)
            ->whereHas('user', static function (Builder $query): void {
                // Nested so the OR cannot escape the relation's own join condition.
                $query->where(static function (Builder $inner): void {
                    $inner->whereNull('two_factor_confirmed_at')->orWhereNull('two_factor_secret');
                });
            })
            ->count();
    }

    /**
     * Every membership this person holds, checked against the requirement of the workspace
     * it is in.
     *
     * The rows are read in one query and the requirement is resolved per workspace from
     * {@see Settings}, which memoises for the request — a person belongs to a handful of
     * workspaces, not a hundred.
     */
    private function anyMembershipRequiresIt(User $user): bool
    {
        $memberships = WorkspaceMember::withoutWorkspaceScope()
            ->where('user_id', $user->getKey())
            ->get(['workspace_id', 'role']);

        $configured = $this->configuredRoles();

        foreach ($memberships as $membership) {
            $role = $membership->role instanceof WorkspaceRole
                ? $membership->role->value
                : (string) $membership->role;

            // The installation-wide floor is free to check and answers before any settings
            // read is needed.
            if (in_array($role, $configured, true)) {
                return true;
            }

            if (in_array($role, $this->storedRoles((int) $membership->workspace_id), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep only real `WorkspaceRole` values, de-duplicated.
     *
     * Both sources are edited by hand — one in a config file, one through a form — and a
     * value that is not a role would silently never match anybody, which reads as "the
     * policy is off" rather than as "the policy is misspelt".
     *
     * @return list<string>
     */
    private static function clean(mixed $roles): array
    {
        if (! is_array($roles)) {
            return [];
        }

        $valid = [];

        foreach ($roles as $role) {
            if (is_string($role) && WorkspaceRole::tryFrom($role) instanceof WorkspaceRole) {
                $valid[$role] = true;
            }
        }

        return array_keys($valid);
    }

    /**
     * @param list<string> $roles
     * @return list<string>
     */
    private static function inRoleOrder(array $roles): array
    {
        $ordered = [];

        foreach (WorkspaceRole::cases() as $case) {
            if (in_array($case->value, $roles, true)) {
                $ordered[] = $case->value;
            }
        }

        return $ordered;
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Permission;
use App\Enums\WorkspaceRole;

/**
 * The capability matrix from ARCHITECTURE.md section 4.2 — static, no database.
 *
 * A cell holds one of:
 *   'Y'    granted unconditionally within the workspace
 *   '+'    only within projects where the user is ProjectRole::manager
 *   '*'    only within projects the guest is explicitly a member of
 *   '~'    only tasks the user is assignee or reporter of
 *   'own'  only records the user owns (uploaded/created)
 *   ''     not granted
 *
 * Conditional cells are the upper bound of what a policy may allow: the policy still has to
 * resolve the project membership, task ownership or record ownership that refines them.
 */
final class Permissions
{
    private const ALWAYS = 'Y';

    private const PROJECT_MANAGER = '+';

    private const GUEST_PROJECT = '*';

    private const OWN_TASKS = '~';

    private const OWN_RECORDS = 'own';

    private const NONE = '';

    /** Column order of every MATRIX row. */
    private const COLUMN = [
        'owner' => 0,
        'admin' => 1,
        'manager' => 2,
        'member' => 3,
        'guest' => 4,
    ];

    /** @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}> */
    private const MATRIX = [
        // owner, admin, manager, member, guest
        'workspace.view' => ['Y', 'Y', 'Y', 'Y', 'Y'],
        'workspace.manage' => ['Y', 'Y', '', '', ''],
        'workspace.delete' => ['Y', '', '', '', ''],

        'project.view' => ['Y', 'Y', 'Y', 'Y', '*'],
        'project.create' => ['Y', 'Y', 'Y', '', ''],
        'project.update' => ['Y', 'Y', '+', '', ''],
        'project.delete' => ['Y', 'Y', '', '', ''],
        'project.archive' => ['Y', 'Y', '+', '', ''],
        'project.manage_members' => ['Y', 'Y', '+', '', ''],

        'task.view' => ['Y', 'Y', 'Y', 'Y', '*'],
        'task.create' => ['Y', 'Y', 'Y', 'Y', ''],
        'task.update' => ['Y', 'Y', 'Y', '~', ''],
        'task.delete' => ['Y', 'Y', '+', '', ''],
        'task.assign' => ['Y', 'Y', 'Y', '', ''],
        'task.comment' => ['Y', 'Y', 'Y', 'Y', '*'],

        'milestone.view' => ['Y', 'Y', 'Y', 'Y', '*'],
        'milestone.manage' => ['Y', 'Y', '+', '', ''],

        'time.log' => ['Y', 'Y', 'Y', 'Y', ''],
        'time.view_all' => ['Y', 'Y', '+', '', ''],

        'budget.view' => ['Y', 'Y', '+', '', ''],
        'budget.manage' => ['Y', 'Y', '', '', ''],

        'wiki.view' => ['Y', 'Y', 'Y', 'Y', '*'],
        'wiki.manage' => ['Y', 'Y', 'Y', 'Y', ''],

        'attachment.upload' => ['Y', 'Y', 'Y', 'Y', '*'],
        'attachment.delete' => ['Y', 'Y', '+', 'own', 'own'],

        'reports.view' => ['Y', 'Y', 'Y', 'Y', ''],

        'settings.manage' => ['Y', 'Y', '', '', ''],
        'users.manage' => ['Y', 'Y', '', '', ''],
        'webhooks.manage' => ['Y', 'Y', '', '', ''],
        'templates.manage' => ['Y', 'Y', 'Y', '', ''],

        'ai.use' => ['Y', 'Y', 'Y', 'Y', ''],
        'ai.manage' => ['Y', 'Y', '', '', ''],
        'ai.autonomous' => ['Y', 'Y', '', '', ''],
        'ai.manage_policies' => ['Y', 'Y', '', '', ''],
        'ai.view_logs' => ['Y', 'Y', '+', '', ''],
        'ai.approve' => ['Y', 'Y', '+', '', ''],
    ];

    /** @var array<string, list<Permission>> */
    private static array $memo = [];

    private function __construct() {}

    /**
     * Permissions the role holds unconditionally anywhere in the workspace.
     *
     * @return list<Permission>
     */
    public static function for(WorkspaceRole $role): array
    {
        return self::collect($role, [self::ALWAYS]);
    }

    /**
     * Permissions the role holds only inside certain projects — '+' for managers, '*' for
     * guests. Never overlaps with the unconditional set.
     *
     * @return list<Permission>
     */
    public static function projectScoped(WorkspaceRole $role): array
    {
        return self::collect($role, [self::PROJECT_MANAGER, self::GUEST_PROJECT]);
    }

    /**
     * Whether the role can ever hold the permission — unconditionally or under a refinement.
     */
    public static function has(WorkspaceRole $role, Permission $permission): bool
    {
        return self::marker($role, $permission) !== self::NONE;
    }

    public static function requiresProjectScope(WorkspaceRole $role, Permission $permission): bool
    {
        return in_array(
            self::marker($role, $permission),
            [self::PROJECT_MANAGER, self::GUEST_PROJECT],
            true,
        );
    }

    /**
     * Whether the grant is narrowed to the user's own records — '~' tasks they are assignee
     * or reporter of, 'own' records they uploaded or created.
     */
    public static function ownOnly(WorkspaceRole $role, Permission $permission): bool
    {
        return in_array(
            self::marker($role, $permission),
            [self::OWN_TASKS, self::OWN_RECORDS],
            true,
        );
    }

    /**
     * @return list<Permission>
     */
    public static function all(): array
    {
        return Permission::cases();
    }

    /**
     * @param non-empty-list<string> $markers
     * @return list<Permission>
     */
    private static function collect(WorkspaceRole $role, array $markers): array
    {
        $column = self::COLUMN[$role->value] ?? null;

        if ($column === null) {
            return [];
        }

        $cacheKey = $role->value.'|'.implode(',', $markers);

        if (isset(self::$memo[$cacheKey])) {
            return self::$memo[$cacheKey];
        }

        $permissions = [];

        foreach (self::MATRIX as $permission => $row) {
            if (in_array($row[$column], $markers, true)) {
                $permissions[] = Permission::from($permission);
            }
        }

        return self::$memo[$cacheKey] = $permissions;
    }

    private static function marker(WorkspaceRole $role, Permission $permission): string
    {
        $column = self::COLUMN[$role->value] ?? null;

        if ($column === null) {
            return self::NONE;
        }

        return self::MATRIX[$permission->value][$column] ?? self::NONE;
    }
}

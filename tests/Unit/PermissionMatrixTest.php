<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Permission;
use App\Enums\WorkspaceRole;
use App\Support\Permissions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The capability matrix, transcribed from ARCHITECTURE.md §4.2 rather than from the code it
 * checks. Two independent copies of the same table is the entire point: a typo in one of
 * them shows up here as a failure instead of shipping as a quietly widened grant.
 *
 * Cell markers, as the contract defines them:
 *   'Y'    granted unconditionally inside the workspace
 *   '+'    only in projects where the user is ProjectRole::manager
 *   '*'    only in projects the guest is explicitly a member of
 *   '~'    only tasks the user is assignee or reporter of
 *   'own'  only records the user uploaded or created
 *   ''     not granted
 */
final class PermissionMatrixTest extends TestCase
{
    private const OWNER = 0;

    private const ADMIN = 1;

    private const MANAGER = 2;

    private const MEMBER = 3;

    private const GUEST = 4;

    /** @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}> */
    private const CONTRACT = [
        //                            owner  admin  manager member guest
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

    /** @var array<string, int> */
    private const COLUMN = [
        'owner' => self::OWNER,
        'admin' => self::ADMIN,
        'manager' => self::MANAGER,
        'member' => self::MEMBER,
        'guest' => self::GUEST,
    ];

    /* ------------------------------------------------------------------ *
     * Completeness — the table and the enum must describe the same world
     * ------------------------------------------------------------------ */

    public function test_the_contract_covers_every_permission_the_enum_declares(): void
    {
        foreach (Permission::cases() as $permission) {
            $this->assertArrayHasKey(
                $permission->value,
                self::CONTRACT,
                "Permission `{$permission->value}` exists but ARCHITECTURE.md §4.2 has no row for it. "
                .'A permission with no matrix row has no defined answer for any role.',
            );
        }
    }

    public function test_the_contract_declares_no_permission_the_enum_does_not_have(): void
    {
        $declared = array_map(
            static fn (Permission $case): string => $case->value,
            Permission::cases(),
        );

        foreach (array_keys(self::CONTRACT) as $permission) {
            $this->assertContains(
                $permission,
                $declared,
                "The matrix has a row for `{$permission}`, which is not a Permission case.",
            );
        }
    }

    public function test_every_workspace_role_has_a_column(): void
    {
        foreach (WorkspaceRole::cases() as $role) {
            $this->assertArrayHasKey(
                $role->value,
                self::COLUMN,
                "WorkspaceRole `{$role->value}` has no column in the matrix.",
            );
        }
    }

    /* ------------------------------------------------------------------ *
     * The matrix itself, cell by cell
     * ------------------------------------------------------------------ */

    #[DataProvider('cells')]
    public function test_has_matches_the_contract(WorkspaceRole $role, Permission $permission, string $marker): void
    {
        $this->assertSame(
            $marker !== '',
            Permissions::has($role, $permission),
            $this->describe($role, $permission, $marker, 'Permissions::has()'),
        );
    }

    #[DataProvider('cells')]
    public function test_requires_project_scope_matches_the_contract(
        WorkspaceRole $role,
        Permission $permission,
        string $marker,
    ): void {
        $this->assertSame(
            in_array($marker, ['+', '*'], true),
            Permissions::requiresProjectScope($role, $permission),
            $this->describe($role, $permission, $marker, 'Permissions::requiresProjectScope()'),
        );
    }

    #[DataProvider('cells')]
    public function test_own_only_matches_the_contract(
        WorkspaceRole $role,
        Permission $permission,
        string $marker,
    ): void {
        $this->assertSame(
            in_array($marker, ['~', 'own'], true),
            Permissions::ownOnly($role, $permission),
            $this->describe($role, $permission, $marker, 'Permissions::ownOnly()'),
        );
    }

    /* ------------------------------------------------------------------ *
     * The derived sets
     * ------------------------------------------------------------------ */

    #[DataProvider('roles')]
    public function test_unconditional_grants_match_the_contract(WorkspaceRole $role): void
    {
        $this->assertSame(
            $this->expected($role, ['Y']),
            $this->values(Permissions::for($role)),
            "Permissions::for({$role->value}) does not match the `Y` cells of the contract.",
        );
    }

    #[DataProvider('roles')]
    public function test_project_scoped_grants_match_the_contract(WorkspaceRole $role): void
    {
        $this->assertSame(
            $this->expected($role, ['+', '*']),
            $this->values(Permissions::projectScoped($role)),
            "Permissions::projectScoped({$role->value}) does not match the `+`/`*` cells of the contract.",
        );
    }

    #[DataProvider('roles')]
    public function test_unconditional_and_project_scoped_sets_never_overlap(WorkspaceRole $role): void
    {
        $this->assertSame(
            [],
            array_values(array_intersect(
                $this->values(Permissions::for($role)),
                $this->values(Permissions::projectScoped($role)),
            )),
            "A permission cannot be both unconditional and project-scoped for {$role->value}.",
        );
    }

    public function test_all_returns_every_declared_permission(): void
    {
        $this->assertSame(Permission::cases(), Permissions::all());
    }

    /* ------------------------------------------------------------------ *
     * Structural invariants the policy layer depends on
     *
     * ChecksWorkspaceAccess tells `+` from `*` by the acting role, because the
     * contract puts each marker in exactly one column. If that ever stops being
     * true the refinement silently starts answering the wrong question, so it is
     * asserted here rather than assumed.
     * ------------------------------------------------------------------ */

    public function test_the_project_manager_marker_appears_only_in_the_manager_column(): void
    {
        foreach (self::CONTRACT as $permission => $row) {
            foreach ($row as $column => $marker) {
                if ($marker !== '+') {
                    continue;
                }

                $this->assertSame(
                    self::MANAGER,
                    $column,
                    "`+` on {$permission} sits outside the manager column. "
                    .'ChecksWorkspaceAccess distinguishes `+` from `*` by the acting role and would misread it.',
                );
            }
        }
    }

    public function test_the_guest_marker_appears_only_in_the_guest_column(): void
    {
        foreach (self::CONTRACT as $permission => $row) {
            foreach ($row as $column => $marker) {
                if ($marker !== '*') {
                    continue;
                }

                $this->assertSame(
                    self::GUEST,
                    $column,
                    "`*` on {$permission} sits outside the guest column. "
                    .'ChecksWorkspaceAccess would refine it as a project-manager grant.',
                );
            }
        }
    }

    public function test_the_own_task_marker_is_used_only_where_a_task_can_be_owned(): void
    {
        foreach (self::CONTRACT as $permission => $row) {
            if (! in_array('~', $row, true)) {
                continue;
            }

            $this->assertStringStartsWith(
                'task.',
                $permission,
                "`~` means assignee-or-reporter, which only a task has; {$permission} is not a task permission.",
            );
        }
    }

    /* ------------------------------------------------------------------ *
     * Sanity checks on the shape of the roles
     * ------------------------------------------------------------------ */

    public function test_the_owner_holds_every_permission(): void
    {
        foreach (Permission::cases() as $permission) {
            $this->assertTrue(
                Permissions::has(WorkspaceRole::Owner, $permission),
                "The owner must hold {$permission->value}.",
            );
        }
    }

    public function test_only_the_owner_may_delete_the_workspace(): void
    {
        foreach (WorkspaceRole::cases() as $role) {
            $this->assertSame(
                $role === WorkspaceRole::Owner,
                Permissions::has($role, Permission::WorkspaceDelete),
                "workspace.delete is the owner's alone; {$role->value} disagrees.",
            );
        }
    }

    public function test_a_guest_holds_nothing_unconditionally_beyond_seeing_the_workspace(): void
    {
        $this->assertSame(
            [Permission::WorkspaceView->value],
            $this->values(Permissions::for(WorkspaceRole::Guest)),
            'A guest may only be told the workspace exists; everything else they reach is project-scoped.',
        );
    }

    public function test_a_guest_can_never_write_outside_a_project(): void
    {
        foreach (Permissions::for(WorkspaceRole::Guest) as $permission) {
            $this->assertFalse(
                str_contains($permission->value, 'manage')
                || str_contains($permission->value, 'create')
                || str_contains($permission->value, 'update')
                || str_contains($permission->value, 'delete'),
                "A guest holds {$permission->value} unconditionally, which is a write outside any project.",
            );
        }
    }

    public function test_platform_configuration_stops_at_owner_and_admin(): void
    {
        $restricted = [
            Permission::SettingsManage,
            Permission::UsersManage,
            Permission::WebhooksManage,
            Permission::BudgetManage,
            Permission::AiManage,
            Permission::AiAutonomous,
            Permission::AiManagePolicies,
        ];

        foreach ($restricted as $permission) {
            foreach ([WorkspaceRole::Manager, WorkspaceRole::Member, WorkspaceRole::Guest] as $role) {
                $this->assertFalse(
                    Permissions::has($role, $permission),
                    "{$role->value} must not hold {$permission->value} in any form.",
                );
            }
        }
    }

    /* ------------------------------------------------------------------ *
     * Providers and helpers
     * ------------------------------------------------------------------ */

    /**
     * @return iterable<string, array{0: WorkspaceRole, 1: Permission, 2: string}>
     */
    public static function cells(): iterable
    {
        foreach (self::CONTRACT as $permission => $row) {
            foreach (self::COLUMN as $role => $column) {
                $marker = $row[$column];
                $label = $marker === '' ? 'denied' : $marker;

                yield "{$role} / {$permission} ({$label})" => [
                    WorkspaceRole::from($role),
                    Permission::from($permission),
                    $marker,
                ];
            }
        }
    }

    /**
     * @return iterable<string, array{0: WorkspaceRole}>
     */
    public static function roles(): iterable
    {
        foreach (WorkspaceRole::cases() as $role) {
            yield $role->value => [$role];
        }
    }

    /**
     * The permission values the contract gives $role under any of $markers, in matrix order.
     *
     * @param non-empty-list<string> $markers
     * @return list<string>
     */
    private function expected(WorkspaceRole $role, array $markers): array
    {
        $column = self::COLUMN[$role->value];
        $permissions = [];

        foreach (self::CONTRACT as $permission => $row) {
            if (in_array($row[$column], $markers, true)) {
                $permissions[] = $permission;
            }
        }

        return $permissions;
    }

    /**
     * @param list<Permission> $permissions
     * @return list<string>
     */
    private function values(array $permissions): array
    {
        return array_map(static fn (Permission $permission): string => $permission->value, $permissions);
    }

    private function describe(
        WorkspaceRole $role,
        Permission $permission,
        string $marker,
        string $method,
    ): string {
        $cell = $marker === '' ? 'no grant' : "`{$marker}`";

        return "{$method} disagrees with ARCHITECTURE.md §4.2: "
            ."the {$role->value} column of {$permission->value} is {$cell}.";
    }
}

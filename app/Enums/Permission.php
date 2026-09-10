<?php

declare(strict_types=1);

namespace App\Enums;

enum Permission: string
{
    case WorkspaceView = 'workspace.view';
    case WorkspaceManage = 'workspace.manage';
    case WorkspaceDelete = 'workspace.delete';

    case ProjectView = 'project.view';
    case ProjectCreate = 'project.create';
    case ProjectUpdate = 'project.update';
    case ProjectDelete = 'project.delete';
    case ProjectArchive = 'project.archive';
    case ProjectManageMembers = 'project.manage_members';

    case TaskView = 'task.view';
    case TaskCreate = 'task.create';
    case TaskUpdate = 'task.update';
    case TaskDelete = 'task.delete';
    case TaskAssign = 'task.assign';
    case TaskComment = 'task.comment';

    case MilestoneView = 'milestone.view';
    case MilestoneManage = 'milestone.manage';

    case TimeLog = 'time.log';
    case TimeViewAll = 'time.view_all';

    case BudgetView = 'budget.view';
    case BudgetManage = 'budget.manage';

    case WikiView = 'wiki.view';
    case WikiManage = 'wiki.manage';

    case AttachmentUpload = 'attachment.upload';
    case AttachmentDelete = 'attachment.delete';

    case ReportsView = 'reports.view';

    case SettingsManage = 'settings.manage';

    case UsersManage = 'users.manage';

    case WebhooksManage = 'webhooks.manage';

    case TemplatesManage = 'templates.manage';

    case AiUse = 'ai.use';
    case AiManage = 'ai.manage';
    case AiAutonomous = 'ai.autonomous';
    case AiManagePolicies = 'ai.manage_policies';
    case AiViewLogs = 'ai.view_logs';
    case AiApprove = 'ai.approve';

    public function label(): string
    {
        return __('enums.permission.'.$this->value);
    }

    /**
     * The segment before the dot, e.g. `project.update` yields `project`.
     */
    public function group(): string
    {
        return explode('.', $this->value, 2)[0];
    }

    public function groupLabel(): string
    {
        return __('enums.permission_group.'.$this->group());
    }

    /**
     * Distinct group keys in declaration order.
     *
     * @return array<int, string>
     */
    public static function groups(): array
    {
        $groups = [];

        foreach (self::cases() as $case) {
            $group = $case->group();

            if (! in_array($group, $groups, true)) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /**
     * @return array<int, self>
     */
    public static function inGroup(string $group): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $case): bool => $case->group() === $group,
        ));
    }

    /**
     * Options nested by group label, for grouped form selects.
     *
     * @return array<string, array<string, string>>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::cases() as $case) {
            $grouped[$case->groupLabel()][$case->value] = $case->label();
        }

        return $grouped;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

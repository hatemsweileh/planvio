<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\Permission;
use App\Models\Activity;
use App\Models\AiAutomation;
use App\Models\AiConversation;
use App\Models\AiPolicy;
use App\Models\AiProvider;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\AiToolRun;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\CustomField;
use App\Models\Expense;
use App\Models\Invitation;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ProjectTemplate;
use App\Models\RecurringTask;
use App\Models\SavedView;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\TaskStatus;
use App\Models\Team;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WikiPage;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Policies\ActivityPolicy;
use App\Policies\AiAutomationPolicy;
use App\Policies\AiConversationPolicy;
use App\Policies\AiPolicyPolicy;
use App\Policies\AiProviderPolicy;
use App\Policies\AiRunPolicy;
use App\Policies\AiSettingPolicy;
use App\Policies\AiToolRunPolicy;
use App\Policies\AttachmentPolicy;
use App\Policies\CommentPolicy;
use App\Policies\Concerns\ChecksWorkspaceAccess;
use App\Policies\CustomFieldPolicy;
use App\Policies\ExpensePolicy;
use App\Policies\InvitationPolicy;
use App\Policies\MilestonePolicy;
use App\Policies\PermissionGate;
use App\Policies\ProjectPolicy;
use App\Policies\ProjectStatusPolicy;
use App\Policies\ProjectTemplatePolicy;
use App\Policies\RecurringTaskPolicy;
use App\Policies\SavedViewPolicy;
use App\Policies\TagPolicy;
use App\Policies\TaskDependencyPolicy;
use App\Policies\TaskPolicy;
use App\Policies\TaskStatusPolicy;
use App\Policies\TeamPolicy;
use App\Policies\TimeEntryPolicy;
use App\Policies\WebhookPolicy;
use App\Policies\WikiPagePolicy;
use App\Policies\WorkspaceMemberPolicy;
use App\Policies\WorkspacePolicy;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy map. Registered explicitly rather than left to Laravel's convention-based
     * discovery: a model silently losing its policy is the kind of failure that looks like
     * working software right up until it leaks a workspace.
     *
     * Models absent from this map deliberately have no policy of their own — a project
     * membership, a checklist item, a comment reaction or an AI message is authorized as a
     * change to the record it hangs off, through that record's policy.
     *
     * @var array<class-string<Model>, class-string>
     */
    private const POLICIES = [
        Workspace::class => WorkspacePolicy::class,
        WorkspaceMember::class => WorkspaceMemberPolicy::class,
        Team::class => TeamPolicy::class,
        Invitation::class => InvitationPolicy::class,

        Project::class => ProjectPolicy::class,
        ProjectStatus::class => ProjectStatusPolicy::class,
        Milestone::class => MilestonePolicy::class,

        Task::class => TaskPolicy::class,
        TaskStatus::class => TaskStatusPolicy::class,
        TaskDependency::class => TaskDependencyPolicy::class,
        RecurringTask::class => RecurringTaskPolicy::class,

        Comment::class => CommentPolicy::class,
        Attachment::class => AttachmentPolicy::class,
        Activity::class => ActivityPolicy::class,

        TimeEntry::class => TimeEntryPolicy::class,
        Expense::class => ExpensePolicy::class,

        WikiPage::class => WikiPagePolicy::class,
        SavedView::class => SavedViewPolicy::class,
        CustomField::class => CustomFieldPolicy::class,
        Tag::class => TagPolicy::class,
        ProjectTemplate::class => ProjectTemplatePolicy::class,

        Webhook::class => WebhookPolicy::class,

        AiConversation::class => AiConversationPolicy::class,
        AiRun::class => AiRunPolicy::class,
        AiToolRun::class => AiToolRunPolicy::class,
        AiPolicy::class => AiPolicyPolicy::class,
        AiSetting::class => AiSettingPolicy::class,
        AiProvider::class => AiProviderPolicy::class,
        AiAutomation::class => AiAutomationPolicy::class,
    ];

    public function register(): void
    {
        // The tenant binding has to be one object per request or job for `set()` and
        // `runFor()` to mean anything — and every policy decision below reads it. Bound
        // here because authorization is what depends on it; re-binding it from another
        // provider is harmless, since nothing resolves it before boot.
        $this->app->singleton(CurrentWorkspace::class);
    }

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->registerPlatformAdminBypass();
        $this->registerPermissionAbilities();
    }

    /**
     * @return array<class-string<Model>, class-string>
     */
    public static function policies(): array
    {
        return self::POLICIES;
    }

    /**
     * The capability matrix, as named abilities — `@can('ai.use', $workspace)`.
     *
     * The product chrome asks capability questions that no policy method answers: whether to
     * show the AI item in the sidebar, whether the create menu offers "Project", whether the
     * command palette lists "Invite member". Those are questions about a *role*, not about a
     * record, and inventing a policy method for each of them would scatter the matrix across
     * thirty classes.
     *
     * The semantics are deliberately the same as {@see ChecksWorkspaceAccess}:
     *
     *   - given a record, the full three-step check runs, refinements included — a manager's
     *     `+` cell still requires ProjectRole::manager in that project;
     *   - given only a workspace (or nothing, falling back to the bound tenant), the answer
     *     is `permitsSomewhere`: could this role ever hold this permission here. That is what
     *     a menu item needs, and it is safe precisely because it never authorises a record —
     *     opening the thing behind the menu item still runs the record's policy.
     *
     * A class-string argument (`@can('project.create', [Project::class, $workspace])`) is
     * ignored for the purpose of finding the workspace: it carries no tenant.
     */
    private function registerPermissionAbilities(): void
    {
        foreach (Permission::cases() as $permission) {
            Gate::define(
                $permission->value,
                function (User $user, mixed ...$arguments) use ($permission): bool {
                    return (new PermissionGate)($user, $permission, $arguments);
                },
            );
        }
    }

    /**
     * The platform super-admin bypass, and the one place it is deliberately *not* a bypass.
     *
     * `users.is_admin` exists so somebody can run the installation: manage accounts and
     * workspaces, configure AI providers, read health and logs. It does not exist so that
     * person can read a customer's tasks. Being able to grant yourself access is not the
     * same as already having it, and an admin who opens a workspace they were never invited
     * to would leave no trace in `workspace_members` — the audit answer to "who could see
     * this" would be wrong for everyone looking at it afterwards.
     *
     * So the bypass is conditional on there being no tenant in play:
     *
     *   - no workspace context at all (the Filament panel, the console, an unbound job) —
     *     the admin is administering the platform, and the bypass applies;
     *   - a workspace context in which the admin *is* a member — they are an ordinary
     *     member with extra platform rights, and the bypass applies;
     *   - a workspace context in which they are not a member — the bypass returns null, not
     *     false: it declines to answer and lets the policy refuse on its own terms. An
     *     abstention leaves any future non-tenant ability free to allow the admin through.
     *
     * The workspace in play is taken from the record being judged, not from whatever is
     * bound: the record is what would leak. Only when no argument carries one does it fall
     * back to the bound workspace, which is what makes `/admin` (where nothing is bound)
     * behave differently from the product UI (where something always is).
     */
    private function registerPlatformAdminBypass(): void
    {
        Gate::before(function (User $user, string $ability, array $arguments = []): ?bool {
            if (! $user->is_admin || ! $user->is_active) {
                return null;
            }

            $workspace = $this->workspaceContext($arguments);

            if ($workspace === null) {
                return true;
            }

            return $user->memberOf($workspace) ? true : null;
        });
    }

    /**
     * The workspace an authorization check concerns, or null when it concerns none.
     *
     * @param array<int, mixed> $arguments
     */
    private function workspaceContext(array $arguments): ?Workspace
    {
        foreach ($arguments as $argument) {
            if ($argument instanceof Workspace) {
                return $argument->exists ? $argument : null;
            }

            if (! $argument instanceof Model) {
                continue;
            }

            $workspaceId = $argument->getAttribute('workspace_id');

            // A null column is not "no opinion": on ProjectTemplate, AiPolicy and AiSetting
            // it marks an install-wide row that belongs to no tenant, so the search
            // continues to the bound workspace rather than granting the bypass outright.
            if ((is_int($workspaceId) || (is_string($workspaceId) && ctype_digit($workspaceId)))
                && (int) $workspaceId > 0) {
                return $this->workspaceReference((int) $workspaceId);
            }
        }

        return $this->app->make(CurrentWorkspace::class)->get();
    }

    /**
     * A key-only Workspace: `User::memberOf()` reads nothing but the primary key, and this
     * runs on every authorization check a platform admin makes.
     */
    private function workspaceReference(int $workspaceId): Workspace
    {
        $workspace = new Workspace;
        $workspace->setAttribute($workspace->getKeyName(), $workspaceId);
        $workspace->exists = true;

        return $workspace;
    }
}

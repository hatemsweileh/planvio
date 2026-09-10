<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\AuthorType;
use App\Enums\ProjectRole;
use App\Enums\WikiVisibility;
use App\Enums\WorkspaceRole;
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
use App\Models\ProjectMember;
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
use App\Providers\AuthServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Tests\TestCase;

/**
 * Cross-workspace access is a security bug (ARCHITECTURE.md §3), and the policy layer is the
 * layer that has to catch it on its own — without help from WorkspaceScope, which is inert
 * until something binds a workspace and can be escaped by any caller.
 *
 * So the suite reflects over every registered policy, finds every ability that takes a
 * record, and asserts that a fully-privileged member of one workspace is refused all of them
 * on the other workspace's records. Adding a policy method automatically adds coverage;
 * adding a model without a policy fails the census test at the bottom.
 */
final class PolicyEnforcementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Models whose policies are deliberately not tenant-scoped, with the reason. They are
     * asserted individually instead of in the sweep.
     *
     * @var array<class-string<Model>, string>
     */
    private const NOT_TENANT_SCOPED = [
        // The tenant root itself: `viewAny` and `create` are answered before any tenant
        // exists, and the record-level abilities are swept like everything else.
        Workspace::class => 'the tenant root',
        // Install-wide credentials with no workspace column at all.
        AiProvider::class => 'install-wide platform configuration',
    ];

    private Workspace $home;

    private Workspace $foreign;

    /** @var array<string, User> */
    private array $outsiders = [];

    private User $insider;

    /** @var array<class-string<Model>, Model> */
    private array $foreignRecords = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = $this->makeWorkspace(['name' => 'Home', 'slug' => 'home']);
        $this->foreign = $this->makeWorkspace(['name' => 'Foreign', 'slug' => 'foreign']);

        // Every role, so a failure names which one leaked. The owner is the interesting
        // case: the most privileged account in one tenant must be the least relevant in
        // another.
        foreach (WorkspaceRole::cases() as $role) {
            $this->outsiders[$role->value] = $this->makeMember($this->home, $role);
        }

        $this->insider = $this->makeMember($this->foreign, WorkspaceRole::Owner);
        $this->foreignRecords = $this->buildForeignRecords();
    }

    /* ------------------------------------------------------------------ *
     * The sweep
     * ------------------------------------------------------------------ */

    public function test_a_member_of_another_workspace_is_denied_every_record_ability(): void
    {
        $checked = 0;

        foreach ($this->tenantScopedPolicies() as $model => $policy) {
            $record = $this->foreignRecords[$model];

            foreach ($this->recordAbilities($policy, $model) as [$ability, $extra]) {
                foreach ($this->outsiders as $role => $outsider) {
                    $checked++;

                    $this->assertFalse(
                        Gate::forUser($outsider)->allows($ability, array_merge([$record], $extra)),
                        $this->breach($policy, $ability, $role, $model),
                    );
                }
            }
        }

        // A sweep that silently stopped finding abilities would pass without testing
        // anything, so the count itself is asserted.
        $this->assertGreaterThan(
            500,
            $checked,
            "Only {$checked} cross-workspace checks ran; the reflection sweep has stopped finding abilities.",
        );
    }

    public function test_a_stranger_who_belongs_to_no_workspace_is_denied_every_record_ability(): void
    {
        $stranger = User::factory()->create();

        foreach ($this->tenantScopedPolicies() as $model => $policy) {
            $record = $this->foreignRecords[$model];

            foreach ($this->recordAbilities($policy, $model) as [$ability, $extra]) {
                $this->assertFalse(
                    Gate::forUser($stranger)->allows($ability, array_merge([$record], $extra)),
                    $this->breach($policy, $ability, 'no membership anywhere', $model),
                );
            }
        }
    }

    public function test_the_workspace_record_itself_is_closed_to_outsiders(): void
    {
        foreach ($this->recordAbilities(AuthServiceProvider::policies()[Workspace::class], Workspace::class) as [$ability, $extra]) {
            foreach ($this->outsiders as $role => $outsider) {
                $this->assertFalse(
                    Gate::forUser($outsider)->allows($ability, array_merge([$this->foreign], $extra)),
                    "A {$role} of another workspace was allowed `{$ability}` on a foreign workspace record.",
                );
            }
        }
    }

    /* ------------------------------------------------------------------ *
     * Class-level abilities, with the foreign workspace bound
     *
     * The realistic attack is not a stray model instance, it is a URL:
     * /w/foreign/projects. The request middleware binds the workspace it was
     * given, so `viewAny` and `create` have to refuse on membership alone.
     * ------------------------------------------------------------------ */

    public function test_class_level_abilities_are_denied_while_a_foreign_workspace_is_bound(): void
    {
        $this->inWorkspace($this->foreign, function (): void {
            foreach ($this->tenantScopedPolicies() as $model => $policy) {
                foreach (['viewAny', 'create'] as $ability) {
                    if (! method_exists($policy, $ability)) {
                        continue;
                    }

                    foreach ($this->outsiders as $role => $outsider) {
                        $this->assertFalse(
                            Gate::forUser($outsider)->allows($ability, $model),
                            "A {$role} of another workspace was allowed `{$ability}` on {$model} "
                            .'while the foreign workspace was the bound tenant.',
                        );
                    }
                }
            }
        });
    }

    public function test_binding_a_foreign_workspace_does_not_grant_its_records(): void
    {
        $this->inWorkspace($this->foreign, function (): void {
            $owner = $this->outsiders[WorkspaceRole::Owner->value];

            $this->assertFalse(Gate::forUser($owner)->allows('view', $this->foreignRecords[Task::class]));
            $this->assertFalse(Gate::forUser($owner)->allows('view', $this->foreignRecords[Project::class]));
            $this->assertFalse(Gate::forUser($owner)->allows('view', $this->foreignRecords[WikiPage::class]));
        });
    }

    /* ------------------------------------------------------------------ *
     * The layers, checked one at a time
     * ------------------------------------------------------------------ */

    public function test_a_project_membership_without_a_workspace_membership_grants_nothing(): void
    {
        $outsider = $this->outsiders[WorkspaceRole::Member->value];

        // A stale row of exactly this shape is what step 1 of §4.3 exists to survive: the
        // user was in the project once, or the row was written directly. Project role is a
        // refinement of workspace membership, never a substitute for it.
        ProjectMember::query()->create([
            'project_id' => $this->foreignRecords[Project::class]->getKey(),
            'user_id' => $outsider->getKey(),
            'role' => ProjectRole::Manager,
        ]);

        $outsider->flushRoleCache();

        $this->assertFalse(Gate::forUser($outsider)->allows('view', $this->foreignRecords[Project::class]));
        $this->assertFalse(Gate::forUser($outsider)->allows('update', $this->foreignRecords[Project::class]));
        $this->assertFalse(Gate::forUser($outsider)->allows('view', $this->foreignRecords[Task::class]));
        $this->assertFalse(Gate::forUser($outsider)->allows('update', $this->foreignRecords[Task::class]));
        $this->assertFalse(Gate::forUser($outsider)->allows('viewBudget', $this->foreignRecords[Project::class]));
    }

    public function test_a_task_assignment_across_workspaces_grants_nothing(): void
    {
        $outsider = $this->outsiders[WorkspaceRole::Member->value];

        // `~` narrows a member's grant to their own tasks; it must never widen a
        // non-member's to somebody else's workspace.
        $task = $this->foreignRecords[Task::class];
        $task->forceFill([
            'assignee_id' => $outsider->getKey(),
            'reporter_id' => $outsider->getKey(),
        ])->save();

        $this->assertFalse(Gate::forUser($outsider)->allows('view', $task));
        $this->assertFalse(Gate::forUser($outsider)->allows('update', $task));
        $this->assertFalse(Gate::forUser($outsider)->allows('comment', $task));
    }

    public function test_owning_a_record_across_workspaces_grants_nothing(): void
    {
        $outsider = $this->outsiders[WorkspaceRole::Member->value];

        $attachment = $this->foreignRecords[Attachment::class];
        $attachment->forceFill(['uploaded_by' => $outsider->getKey()])->save();

        $comment = $this->foreignRecords[Comment::class];
        $comment->forceFill(['user_id' => $outsider->getKey()])->save();

        $this->assertFalse(Gate::forUser($outsider)->allows('view', $attachment));
        $this->assertFalse(Gate::forUser($outsider)->allows('download', $attachment));
        $this->assertFalse(Gate::forUser($outsider)->allows('delete', $attachment));
        $this->assertFalse(Gate::forUser($outsider)->allows('update', $comment));
        $this->assertFalse(Gate::forUser($outsider)->allows('delete', $comment));
    }

    public function test_a_deactivated_member_loses_every_ability_in_their_own_workspace(): void
    {
        $this->insider->forceFill(['is_active' => false])->save();
        $this->insider->flushRoleCache();

        foreach ($this->tenantScopedPolicies() as $model => $policy) {
            $record = $this->foreignRecords[$model];

            foreach ($this->recordAbilities($policy, $model) as [$ability, $extra]) {
                $this->assertFalse(
                    Gate::forUser($this->insider)->allows($ability, array_merge([$record], $extra)),
                    "A deactivated account was allowed `{$ability}` via {$policy}.",
                );
            }
        }
    }

    /* ------------------------------------------------------------------ *
     * The platform-admin bypass, and the line it does not cross
     * ------------------------------------------------------------------ */

    public function test_a_platform_admin_is_refused_inside_a_workspace_they_do_not_belong_to(): void
    {
        $admin = $this->makeMember($this->home, WorkspaceRole::Member, ['is_admin' => true]);

        foreach ($this->tenantScopedPolicies() as $model => $policy) {
            $record = $this->foreignRecords[$model];

            foreach ($this->recordAbilities($policy, $model) as [$ability, $extra]) {
                $this->assertFalse(
                    Gate::forUser($admin)->allows($ability, array_merge([$record], $extra)),
                    "A platform admin read another workspace's data through the product: "
                    ."`{$ability}` on {$model} was allowed. Gate::before must abstain outside their memberships.",
                );
            }
        }
    }

    public function test_a_platform_admin_who_is_a_member_keeps_the_bypass(): void
    {
        // A guest holds nothing but workspace.view, so anything beyond that can only come
        // from the bypass — which is exactly what should apply inside their own workspace.
        $admin = $this->makeMember($this->home, WorkspaceRole::Guest, ['is_admin' => true]);
        $project = $this->makeProject($this->home);

        $this->assertTrue(Gate::forUser($admin)->allows('update', $this->home));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $project));
    }

    public function test_a_platform_admin_keeps_the_bypass_where_no_workspace_is_in_play(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $provider = AiProvider::factory()->create();

        // Nothing bound, no workspace column on the record: this is /admin, and it must
        // keep working — the point of the rule is the product UI, not the panel.
        $this->assertTrue(Gate::forUser($admin)->allows('update', $provider));
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $provider));
    }

    public function test_a_platform_admin_loses_the_bypass_over_global_records_inside_a_foreign_workspace(): void
    {
        $admin = $this->makeMember($this->home, WorkspaceRole::Member, ['is_admin' => true]);
        $provider = AiProvider::factory()->create();

        $this->inWorkspace($this->foreign, function () use ($admin, $provider): void {
            $this->assertFalse(Gate::forUser($admin)->allows('update', $provider));
            $this->assertFalse(Gate::forUser($admin)->allows('view', $provider));
        });
    }

    public function test_a_deactivated_platform_admin_gets_no_bypass(): void
    {
        $admin = User::factory()->platformAdmin()->create(['is_active' => false]);

        $this->assertFalse(Gate::forUser($admin)->allows('update', AiProvider::factory()->create()));
        $this->assertFalse(Gate::forUser($admin)->allows('view', $this->foreignRecords[Task::class]));
    }

    public function test_an_ordinary_workspace_owner_cannot_write_platform_configuration(): void
    {
        $provider = AiProvider::factory()->create();
        $owner = $this->outsiders[WorkspaceRole::Owner->value];

        $this->inWorkspace($this->home, function () use ($owner, $provider): void {
            $this->assertFalse(Gate::forUser($owner)->allows('create', AiProvider::class));
            $this->assertFalse(Gate::forUser($owner)->allows('update', $provider));
            $this->assertFalse(Gate::forUser($owner)->allows('delete', $provider));
            $this->assertFalse(Gate::forUser($owner)->allows('test', $provider));
        });
    }

    /* ------------------------------------------------------------------ *
     * Positive controls
     *
     * Without these the suite above would pass just as happily if every policy
     * returned false, which would prove nothing at all.
     * ------------------------------------------------------------------ */

    public function test_the_workspace_owner_can_do_inside_their_own_workspace_what_outsiders_cannot(): void
    {
        $this->assertTrue(Gate::forUser($this->insider)->allows('view', $this->foreign));
        $this->assertTrue(Gate::forUser($this->insider)->allows('update', $this->foreign));
        $this->assertTrue(Gate::forUser($this->insider)->allows('view', $this->foreignRecords[Project::class]));
        $this->assertTrue(Gate::forUser($this->insider)->allows('update', $this->foreignRecords[Task::class]));
        $this->assertTrue(Gate::forUser($this->insider)->allows('delete', $this->foreignRecords[Task::class]));
        $this->assertTrue(Gate::forUser($this->insider)->allows('view', $this->foreignRecords[Comment::class]));
        $this->assertTrue(Gate::forUser($this->insider)->allows('download', $this->foreignRecords[Attachment::class]));
        $this->assertTrue(Gate::forUser($this->insider)->allows('view', $this->foreignRecords[Expense::class]));
        $this->assertTrue(Gate::forUser($this->insider)->allows('view', $this->foreignRecords[AiRun::class]));
    }

    public function test_class_level_abilities_are_allowed_for_a_member_of_the_bound_workspace(): void
    {
        $this->inWorkspace($this->foreign, function (): void {
            $this->assertTrue(Gate::forUser($this->insider)->allows('viewAny', Task::class));
            $this->assertTrue(Gate::forUser($this->insider)->allows('create', Project::class));
            $this->assertTrue(Gate::forUser($this->insider)->allows('viewAny', Webhook::class));
        });
    }

    /* ------------------------------------------------------------------ *
     * Step 3 — the conditional cells, inside one workspace
     *
     * The sweep above proves the layer refuses outsiders. These prove it lets
     * the right insiders through and no further, which is the half a
     * fail-closed bug would sail past.
     * ------------------------------------------------------------------ */

    public function test_a_workspace_manager_holds_the_plus_cells_only_in_projects_they_manage(): void
    {
        $manager = $this->makeMember($this->foreign, WorkspaceRole::Manager);
        $managed = $this->makeProject($this->foreign, [[$manager, ProjectRole::Manager]]);
        $joined = $this->makeProject($this->foreign, [[$manager, ProjectRole::Member]]);
        $foreignToThem = $this->makeProject($this->foreign);

        $gate = Gate::forUser($manager);

        foreach (['update', 'archive', 'manageMembers', 'viewBudget', 'viewAllTime'] as $ability) {
            $this->assertTrue($gate->allows($ability, $managed), "A project manager was refused `{$ability}`.");
            $this->assertFalse($gate->allows($ability, $joined), "`{$ability}` leaked to a project they only belong to.");
            $this->assertFalse($gate->allows($ability, $foreignToThem), "`{$ability}` leaked to a project they are not in.");
        }

        // `project.view` is `Y` for a manager, so the `+` cells narrowing writes must not
        // also narrow reads.
        $this->assertTrue($gate->allows('view', $foreignToThem));
        $this->inWorkspace($this->foreign, function () use ($gate): void {
            $this->assertTrue($gate->allows('viewAny', Project::class));
            $this->assertTrue($gate->allows('create', Project::class));
        });

        // ...and `project.delete` is blank for a manager, so managing the project is not a
        // way to acquire it.
        $this->assertFalse($gate->allows('delete', $managed));
    }

    public function test_the_plus_cell_carries_through_to_records_inside_the_managed_project(): void
    {
        $manager = $this->makeMember($this->foreign, WorkspaceRole::Manager);
        $managed = $this->makeProject($this->foreign, [[$manager, ProjectRole::Manager]]);
        $elsewhere = $this->makeProject($this->foreign);

        $gate = Gate::forUser($manager);

        $this->assertTrue($gate->allows('delete', $this->makeTask($managed)));
        $this->assertFalse($gate->allows('delete', $this->makeTask($elsewhere)));

        $this->assertTrue($gate->allows('update', Milestone::factory()->create(['project_id' => $managed->getKey()])));
        $this->assertFalse($gate->allows('update', Milestone::factory()->create(['project_id' => $elsewhere->getKey()])));

        $this->assertTrue($gate->allows('view', Expense::factory()->create(['project_id' => $managed->getKey()])));
        $this->assertFalse($gate->allows('view', Expense::factory()->create(['project_id' => $elsewhere->getKey()])));

        // budget.manage is blank for a manager even in their own project.
        $this->assertFalse($gate->allows('update', Expense::factory()->create(['project_id' => $managed->getKey()])));
    }

    public function test_a_guest_reaches_only_the_projects_they_were_added_to(): void
    {
        $guest = $this->makeMember($this->foreign, WorkspaceRole::Guest);
        $invited = $this->makeProject($this->foreign, [[$guest, ProjectRole::Guest]]);
        $other = $this->makeProject($this->foreign);

        $gate = Gate::forUser($guest);

        $this->assertTrue($gate->allows('view', $invited));
        $this->assertFalse($gate->allows('view', $other));

        $insideTask = $this->makeTask($invited);
        $outsideTask = $this->makeTask($other);

        $this->assertTrue($gate->allows('view', $insideTask));
        $this->assertTrue($gate->allows('comment', $insideTask));
        $this->assertFalse($gate->allows('view', $outsideTask));
        $this->assertFalse($gate->allows('comment', $outsideTask));

        // Nothing in the guest column is a write outside those reads.
        $this->assertFalse($gate->allows('update', $insideTask));
        $this->assertFalse($gate->allows('delete', $insideTask));
        $this->assertFalse($gate->allows('update', $invited));
        $this->assertFalse($gate->allows('create', [Task::class, $invited]));
        $this->assertFalse($gate->allows('create', [Milestone::class, $invited]));
        $this->assertFalse($gate->allows('create', [WikiPage::class, $invited]));
    }

    public function test_a_guest_cannot_read_workspace_wide_knowledge(): void
    {
        $guest = $this->makeMember($this->foreign, WorkspaceRole::Guest);
        $member = $this->makeMember($this->foreign, WorkspaceRole::Member);
        $invited = $this->makeProject($this->foreign, [[$guest, ProjectRole::Guest]]);

        $projectPage = WikiPage::factory()->create([
            'workspace_id' => $this->foreign->getKey(),
            'project_id' => $invited->getKey(),
            'visibility' => WikiVisibility::Project,
        ]);

        $workspacePage = WikiPage::factory()->create([
            'workspace_id' => $this->foreign->getKey(),
            'project_id' => null,
            'visibility' => WikiVisibility::Workspace,
        ]);

        $this->assertTrue(Gate::forUser($guest)->allows('view', $projectPage));
        // A workspace-visible page has no project for the `*` cell to match, which is what
        // keeps the guest inside the projects they were invited to.
        $this->assertFalse(Gate::forUser($guest)->allows('view', $workspacePage));

        $this->assertTrue(Gate::forUser($member)->allows('view', $workspacePage));
    }

    public function test_a_private_wiki_page_stays_with_its_author(): void
    {
        $author = $this->makeMember($this->foreign, WorkspaceRole::Member);

        $page = WikiPage::factory()->create([
            'workspace_id' => $this->foreign->getKey(),
            'project_id' => null,
            'visibility' => WikiVisibility::Private,
            'author_id' => $author->getKey(),
        ]);

        $this->assertTrue(Gate::forUser($author)->allows('view', $page));
        $this->assertTrue(Gate::forUser($author)->allows('update', $page));

        // Not even the workspace owner, which is the whole promise of "private".
        $this->assertFalse(Gate::forUser($this->insider)->allows('view', $page));
        $this->assertFalse(Gate::forUser($this->insider)->allows('update', $page));
        $this->assertFalse(Gate::forUser($this->insider)->allows('delete', $page));
    }

    public function test_a_member_may_edit_only_the_tasks_they_are_assignee_or_reporter_of(): void
    {
        $member = $this->makeMember($this->foreign, WorkspaceRole::Member);
        $project = $this->makeProject($this->foreign);

        $assigned = $this->makeTask($project, ['assignee_id' => $member->getKey()]);
        $reported = $this->makeTask($project, ['reporter_id' => $member->getKey()]);
        $somebodyElses = $this->makeTask($project);

        $gate = Gate::forUser($member);

        $this->assertTrue($gate->allows('update', $assigned));
        $this->assertTrue($gate->allows('update', $reported));
        $this->assertFalse($gate->allows('update', $somebodyElses));

        // task.view is `Y` for a member: they can read the whole board, they just cannot
        // rewrite the parts of it that are not theirs.
        $this->assertTrue($gate->allows('view', $somebodyElses));
        $this->assertTrue($gate->allows('comment', $somebodyElses));

        // task.assign and task.delete are blank for a member, even on their own task.
        $this->assertFalse($gate->allows('assign', $assigned));
        $this->assertFalse($gate->allows('delete', $assigned));
    }

    public function test_the_own_cell_limits_attachment_deletion_to_the_uploader(): void
    {
        $member = $this->makeMember($this->foreign, WorkspaceRole::Member);
        $manager = $this->makeMember($this->foreign, WorkspaceRole::Manager);
        $project = $this->makeProject($this->foreign, [[$manager, ProjectRole::Manager]]);
        $task = $this->makeTask($project);

        $theirs = Attachment::factory()->create([
            'attachable_type' => $task->getMorphClass(),
            'attachable_id' => $task->getKey(),
            'uploaded_by' => $member->getKey(),
        ]);

        $somebodyElses = Attachment::factory()->create([
            'attachable_type' => $task->getMorphClass(),
            'attachable_id' => $task->getKey(),
        ]);

        $this->assertTrue(Gate::forUser($member)->allows('delete', $theirs));
        $this->assertFalse(Gate::forUser($member)->allows('delete', $somebodyElses));

        // Both are readable — `own` narrows deletion, not access.
        $this->assertTrue(Gate::forUser($member)->allows('view', $somebodyElses));

        // The `+` cell: a project manager clears up after anybody inside their project.
        $this->assertTrue(Gate::forUser($manager)->allows('delete', $somebodyElses));
    }

    public function test_a_comment_is_edited_only_by_its_author_and_moderated_by_the_workspace(): void
    {
        $author = $this->makeMember($this->foreign, WorkspaceRole::Member);
        $bystander = $this->makeMember($this->foreign, WorkspaceRole::Member);
        $project = $this->makeProject($this->foreign);
        $task = $this->makeTask($project);

        $comment = Comment::factory()->create([
            'commentable_type' => $task->getMorphClass(),
            'commentable_id' => $task->getKey(),
            'user_id' => $author->getKey(),
        ]);

        $this->assertTrue(Gate::forUser($author)->allows('update', $comment));
        $this->assertTrue(Gate::forUser($author)->allows('delete', $comment));

        $this->assertFalse(Gate::forUser($bystander)->allows('update', $comment));
        $this->assertFalse(Gate::forUser($bystander)->allows('delete', $comment));

        // The owner moderates but still does not rewrite.
        $this->assertTrue(Gate::forUser($this->insider)->allows('delete', $comment));
        $this->assertFalse(Gate::forUser($this->insider)->allows('update', $comment));
    }

    public function test_an_ai_authored_comment_is_immutable(): void
    {
        $project = $this->makeProject($this->foreign);
        $task = $this->makeTask($project);

        $comment = Comment::factory()->create([
            'commentable_type' => $task->getMorphClass(),
            'commentable_id' => $task->getKey(),
            'user_id' => null,
            'author_type' => AuthorType::Ai,
        ]);

        $this->assertFalse(Gate::forUser($this->insider)->allows('update', $comment));
        $this->assertTrue(Gate::forUser($this->insider)->allows('delete', $comment));
    }

    public function test_the_activity_feed_is_append_only_for_everyone(): void
    {
        $activity = $this->foreignRecords[Activity::class];

        $this->assertTrue(Gate::forUser($this->insider)->allows('view', $activity));

        foreach (['create', 'update', 'delete', 'restore', 'forceDelete'] as $ability) {
            $this->assertFalse(
                Gate::forUser($this->insider)->allows($ability, $activity),
                "The workspace owner was allowed `{$ability}` on an activity row; the feed must be append-only.",
            );
        }
    }

    public function test_a_saved_view_belonging_to_one_person_is_invisible_to_another(): void
    {
        $owner = $this->makeMember($this->foreign, WorkspaceRole::Member);
        $other = $this->makeMember($this->foreign, WorkspaceRole::Member);

        $personal = SavedView::factory()->create([
            'workspace_id' => $this->foreign->getKey(),
            'user_id' => $owner->getKey(),
            'is_shared' => false,
        ]);

        $shared = SavedView::factory()->create([
            'workspace_id' => $this->foreign->getKey(),
            'user_id' => $owner->getKey(),
            'is_shared' => true,
        ]);

        $this->assertTrue(Gate::forUser($owner)->allows('view', $personal));
        $this->assertFalse(Gate::forUser($other)->allows('view', $personal));
        $this->assertTrue(Gate::forUser($other)->allows('view', $shared));

        // Sharing is `templates.manage`, which a plain member does not hold.
        $this->assertFalse(Gate::forUser($owner)->allows('share', $personal));
    }

    public function test_a_workspace_member_cannot_be_edited_by_the_person_it_belongs_to(): void
    {
        $admin = $this->makeMember($this->foreign, WorkspaceRole::Admin);
        $ownMembership = WorkspaceMember::withoutWorkspaceScope()
            ->where('workspace_id', $this->foreign->getKey())
            ->where('user_id', $admin->getKey())
            ->firstOrFail();

        $ownerMembership = WorkspaceMember::withoutWorkspaceScope()
            ->where('workspace_id', $this->foreign->getKey())
            ->where('user_id', $this->insider->getKey())
            ->firstOrFail();

        $someoneElse = WorkspaceMember::factory()->create(['workspace_id' => $this->foreign->getKey()]);

        $gate = Gate::forUser($admin);

        $this->assertTrue($gate->allows('update', $someoneElse));
        $this->assertFalse($gate->allows('update', $ownMembership), 'An admin must not edit their own membership.');
        $this->assertFalse($gate->allows('update', $ownerMembership), 'Only an owner may touch an owner.');
        $this->assertFalse(
            $gate->allows('assignRole', [$someoneElse, WorkspaceRole::Owner]),
            'Only an owner may grant the owner role.',
        );
        $this->assertTrue($gate->allows('assignRole', [$someoneElse, WorkspaceRole::Manager]));
    }

    /* ------------------------------------------------------------------ *
     * Census — the sweep is only as good as its record map
     * ------------------------------------------------------------------ */

    public function test_every_registered_policy_is_exercised_by_this_suite(): void
    {
        foreach (AuthServiceProvider::policies() as $model => $policy) {
            if (array_key_exists($model, self::NOT_TENANT_SCOPED)) {
                continue;
            }

            $this->assertArrayHasKey(
                $model,
                $this->foreignRecords,
                "{$policy} is registered but {$model} has no record in the cross-workspace suite. "
                .'Add one, or list the model in NOT_TENANT_SCOPED with the reason.',
            );
        }
    }

    public function test_every_model_with_a_workspace_column_reads_it_from_the_foreign_workspace(): void
    {
        foreach ($this->foreignRecords as $model => $record) {
            $workspaceId = $record instanceof Workspace
                ? $record->getKey()
                : $record->getAttribute('workspace_id');

            $this->assertSame(
                (int) $this->foreign->getKey(),
                (int) $workspaceId,
                "The {$model} fixture was not built in the foreign workspace, so the sweep proves nothing about it.",
            );
        }
    }

    /* ------------------------------------------------------------------ *
     * Fixtures
     * ------------------------------------------------------------------ */

    /**
     * @return array<class-string<Model>, Model>
     */
    private function buildForeignRecords(): array
    {
        $workspaceId = (int) $this->foreign->getKey();
        $userId = (int) $this->insider->getKey();

        $project = $this->makeProject($this->foreign, [[$this->insider, ProjectRole::Manager]]);
        $projectId = (int) $project->getKey();

        $task = $this->makeTask($project, ['reporter_id' => $userId]);
        $taskMorph = $task->getMorphClass();

        $aiRun = AiRun::factory()->create([
            'workspace_id' => $workspaceId,
            'project_id' => $projectId,
            'user_id' => $userId,
        ]);

        return [
            Workspace::class => $this->foreign,
            WorkspaceMember::class => WorkspaceMember::factory()->create(['workspace_id' => $workspaceId]),
            Team::class => Team::factory()->create(['workspace_id' => $workspaceId]),
            Invitation::class => Invitation::factory()->create([
                'workspace_id' => $workspaceId,
                'project_id' => $projectId,
                'invited_by' => $userId,
            ]),

            Project::class => $project,
            ProjectStatus::class => ProjectStatus::factory()->create(['workspace_id' => $workspaceId]),
            Milestone::class => Milestone::factory()->create(['project_id' => $projectId]),

            Task::class => $task,
            TaskStatus::class => TaskStatus::factory()->create(['project_id' => $projectId]),
            TaskDependency::class => TaskDependency::factory()->create(['task_id' => $task->getKey()]),
            RecurringTask::class => RecurringTask::factory()->create([
                'project_id' => $projectId,
                'created_by' => $userId,
            ]),

            Comment::class => Comment::factory()->create([
                'commentable_type' => $taskMorph,
                'commentable_id' => $task->getKey(),
                'user_id' => $userId,
            ]),
            Attachment::class => Attachment::factory()->create([
                'attachable_type' => $taskMorph,
                'attachable_id' => $task->getKey(),
                'uploaded_by' => $userId,
            ]),
            Activity::class => Activity::factory()->create([
                'subject_type' => $taskMorph,
                'subject_id' => $task->getKey(),
                'causer_id' => $userId,
            ]),

            TimeEntry::class => TimeEntry::factory()->create([
                'project_id' => $projectId,
                'task_id' => $task->getKey(),
                'user_id' => $userId,
            ]),
            Expense::class => Expense::factory()->create([
                'project_id' => $projectId,
                'user_id' => $userId,
            ]),

            WikiPage::class => WikiPage::factory()->create([
                'workspace_id' => $workspaceId,
                'project_id' => $projectId,
                'author_id' => $userId,
            ]),
            SavedView::class => SavedView::factory()->create([
                'workspace_id' => $workspaceId,
                'project_id' => $projectId,
                'user_id' => $userId,
            ]),
            CustomField::class => CustomField::factory()->create([
                'workspace_id' => $workspaceId,
                'project_id' => $projectId,
            ]),
            Tag::class => Tag::factory()->create(['workspace_id' => $workspaceId]),
            ProjectTemplate::class => ProjectTemplate::factory()->create([
                'workspace_id' => $workspaceId,
                'is_system' => false,
            ]),

            Webhook::class => Webhook::factory()->create(['workspace_id' => $workspaceId]),

            AiConversation::class => AiConversation::factory()->create([
                'workspace_id' => $workspaceId,
                'project_id' => $projectId,
                'user_id' => $userId,
            ]),
            AiRun::class => $aiRun,
            AiToolRun::class => AiToolRun::factory()->create([
                'workspace_id' => $workspaceId,
                'ai_run_id' => $aiRun->getKey(),
                'project_id' => $projectId,
                'user_id' => $userId,
            ]),
            AiPolicy::class => AiPolicy::factory()->create([
                'workspace_id' => $workspaceId,
                'project_id' => $projectId,
            ]),
            AiSetting::class => AiSetting::factory()->create(['workspace_id' => $workspaceId]),
            AiAutomation::class => AiAutomation::factory()->create([
                'workspace_id' => $workspaceId,
                'project_id' => $projectId,
                'created_by' => $userId,
            ]),
        ];
    }

    /* ------------------------------------------------------------------ *
     * Reflection over the policy map
     * ------------------------------------------------------------------ */

    /**
     * @return array<class-string<Model>, class-string>
     */
    private function tenantScopedPolicies(): array
    {
        return array_diff_key(AuthServiceProvider::policies(), self::NOT_TENANT_SCOPED);
    }

    /**
     * Every public ability whose second parameter is the policy's own model — the abilities
     * that judge a record rather than a class.
     *
     * @param class-string $policy
     * @param class-string<Model> $model
     * @return list<array{0: string, 1: list<mixed>}>
     */
    private function recordAbilities(string $policy, string $model): array
    {
        $abilities = [];

        foreach ((new ReflectionClass($policy))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor()) {
                continue;
            }

            $parameters = $method->getParameters();

            if (count($parameters) < 2) {
                continue;
            }

            $subject = $parameters[1]->getType();

            if (! $subject instanceof ReflectionNamedType || $subject->getName() !== $model) {
                continue;
            }

            $abilities[] = [$method->getName(), $this->extraArguments($parameters)];
        }

        $this->assertNotSame([], $abilities, "{$policy} exposes no record-level ability to test.");

        return $abilities;
    }

    /**
     * Stand-in values for the arguments an ability takes beyond the user and the record.
     *
     * @param list<ReflectionParameter> $parameters
     * @return list<mixed>
     */
    private function extraArguments(array $parameters): array
    {
        $extra = [];

        foreach (array_slice($parameters, 2) as $parameter) {
            if ($parameter->isDefaultValueAvailable()) {
                $extra[] = $parameter->getDefaultValue();

                continue;
            }

            $type = $parameter->getType();
            $name = $type instanceof ReflectionNamedType ? $type->getName() : null;

            $extra[] = match ($name) {
                WorkspaceRole::class => WorkspaceRole::Member,
                ProjectRole::class => ProjectRole::Member,
                default => $this->fail(
                    "A policy ability takes a required `{$parameter->getName()}` argument of an "
                    .'unknown type, so the sweep cannot call it. Teach extraArguments() about it.',
                ),
            };
        }

        return $extra;
    }

    private function breach(string $policy, string $ability, string $role, string $model): string
    {
        return "TENANCY BREACH: a {$role} of another workspace was allowed `{$ability}` "
            ."on a {$model} belonging to the foreign workspace, via {$policy}.";
    }
}

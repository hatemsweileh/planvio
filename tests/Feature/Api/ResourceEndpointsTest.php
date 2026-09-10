<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Actions\Projects\SeedTaskStatuses;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Tag;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The rest of the surface: projects, milestones, time, tags, people, history and search.
 *
 * Two behaviours here are worth stating out loud because they are where an API most easily
 * says more than the product does.
 *
 * **Budget is a separate permission.** `budget.view` is not `project.view`, so `budget` and
 * `currency` are absent — not null — for a caller who does not hold it. A null would say
 * "this project has no budget"; an absent key says "this is not yours to see".
 *
 * **Time is somebody's private record until `time.view_all` says otherwise.** A plain member
 * reading `/time-entries` sees their own hours and nobody else's, whatever they filter for.
 */
final class ResourceEndpointsTest extends ApiTestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $member;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->makeWorkspace(['slug' => 'acme', 'currency' => 'GBP']);
        $this->owner = $this->makeMember($this->workspace, WorkspaceRole::Owner);
        $this->member = $this->makeMember($this->workspace, WorkspaceRole::Member);

        $this->project = $this->makeProject($this->workspace, [], [
            'key' => 'WEB',
            'slug' => 'website',
            'budget' => '25000.00',
            'currency' => 'GBP',
        ]);

        // `makeProject()` writes the row directly, so it skips CreateProject and the board it
        // seeds. Anything that creates a task needs the columns to exist.
        $this->inWorkspace($this->workspace, function (): void {
            app(SeedTaskStatuses::class)($this->project);
        });
    }

    /* ------------------------------------------------------------------ *
     * Projects
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_creates_a_project_with_a_seeded_board(): void
    {
        $response = $this->asToken($this->owner, $this->workspace)->postJson('/api/v1/projects', [
            'name' => 'Mobile app',
            'key' => 'APP',
            'type' => 'software',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Mobile app');
        $response->assertJsonPath('data.key', 'APP');
        $response->assertJsonPath('data.type', 'software');
        $response->assertJsonPath('data.owner_id', $this->owner->getKey());

        // Created through the action, so the board came with it.
        $this->assertDatabaseHas('task_statuses', ['project_id' => $response->json('data.id')]);
    }

    #[Test]
    public function an_explicit_key_that_is_taken_is_refused(): void
    {
        // A key somebody typed is a decision, not a suggestion: silently handing back `WEB2`
        // would put a prefix nobody chose on every task in the project
        // ({@see \App\Actions\Projects\ProjectKeyGenerator}).
        $response = $this->asToken($this->owner, $this->workspace)->postJson('/api/v1/projects', [
            'name' => 'Website relaunch',
            'key' => 'WEB',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'rule_violated');
    }

    #[Test]
    public function a_derived_key_is_deduplicated(): void
    {
        // No key was asked for, so the generator derives one and steps around the collision.
        $this->makeProject($this->workspace, [], ['key' => 'MA', 'slug' => 'mobile-app-old']);

        $response = $this->asToken($this->owner, $this->workspace)
            ->postJson('/api/v1/projects', ['name' => 'Mobile App']);

        $response->assertCreated();
        $this->assertNotSame('MA', $response->json('data.key'));
    }

    #[Test]
    public function it_patches_a_project(): void
    {
        $response = $this->asToken($this->owner, $this->workspace)
            ->patchJson('/api/v1/projects/'.$this->project->getKey(), [
                'name' => 'Website, renamed',
                'health' => 'at_risk',
                'health_note' => 'Waiting on copy.',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Website, renamed');
        $response->assertJsonPath('data.health', 'at_risk');
        $response->assertJsonPath('data.health_note', 'Waiting on copy.');
    }

    #[Test]
    public function the_budget_is_shown_to_an_owner_and_withheld_from_a_member(): void
    {
        $forOwner = $this->asToken($this->owner, $this->workspace)
            ->getJson('/api/v1/projects/'.$this->project->getKey());

        $forOwner->assertOk();
        $forOwner->assertJsonPath('data.budget', '25000.00');
        $forOwner->assertJsonPath('data.currency', 'GBP');

        $forMember = $this->asToken($this->member, $this->workspace)
            ->getJson('/api/v1/projects/'.$this->project->getKey());

        $forMember->assertOk();

        // Absent, not null.
        $this->assertArrayNotHasKey('budget', (array) $forMember->json('data'));
        $this->assertArrayNotHasKey('currency', (array) $forMember->json('data'));
        $this->assertStringNotContainsString('25000', $forMember->getContent() ?: '');
    }

    #[Test]
    public function a_member_cannot_set_a_budget_through_the_api(): void
    {
        $project = $this->makeProject($this->workspace, [], ['key' => 'OPS', 'slug' => 'ops']);

        // A plain member cannot update a project at all, so the write is refused outright —
        // which is the same answer the product's settings screen gives.
        $this->asToken($this->member, $this->workspace)
            ->patchJson('/api/v1/projects/'.$project->getKey(), ['budget' => 999])
            ->assertStatus(403);

        // Unchanged — the factory gave it one, and 999 is not it.
        $this->assertSame((string) $project->budget, (string) $project->fresh()?->budget);
    }

    #[Test]
    public function archived_projects_are_out_of_the_default_list(): void
    {
        $archived = $this->makeProject($this->workspace, [], [
            'key' => 'OLD',
            'slug' => 'old',
            'is_archived' => true,
        ]);

        $active = $this->asToken($this->owner, $this->workspace)
            ->getJson('/api/v1/projects')
            ->json('data.*.id');

        $this->assertNotContains($archived->getKey(), $active);

        $withArchived = $this->asToken($this->owner, $this->workspace)
            ->getJson('/api/v1/projects?archived=1')
            ->json('data.*.id');

        $this->assertSame([$archived->getKey()], $withArchived);
    }

    /* ------------------------------------------------------------------ *
     * Milestones
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_creates_and_completes_a_milestone(): void
    {
        $created = $this->asToken($this->owner, $this->workspace)->postJson('/api/v1/milestones', [
            'project_id' => $this->project->getKey(),
            'name' => 'Beta',
            'due_date' => '2026-10-01',
        ]);

        $created->assertCreated();
        $created->assertJsonPath('data.name', 'Beta');
        $created->assertJsonPath('data.due_date', '2026-10-01');
        $created->assertJsonPath('data.completed_at', null);

        $completed = $this->asToken($this->owner, $this->workspace)
            ->patchJson('/api/v1/milestones/'.$created->json('data.id'), [
                'status' => MilestoneStatus::Completed->value,
            ]);

        $completed->assertOk();
        $completed->assertJsonPath('data.status', MilestoneStatus::Completed->value);

        // Written by the transition, never by the caller.
        $this->assertNotNull($completed->json('data.completed_at'));
    }

    #[Test]
    public function undated_milestones_sort_last(): void
    {
        Milestone::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'project_id' => $this->project->getKey(),
            'name' => 'Someday',
            'due_date' => null,
        ]);

        $dated = Milestone::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'project_id' => $this->project->getKey(),
            'name' => 'Soon',
            'due_date' => '2026-01-01',
        ]);

        $ids = $this->asToken($this->owner, $this->workspace)
            ->getJson('/api/v1/milestones?direction=asc')
            ->json('data.*.id');

        $this->assertSame($dated->getKey(), $ids[0]);
    }

    /* ------------------------------------------------------------------ *
     * Time
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_logs_time_as_the_caller(): void
    {
        $task = $this->makeTask($this->project);

        $response = $this->asToken($this->member, $this->workspace)->postJson('/api/v1/time-entries', [
            'project_id' => $this->project->getKey(),
            'task_id' => $task->getKey(),
            'minutes' => 90,
            'spent_on' => '2026-02-10',
            'description' => 'Pairing on the importer',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.minutes', 90);
        $response->assertJsonPath('data.spent_on', '2026-02-10');

        // There is no `user_id` on the write: an entry is always the caller's own.
        $response->assertJsonPath('data.user_id', $this->member->getKey());
    }

    #[Test]
    public function a_member_sees_only_their_own_hours(): void
    {
        TimeEntry::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'project_id' => $this->project->getKey(),
            'user_id' => $this->owner->getKey(),
            'minutes' => 120,
        ]);

        $mine = TimeEntry::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'project_id' => $this->project->getKey(),
            'user_id' => $this->member->getKey(),
            'minutes' => 30,
        ]);

        $response = $this->asToken($this->member, $this->workspace)->getJson('/api/v1/time-entries');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $mine->getKey());

        // And filtering for a colleague does not widen it.
        $this->asToken($this->member, $this->workspace)
            ->getJson('/api/v1/time-entries?user_id='.$this->owner->getKey())
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function a_manager_sees_the_team_inside_the_project_they_run(): void
    {
        $manager = $this->makeMember($this->workspace, WorkspaceRole::Manager);

        $this->project->members()->attach($manager->getKey(), [
            'role' => ProjectRole::Manager->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        TimeEntry::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'project_id' => $this->project->getKey(),
            'user_id' => $this->member->getKey(),
            'minutes' => 45,
        ]);

        // `time.view_all` is a `+` cell: a manager holds it inside the projects they manage
        // and nowhere else, so the answer depends on what they asked about.
        $this->asToken($manager, $this->workspace)
            ->getJson('/api/v1/time-entries?project_id='.$this->project->getKey())
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Asked workspace-wide there is no project for the `+` cell to match, and they see
        // only their own — which is none.
        $this->asToken($manager, $this->workspace)
            ->getJson('/api/v1/time-entries')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function an_owner_sees_everybodys_hours(): void
    {
        TimeEntry::factory()->count(2)->create([
            'workspace_id' => $this->workspace->getKey(),
            'project_id' => $this->project->getKey(),
            'user_id' => $this->member->getKey(),
        ]);

        $this->asToken($this->owner, $this->workspace)
            ->getJson('/api/v1/time-entries')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function a_single_entry_cannot_exceed_a_day(): void
    {
        $this->asToken($this->member, $this->workspace)
            ->postJson('/api/v1/time-entries', [
                'project_id' => $this->project->getKey(),
                'minutes' => 5000,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    /* ------------------------------------------------------------------ *
     * Tags
     * ------------------------------------------------------------------ */

    #[Test]
    public function creating_a_tag_twice_returns_the_existing_one(): void
    {
        $first = $this->asToken($this->owner, $this->workspace)
            ->postJson('/api/v1/tags', ['name' => 'Blocked', 'color' => '#B03F3F']);

        $first->assertCreated();

        $second = $this->asToken($this->owner, $this->workspace)
            ->postJson('/api/v1/tags', ['name' => 'Blocked']);

        // 200 rather than 201: nothing new was made, and the caller can tell.
        $second->assertOk();
        $second->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, Tag::withoutWorkspaceScope()->count());
    }

    #[Test]
    public function a_tag_colour_must_be_a_hex_value(): void
    {
        $this->asToken($this->owner, $this->workspace)
            ->postJson('/api/v1/tags', ['name' => 'Odd', 'color' => 'javascript:alert(1)'])
            ->assertStatus(422);
    }

    /* ------------------------------------------------------------------ *
     * People, history, search and identity
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_member_list_is_the_membership_not_the_account(): void
    {
        $response = $this->asToken($this->owner, $this->workspace)->getJson('/api/v1/users');

        $response->assertOk();
        $response->assertJsonStructure(['data' => [['user_id', 'role', 'user' => ['id', 'name', 'email']]]]);

        $roles = $response->json('data.*.role');

        $this->assertContains(WorkspaceRole::Owner->value, $roles);
        $this->assertContains(WorkspaceRole::Member->value, $roles);
    }

    #[Test]
    public function a_member_is_addressed_by_user_id(): void
    {
        $this->asToken($this->owner, $this->workspace)
            ->getJson('/api/v1/users/'.$this->member->getKey())
            ->assertOk()
            ->assertJsonPath('data.user_id', $this->member->getKey())
            ->assertJsonPath('data.role', WorkspaceRole::Member->value);
    }

    #[Test]
    public function the_activity_feed_records_what_the_api_did(): void
    {
        $this->asToken($this->owner, $this->workspace)->postJson('/api/v1/tasks', [
            'project_id' => $this->project->getKey(),
            'title' => 'Traceable',
        ])->assertCreated();

        $response = $this->asToken($this->owner, $this->workspace)
            ->getJson('/api/v1/activity?event=created');

        $response->assertOk();
        $this->assertContains($this->owner->getKey(), $response->json('data.*.causer_id'));
    }

    #[Test]
    public function search_groups_results_by_type(): void
    {
        $this->makeTask($this->project, ['title' => 'Importer rewrite']);

        $response = $this->asToken($this->owner, $this->workspace)
            ->getJson('/api/v1/search?q=Importer');

        $response->assertOk();
        $response->assertJsonPath('data.term', 'Importer');
        $response->assertJsonStructure(['data' => ['term', 'total', 'groups'], 'meta' => ['types']]);
        $this->assertGreaterThan(0, (int) $response->json('data.total'));
    }

    #[Test]
    public function search_can_be_narrowed_to_one_type(): void
    {
        $this->makeTask($this->project, ['title' => 'Importer rewrite']);

        $response = $this->asToken($this->owner, $this->workspace)
            ->getJson('/api/v1/search?q=Importer&types[]=project');

        $response->assertOk();
        $response->assertJsonPath('data.total', 0);
    }

    #[Test]
    public function me_lists_the_workspaces_a_token_may_name(): void
    {
        $other = $this->makeWorkspace(['slug' => 'northwind']);
        $other->members()->attach($this->owner->getKey(), [
            'role' => WorkspaceRole::Member->value,
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->asToken($this->owner)->getJson('/api/v1/me');

        $response->assertOk();

        $slugs = $response->json('data.workspaces.*.slug');

        $this->assertContains('acme', $slugs);
        $this->assertContains('northwind', $slugs);
        $this->assertSame(
            [WorkspaceRole::Owner->value, WorkspaceRole::Member->value],
            [
                $response->json('data.workspaces.'.array_search('acme', $slugs, true).'.role'),
                $response->json('data.workspaces.'.array_search('northwind', $slugs, true).'.role'),
            ],
        );
    }
}

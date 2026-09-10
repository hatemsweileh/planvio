<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Actions\Projects\CreateProject;
use App\Actions\Projects\SeedTaskStatuses;
use App\Enums\Priority;
use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The task surface end to end, and the places where the API has to say no.
 *
 * The refusals are the interesting half. Every one of them is the *same* policy the product
 * UI runs — `task.assign` is a separate grant from `task.update`, a plain member may only
 * edit tasks they are the assignee or reporter of, a guest sees only their own projects — so
 * an API that got any of them wrong would be an API that quietly out-ranks the screens.
 */
final class TaskEndpointsTest extends ApiTestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $member;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->makeWorkspace(['slug' => 'acme']);
        $this->owner = $this->makeMember($this->workspace, WorkspaceRole::Owner);
        $this->member = $this->makeMember($this->workspace, WorkspaceRole::Member);
        $this->project = $this->makeProject($this->workspace, [], ['key' => 'WEB', 'slug' => 'website']);

        $this->seedBoard($this->project);
    }

    /**
     * Give a factory-built project the board a real one gets on creation.
     *
     * `makeProject()` writes the row directly, so it skips
     * {@see CreateProject} and the columns it seeds. Running the
     * same seeding action the product runs is closer to the truth than inventing statuses
     * here: these tests assert on `is_completed`, which is a property of the real template.
     */
    private function seedBoard(Project $project): void
    {
        $this->inWorkspace($this->workspace, static function () use ($project): void {
            app(SeedTaskStatuses::class)($project);
        });
    }

    #[Test]
    public function it_creates_a_task(): void
    {
        $response = $this->asToken($this->owner, $this->workspace)->postJson('/api/v1/tasks', [
            'project_id' => $this->project->getKey(),
            'title' => 'Ship the API',
            'description' => 'With documentation.',
            'priority' => Priority::High->value,
            'assignee_id' => $this->member->getKey(),
            'due_date' => '2026-12-01',
            'estimate_minutes' => 240,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.title', 'Ship the API');
        $response->assertJsonPath('data.priority', 'high');
        $response->assertJsonPath('data.assignee_id', $this->member->getKey());
        $response->assertJsonPath('data.due_date', '2026-12-01');
        $response->assertJsonPath('data.project_id', $this->project->getKey());

        // The project prefix is part of the contract, so the relation has to be loaded.
        $this->assertSame('WEB-'.$response->json('data.number'), $response->json('data.key'));

        $this->assertDatabaseHas('tasks', [
            'id' => $response->json('data.id'),
            'workspace_id' => $this->workspace->getKey(),
            'reporter_id' => $this->owner->getKey(),
        ]);
    }

    #[Test]
    public function it_refuses_a_task_with_no_title(): void
    {
        $response = $this->asToken($this->owner, $this->workspace)->postJson('/api/v1/tasks', [
            'project_id' => $this->project->getKey(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'validation_failed');
        $response->assertJsonStructure(['error' => ['status', 'code', 'message', 'fields' => ['title']]]);
    }

    #[Test]
    public function it_reads_a_task_with_its_status_and_assignee(): void
    {
        $task = $this->makeTask($this->project, ['assignee_id' => $this->member->getKey()]);

        $response = $this->asToken($this->owner, $this->workspace)
            ->getJson('/api/v1/tasks/'.$task->getKey());

        $response->assertOk();
        $response->assertJsonPath('data.id', $task->getKey());
        $response->assertJsonPath('data.assignee.id', $this->member->getKey());
        $response->assertJsonStructure(['data' => ['status' => ['id', 'name', 'category', 'is_completed']]]);
    }

    #[Test]
    public function it_patches_only_the_fields_that_were_sent(): void
    {
        $task = $this->makeTask($this->project, [
            'title' => 'Original',
            'due_date' => '2026-05-05',
        ]);

        $response = $this->asToken($this->owner, $this->workspace)
            ->patchJson('/api/v1/tasks/'.$task->getKey(), ['title' => 'Renamed']);

        $response->assertOk();
        $response->assertJsonPath('data.title', 'Renamed');

        // Untouched, because it was never mentioned.
        $response->assertJsonPath('data.due_date', '2026-05-05');
    }

    #[Test]
    public function a_null_clears_a_date_and_an_absent_key_does_not(): void
    {
        $task = $this->makeTask($this->project, ['due_date' => '2026-05-05']);

        $this->asToken($this->owner, $this->workspace)
            ->patchJson('/api/v1/tasks/'.$task->getKey(), ['due_date' => null])
            ->assertOk()
            ->assertJsonPath('data.due_date', null);

        $this->assertNull($task->fresh()?->due_date);
    }

    #[Test]
    public function it_deletes_a_task(): void
    {
        $task = $this->makeTask($this->project);

        $this->asToken($this->owner, $this->workspace)
            ->deleteJson('/api/v1/tasks/'.$task->getKey())
            ->assertNoContent();

        $this->assertSoftDeleted('tasks', ['id' => $task->getKey()]);
    }

    #[Test]
    public function a_soft_deleted_task_is_gone_from_the_api(): void
    {
        $task = $this->makeTask($this->project);
        $task->delete();

        $this->asToken($this->owner, $this->workspace)
            ->getJson('/api/v1/tasks/'.$task->getKey())
            ->assertStatus(404);

        $ids = $this->asToken($this->owner, $this->workspace)
            ->getJson('/api/v1/tasks')
            ->json('data.*.id');

        $this->assertNotContains($task->getKey(), $ids);
    }

    #[Test]
    public function it_assigns_and_unassigns(): void
    {
        $task = $this->makeTask($this->project);

        $this->asToken($this->owner, $this->workspace)
            ->postJson('/api/v1/tasks/'.$task->getKey().'/assign', ['assignee_id' => $this->member->getKey()])
            ->assertOk()
            ->assertJsonPath('data.assignee_id', $this->member->getKey());

        $this->asToken($this->owner, $this->workspace)
            ->postJson('/api/v1/tasks/'.$task->getKey().'/assign', ['assignee_id' => null])
            ->assertOk()
            ->assertJsonPath('data.assignee_id', null);
    }

    #[Test]
    public function an_empty_assign_body_is_refused(): void
    {
        $task = $this->makeTask($this->project);

        $this->asToken($this->owner, $this->workspace)
            ->postJson('/api/v1/tasks/'.$task->getKey().'/assign', [])
            ->assertStatus(422);
    }

    #[Test]
    public function it_moves_a_task_to_another_column(): void
    {
        $task = $this->makeTask($this->project);

        $done = TaskStatus::query()
            ->where('project_id', $this->project->getKey())
            ->where('is_completed', true)
            ->firstOrFail();

        $response = $this->asToken($this->owner, $this->workspace)
            ->postJson('/api/v1/tasks/'.$task->getKey().'/status', ['status_id' => $done->getKey()]);

        $response->assertOk();
        $response->assertJsonPath('data.status_id', $done->getKey());
        $response->assertJsonPath('data.is_completed', true);
        $this->assertNotNull($response->json('data.completed_at'));
    }

    #[Test]
    public function it_refuses_a_column_from_another_project(): void
    {
        $task = $this->makeTask($this->project);
        $other = $this->makeProject($this->workspace, [], ['key' => 'OPS', 'slug' => 'ops']);
        $this->seedBoard($other);

        $foreignColumn = TaskStatus::query()
            ->where('project_id', $other->getKey())
            ->firstOrFail();

        // In the workspace, so it resolves — and refused by the action, which knows the
        // column belongs to a different board.
        $this->asToken($this->owner, $this->workspace)
            ->postJson('/api/v1/tasks/'.$task->getKey().'/status', ['status_id' => $foreignColumn->getKey()])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'rule_violated');
    }

    #[Test]
    public function the_status_endpoint_cannot_be_reached_through_the_patch(): void
    {
        $task = $this->makeTask($this->project);
        $original = (int) $task->status_id;

        $done = TaskStatus::query()
            ->where('project_id', $this->project->getKey())
            ->where('is_completed', true)
            ->firstOrFail();

        // `status_id` is not in the PATCH rules, so it is not in the validated data and never
        // reaches the change set. The request succeeds and the column is untouched.
        $this->asToken($this->owner, $this->workspace)
            ->patchJson('/api/v1/tasks/'.$task->getKey(), ['status_id' => $done->getKey()])
            ->assertOk();

        $this->assertSame($original, (int) $task->fresh()?->status_id);
    }

    #[Test]
    public function a_plain_member_may_not_assign(): void
    {
        $task = $this->makeTask($this->project, ['reporter_id' => $this->member->getKey()]);

        // `task.update` yes — they are the reporter. `task.assign` no: the matrix withholds
        // it from a plain member entirely (ARCHITECTURE.md §4.2).
        $this->asToken($this->member, $this->workspace)
            ->patchJson('/api/v1/tasks/'.$task->getKey(), ['title' => 'Mine to rename'])
            ->assertOk();

        $this->asToken($this->member, $this->workspace)
            ->postJson('/api/v1/tasks/'.$task->getKey().'/assign', ['assignee_id' => $this->member->getKey()])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }

    #[Test]
    public function a_plain_member_may_not_edit_somebody_elses_task(): void
    {
        $task = $this->makeTask($this->project, [
            'reporter_id' => $this->owner->getKey(),
            'assignee_id' => $this->owner->getKey(),
        ]);

        $this->asToken($this->member, $this->workspace)
            ->patchJson('/api/v1/tasks/'.$task->getKey(), ['title' => 'Not mine'])
            ->assertStatus(403);

        // And may not reassign it to themselves through the update path either.
        $this->asToken($this->member, $this->workspace)
            ->patchJson('/api/v1/tasks/'.$task->getKey(), ['assignee_id' => $this->member->getKey()])
            ->assertStatus(403);
    }

    #[Test]
    public function a_plain_member_may_not_delete(): void
    {
        $task = $this->makeTask($this->project, ['reporter_id' => $this->member->getKey()]);

        $this->asToken($this->member, $this->workspace)
            ->deleteJson('/api/v1/tasks/'.$task->getKey())
            ->assertStatus(403);

        $this->assertNotSoftDeleted('tasks', ['id' => $task->getKey()]);
    }

    #[Test]
    public function filters_narrow_the_list(): void
    {
        $mine = $this->makeTask($this->project, ['assignee_id' => $this->member->getKey()]);
        $this->makeTask($this->project);

        $response = $this->asToken($this->owner, $this->workspace)
            ->getJson('/api/v1/tasks?assignee_id='.$this->member->getKey());

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $mine->getKey());
    }

    #[Test]
    public function a_search_filter_treats_wildcards_as_literal_text(): void
    {
        $this->makeTask($this->project, ['title' => 'Ordinary work']);
        $literal = $this->makeTask($this->project, ['title' => 'Discount 100% applied']);

        $response = $this->asToken($this->owner, $this->workspace)
            ->getJson('/api/v1/tasks?q='.urlencode('100%'));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $literal->getKey());
    }

    #[Test]
    public function a_non_numeric_id_never_reaches_a_query(): void
    {
        $this->asToken($this->owner, $this->workspace)
            ->getJson('/api/v1/tasks/not-an-id')
            ->assertStatus(404);
    }

    #[Test]
    public function it_writes_and_reads_a_comment_thread(): void
    {
        $task = $this->makeTask($this->project);

        $created = $this->asToken($this->member, $this->workspace)
            ->postJson('/api/v1/tasks/'.$task->getKey().'/comments', ['body' => 'Looks right to me.']);

        $created->assertCreated();
        $created->assertJsonPath('data.author.id', $this->member->getKey());
        $created->assertJsonPath('data.author_type', 'user');

        $reply = $this->asToken($this->owner, $this->workspace)
            ->postJson('/api/v1/tasks/'.$task->getKey().'/comments', [
                'body' => 'Agreed.',
                'parent_id' => $created->json('data.id'),
            ]);

        $reply->assertCreated();
        $reply->assertJsonPath('data.parent_id', $created->json('data.id'));

        $thread = $this->asToken($this->owner, $this->workspace)
            ->getJson('/api/v1/tasks/'.$task->getKey().'/comments');

        $thread->assertOk();
        $thread->assertJsonCount(2, 'data');
    }

    #[Test]
    public function comment_bodies_are_sanitised_on_the_way_in(): void
    {
        $task = $this->makeTask($this->project);

        $response = $this->asToken($this->owner, $this->workspace)
            ->postJson('/api/v1/tasks/'.$task->getKey().'/comments', [
                'body' => '<p>Fine</p><script>alert(1)</script>',
            ]);

        $response->assertCreated();

        $body = (string) $response->json('data.body');

        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringContainsString('Fine', $body);
    }

    #[Test]
    public function creating_a_task_does_not_lazy_load(): void
    {
        // `Model::preventLazyLoading()` is on outside production, so an N+1 in a resource is
        // an exception rather than a slow page. Listing with every relation the resource can
        // render is the cheapest way to keep that honest.
        $this->makeTask($this->project);

        $this->asToken($this->owner, $this->workspace)
            ->getJson('/api/v1/tasks')
            ->assertOk();

        $this->assertSame(1, Task::query()->count());
    }
}

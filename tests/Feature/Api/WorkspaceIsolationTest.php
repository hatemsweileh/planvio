<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\WorkspaceRole;
use App\Models\Comment;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The one that matters: a member of workspace A must not reach workspace B through the API.
 *
 * Cross-workspace access is a security bug (ARCHITECTURE.md §3), and an API is where it is
 * easiest to introduce — there is no navigation to constrain the caller, so every id in every
 * URL and every body is an opportunity to name a record from somewhere else.
 *
 * Three attack shapes are covered, because they fail differently:
 *
 *   1. **A foreign id with your own header.** `GET /tasks/{B's id}` while scoped to A. The
 *      controller's workspace-scoped lookup has to reject it.
 *   2. **A foreign header.** Naming B in `X-Planvio-Workspace` while holding A's token. The
 *      middleware's membership check has to reject it.
 *   3. **A foreign id inside a body.** `assignee_id`, `project_id`, `status_id`,
 *      `milestone_id`, `parent_id` — each is a reference the API resolves, and a resolver
 *      that skipped the workspace would write a cross-tenant row that every later check
 *      waves through, because by then it is holding a model.
 *
 * Every one of them must answer 404 rather than 403. A 403 confirms that the record exists,
 * which is most of what somebody probing an id space wants to know.
 */
final class WorkspaceIsolationTest extends ApiTestCase
{
    use RefreshDatabase;

    private Workspace $alpha;

    private Workspace $beta;

    /** An owner of alpha — the most privileged role there is, and still an outsider in beta. */
    private User $insider;

    private Project $betaProject;

    private Task $betaTask;

    private Milestone $betaMilestone;

    private Comment $betaComment;

    private TimeEntry $betaEntry;

    private Tag $betaTag;

    private User $betaMember;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->makeWorkspace(['slug' => 'alpha']);
        $this->beta = $this->makeWorkspace(['slug' => 'beta']);

        $this->insider = $this->makeMember($this->alpha, WorkspaceRole::Owner);
        $this->betaMember = $this->makeMember($this->beta, WorkspaceRole::Owner);

        $this->betaProject = $this->makeProject($this->beta, [], ['slug' => 'secret', 'key' => 'SEC']);
        $this->betaTask = $this->makeTask($this->betaProject, ['title' => 'Confidential acquisition plan']);

        $this->betaMilestone = Milestone::factory()->create([
            'workspace_id' => $this->beta->getKey(),
            'project_id' => $this->betaProject->getKey(),
        ]);

        $this->betaComment = Comment::factory()->create([
            'workspace_id' => $this->beta->getKey(),
            'commentable_type' => $this->betaTask->getMorphClass(),
            'commentable_id' => $this->betaTask->getKey(),
            'user_id' => $this->betaMember->getKey(),
        ]);

        $this->betaEntry = TimeEntry::factory()->create([
            'workspace_id' => $this->beta->getKey(),
            'project_id' => $this->betaProject->getKey(),
            'task_id' => $this->betaTask->getKey(),
            'user_id' => $this->betaMember->getKey(),
        ]);

        $this->betaTag = Tag::factory()->create([
            'workspace_id' => $this->beta->getKey(),
            'name' => 'Board only',
        ]);
    }

    /* ------------------------------------------------------------------ *
     * 1. A foreign id, with the caller's own workspace header
     * ------------------------------------------------------------------ */

    /**
     * Each write carries a body that would pass validation, so the 404 that comes back is the
     * record lookup refusing and not the validator: a 422 would prove nothing about tenancy.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function foreignRecordUrls(): array
    {
        return [
            'read a project' => ['getJson', '/api/v1/projects/{project}', []],
            'update a project' => ['patchJson', '/api/v1/projects/{project}', ['name' => 'Renamed']],
            'delete a project' => ['deleteJson', '/api/v1/projects/{project}', []],
            'read a task' => ['getJson', '/api/v1/tasks/{task}', []],
            'update a task' => ['patchJson', '/api/v1/tasks/{task}', ['title' => 'Renamed']],
            'delete a task' => ['deleteJson', '/api/v1/tasks/{task}', []],
            'read a milestone' => ['getJson', '/api/v1/milestones/{milestone}', []],
            'update a milestone' => ['patchJson', '/api/v1/milestones/{milestone}', ['name' => 'Renamed']],
            'delete a milestone' => ['deleteJson', '/api/v1/milestones/{milestone}', []],
            'read a comment' => ['getJson', '/api/v1/comments/{comment}', []],
            'update a comment' => ['patchJson', '/api/v1/comments/{comment}', ['body' => 'Rewritten']],
            'delete a comment' => ['deleteJson', '/api/v1/comments/{comment}', []],
            'read a time entry' => ['getJson', '/api/v1/time-entries/{entry}', []],
            'update a time entry' => ['patchJson', '/api/v1/time-entries/{entry}', ['minutes' => 15]],
            'delete a time entry' => ['deleteJson', '/api/v1/time-entries/{entry}', []],
            'read a tag' => ['getJson', '/api/v1/tags/{tag}', []],
            'update a tag' => ['patchJson', '/api/v1/tags/{tag}', ['name' => 'Renamed']],
            'delete a tag' => ['deleteJson', '/api/v1/tags/{tag}', []],
            'read a member' => ['getJson', '/api/v1/users/{user}', []],
            'list a task thread' => ['getJson', '/api/v1/tasks/{task}/comments', []],
            'comment on a task' => ['postJson', '/api/v1/tasks/{task}/comments', ['body' => 'Planted']],
            'assign a task' => ['postJson', '/api/v1/tasks/{task}/assign', ['assignee_id' => null]],
            'move a task' => ['postJson', '/api/v1/tasks/{task}/status', ['status_id' => 1]],
        ];
    }

    #[Test]
    #[DataProvider('foreignRecordUrls')]
    public function a_member_of_one_workspace_cannot_reach_another_workspaces_records(
        string $method,
        string $template,
        array $body,
    ): void {
        $url = strtr($template, [
            '{project}' => (string) $this->betaProject->getKey(),
            '{task}' => (string) $this->betaTask->getKey(),
            '{milestone}' => (string) $this->betaMilestone->getKey(),
            '{comment}' => (string) $this->betaComment->getKey(),
            '{entry}' => (string) $this->betaEntry->getKey(),
            '{tag}' => (string) $this->betaTag->getKey(),
            '{user}' => (string) $this->betaMember->getKey(),
        ]);

        $response = $this->asToken($this->insider, $this->alpha)->{$method}($url, $body);

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'not_found');

        // And nothing of beta's leaked into the sentence that came back.
        $this->assertStringNotContainsString('Confidential', $response->getContent() ?: '');
    }

    /* ------------------------------------------------------------------ *
     * 2. A foreign workspace header
     * ------------------------------------------------------------------ */

    #[Test]
    public function naming_another_workspace_in_the_header_is_a_404_not_a_403(): void
    {
        $response = $this->asToken($this->insider, $this->beta)->getJson('/api/v1/tasks');

        // 404, deliberately: a 403 would confirm that a workspace with this slug exists.
        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'not_found');
    }

    #[Test]
    public function reading_another_workspace_directly_is_a_404(): void
    {
        $this->asToken($this->insider)
            ->getJson('/api/v1/workspaces/beta')
            ->assertStatus(404);
    }

    #[Test]
    public function the_workspace_list_holds_only_the_callers_own(): void
    {
        $response = $this->asToken($this->insider)->getJson('/api/v1/workspaces');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.slug', 'alpha');
    }

    /* ------------------------------------------------------------------ *
     * 3. A foreign id inside a request body
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_task_cannot_be_created_in_another_workspaces_project(): void
    {
        $this->asToken($this->insider, $this->alpha)
            ->postJson('/api/v1/tasks', [
                'project_id' => $this->betaProject->getKey(),
                'title' => 'Planted',
            ])
            ->assertStatus(404);

        $this->assertDatabaseMissing('tasks', ['title' => 'Planted']);
    }

    #[Test]
    public function a_task_cannot_be_assigned_to_someone_from_another_workspace(): void
    {
        $project = $this->makeProject($this->alpha);
        $task = $this->makeTask($project);

        $this->asToken($this->insider, $this->alpha)
            ->postJson('/api/v1/tasks/'.$task->getKey().'/assign', [
                'assignee_id' => $this->betaMember->getKey(),
            ])
            ->assertStatus(404);

        $this->assertNull($task->fresh()?->assignee_id);
    }

    #[Test]
    public function a_task_cannot_be_moved_into_another_workspaces_column(): void
    {
        $project = $this->makeProject($this->alpha);
        $task = $this->makeTask($project);

        $foreignColumn = TaskStatus::withoutWorkspaceScope()
            ->where('project_id', $this->betaProject->getKey())
            ->firstOrFail();

        $this->asToken($this->insider, $this->alpha)
            ->postJson('/api/v1/tasks/'.$task->getKey().'/status', [
                'status_id' => $foreignColumn->getKey(),
            ])
            ->assertStatus(404);

        $this->assertNotSame((int) $foreignColumn->getKey(), (int) $task->fresh()?->status_id);
    }

    #[Test]
    public function a_task_cannot_be_hung_off_another_workspaces_milestone(): void
    {
        $project = $this->makeProject($this->alpha);
        $task = $this->makeTask($project);

        $this->asToken($this->insider, $this->alpha)
            ->patchJson('/api/v1/tasks/'.$task->getKey(), [
                'milestone_id' => $this->betaMilestone->getKey(),
            ])
            ->assertStatus(404);

        $this->assertNull($task->fresh()?->milestone_id);
    }

    #[Test]
    public function a_reply_cannot_be_grafted_onto_another_workspaces_thread(): void
    {
        $project = $this->makeProject($this->alpha);
        $task = $this->makeTask($project);

        $this->asToken($this->insider, $this->alpha)
            ->postJson('/api/v1/tasks/'.$task->getKey().'/comments', [
                'body' => 'Planted reply',
                'parent_id' => $this->betaComment->getKey(),
            ])
            ->assertStatus(404);

        $this->assertDatabaseMissing('comments', ['body' => 'Planted reply']);
    }

    #[Test]
    public function time_cannot_be_logged_against_another_workspaces_project(): void
    {
        $this->asToken($this->insider, $this->alpha)
            ->postJson('/api/v1/time-entries', [
                'project_id' => $this->betaProject->getKey(),
                'minutes' => 30,
            ])
            ->assertStatus(404);

        $this->assertSame(1, TimeEntry::withoutWorkspaceScope()->count());
    }

    #[Test]
    public function an_ai_run_cannot_be_scoped_to_another_workspaces_project(): void
    {
        $this->asToken($this->insider, $this->alpha)
            ->postJson('/api/v1/ai/runs', [
                'objective' => 'Summarise the acquisition plan',
                'project_id' => $this->betaProject->getKey(),
            ])
            ->assertStatus(404);
    }

    /* ------------------------------------------------------------------ *
     * Lists: nothing foreign, and no count that says something exists
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, array{0: string}>
     */
    public static function collectionUrls(): array
    {
        return [
            'projects' => ['/api/v1/projects'],
            'tasks' => ['/api/v1/tasks'],
            'milestones' => ['/api/v1/milestones'],
            'time entries' => ['/api/v1/time-entries'],
            'tags' => ['/api/v1/tags'],
            'users' => ['/api/v1/users'],
            'activity' => ['/api/v1/activity'],
        ];
    }

    #[Test]
    #[DataProvider('collectionUrls')]
    public function no_list_leaks_a_row_from_another_workspace(string $url): void
    {
        $response = $this->asToken($this->insider, $this->alpha)->getJson($url);

        $response->assertOk();

        foreach ((array) $response->json('data') as $row) {
            if (is_array($row) && array_key_exists('workspace_id', $row) && $row['workspace_id'] !== null) {
                $this->assertSame(
                    (int) $this->alpha->getKey(),
                    (int) $row['workspace_id'],
                    'A row from another workspace appeared in '.$url.'. This is a tenancy breach.',
                );
            }
        }

        $body = $response->getContent() ?: '';

        $this->assertStringNotContainsString('Confidential', $body);
        $this->assertStringNotContainsString($this->betaMember->email, $body);
    }

    #[Test]
    public function search_does_not_cross_the_workspace_boundary(): void
    {
        $response = $this->asToken($this->insider, $this->alpha)
            ->getJson('/api/v1/search?q=Confidential');

        $response->assertOk();
        $response->assertJsonPath('data.total', 0);
        $this->assertStringNotContainsString('Confidential acquisition plan', $response->getContent() ?: '');
    }
}

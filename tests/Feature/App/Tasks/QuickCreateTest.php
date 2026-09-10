<?php

declare(strict_types=1);

namespace Tests\Feature\App\Tasks;

use App\Enums\Priority;
use App\Enums\StatusCategory;
use App\Enums\WorkspaceRole;
use App\Livewire\App\Tasks\QuickCreate;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick create.
 *
 * It sits on every page in the product, so the first test here is about what it costs when
 * nobody is using it. The rest are about the select being a suggestion rather than an
 * authorisation: the project, the column and the assignee are all re-checked on the server.
 */
final class QuickCreateTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $member;

    private Project $project;

    private TaskStatus $todo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->makeWorkspace(['slug' => 'acme']);
        $this->member = $this->makeMember($this->workspace, WorkspaceRole::Owner);
        $this->project = $this->makeProject($this->workspace, [], ['slug' => 'website', 'key' => 'WEB']);

        $this->todo = TaskStatus::factory()->create([
            'project_id' => $this->project->getKey(),
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'To Do',
            'category' => StatusCategory::Todo,
            'position' => 1,
            'is_default' => true,
        ]);
    }

    #[Test]
    public function it_renders_nothing_and_asks_nothing_until_it_is_opened(): void
    {
        $this->quickCreate()
            ->assertSet('open', false)
            ->assertDontSee('Create task')
            ->assertDontSee($this->project->name);
    }

    #[Test]
    public function opening_it_offers_the_projects_this_person_can_add_to(): void
    {
        $this->quickCreate()
            ->call('openQuickCreate', 'task')
            ->assertSet('open', true)
            ->assertSet('projectId', (int) $this->project->getKey())
            ->assertSet('statusId', (int) $this->todo->getKey())
            ->assertSee($this->project->name);
    }

    #[Test]
    public function it_stays_out_of_the_way_for_other_kinds_of_record(): void
    {
        $this->quickCreate()
            ->call('openQuickCreate', 'milestone')
            ->assertSet('open', false);
    }

    #[Test]
    public function it_creates_the_task_through_the_action(): void
    {
        $other = $this->makeMember($this->workspace);
        $due = Carbon::today()->addWeek()->toDateString();

        $this->quickCreate()
            ->call('openQuickCreate', 'task', (int) $this->project->getKey())
            ->set('title', 'Draft the launch email')
            ->set('assigneeId', (int) $other->getKey())
            ->set('priority', Priority::High->value)
            ->set('dueDate', $due)
            ->call('create')
            ->assertSet('open', false)
            ->assertDispatched('task-created')
            ->assertDispatched('planvio-notify');

        $task = Task::withoutWorkspaceScope()->firstWhere('title', 'Draft the launch email');

        $this->assertNotNull($task);
        $this->assertSame((int) $this->project->getKey(), (int) $task->project_id);
        $this->assertSame((int) $this->todo->getKey(), (int) $task->status_id);
        $this->assertSame((int) $other->getKey(), (int) $task->assignee_id);
        $this->assertSame(Priority::High, $task->priority);
        $this->assertSame($due, $task->due_date?->toDateString());

        // CreateTask makes the reporter and the assignee watchers, which is what the
        // notification promises depend on.
        $this->assertTrue($task->watchers()->whereKey($this->member->getKey())->exists());
        $this->assertTrue($task->watchers()->whereKey($other->getKey())->exists());
    }

    #[Test]
    public function create_and_add_another_keeps_the_sheet_open(): void
    {
        $this->quickCreate()
            ->call('openQuickCreate', 'task')
            ->set('title', 'First')
            ->call('create', true)
            ->assertSet('open', true)
            ->assertSet('title', '');

        $this->assertDatabaseHas('tasks', ['title' => 'First']);
    }

    #[Test]
    public function a_title_is_required(): void
    {
        $this->quickCreate()
            ->call('openQuickCreate', 'task')
            ->set('title', '   ')
            ->call('create')
            ->assertSet('open', true)
            ->assertSee('Give the task a title.');

        $this->assertSame(0, Task::withoutWorkspaceScope()->count());
    }

    #[Test]
    public function a_project_this_person_cannot_add_to_is_not_offered(): void
    {
        // A guest holds no task.create anywhere.
        $guest = $this->makeMember($this->workspace, WorkspaceRole::Guest);
        $this->project->members()->attach($guest->getKey(), [
            'role' => 'guest', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingInWorkspace($guest, $this->workspace);

        Livewire::test(QuickCreate::class)
            ->call('openQuickCreate', 'task')
            ->assertSet('projectId', null)
            ->assertSee('No project to put it in');
    }

    #[Test]
    public function a_project_from_another_workspace_can_never_be_selected(): void
    {
        $foreignProject = $this->makeProject($this->makeWorkspace(['slug' => 'northwind']));

        $this->quickCreate()
            ->call('openQuickCreate', 'task', (int) $foreignProject->getKey())
            // The requested project is not one this person may use here, so the component
            // falls back to a project they actually hold.
            ->assertSet('projectId', (int) $this->project->getKey());
    }

    #[Test]
    public function the_ai_route_hands_the_sentence_to_the_assistant_rather_than_guessing(): void
    {
        $this->quickCreate()
            ->call('openQuickCreate', 'task')
            ->set('mode', 'describe')
            ->set('sentence', 'Draft the launch email, due Friday')
            ->call('describe')
            ->assertSet('open', false)
            ->assertDispatched('open-ai-panel');

        $this->assertSame(0, Task::withoutWorkspaceScope()->count());
    }

    private function quickCreate(): Testable
    {
        $this->actingInWorkspace($this->member, $this->workspace);

        return Livewire::test(QuickCreate::class);
    }
}

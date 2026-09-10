<?php

declare(strict_types=1);

namespace Tests\Feature\App;

use App\Enums\MilestoneStatus;
use App\Enums\Priority;
use App\Enums\ProjectHealth;
use App\Enums\StatusCategory;
use App\Enums\WorkspaceRole;
use App\Livewire\App\Inbox\Index as Inbox;
use App\Livewire\App\MyTasks\Index as MyTasks;
use App\Models\Activity;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The three personal screens: the dashboard, the inbox and My Tasks.
 *
 * The assertion that matters most here is the one about query counts. All three pages are
 * built out of aggregates and capped selects rather than loops, and the property that buys —
 * a cost that does not grow with the size of the workspace — is invisible in a screenshot
 * and easy to lose in a later edit. So it is measured: the same page is rendered against a
 * small workspace and against one with several times the data, and the two counts have to
 * match exactly.
 *
 * The rest asserts the security contract these components carry. Nothing here is scoped by
 * the workspace alone: a notification is filtered by its reader, and a task by whether its
 * project is one the viewer can open.
 */
final class HomeInboxMyTasksTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $member;

    /** @var list<Project> */
    private array $projects = [];

    protected function setUp(): void
    {
        parent::setUp();

        // A fixed "now" so "overdue", "today" and "the next fortnight" mean the same thing
        // on every run. The dashboard's whole vocabulary is relative to the current day.
        Carbon::setTestNow(Carbon::parse('2026-09-08 09:00:00'));

        $this->workspace = $this->makeWorkspace(['slug' => 'acme', 'timezone' => 'UTC']);
        $this->member = $this->makeMember($this->workspace, WorkspaceRole::Owner, ['timezone' => 'UTC']);

        $this->app->make(CurrentWorkspace::class)->set($this->workspace);

        $colleagues = [];

        for ($i = 0; $i < 4; $i++) {
            $colleagues[] = $this->makeMember($this->workspace, WorkspaceRole::Member);
        }

        for ($p = 0; $p < 5; $p++) {
            $this->projects[] = $this->seedProject($p, $colleagues);
        }

        $this->seedNotifications();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ *
     * Cost
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_dashboard_costs_the_same_however_much_work_there_is(): void
    {
        // Warm anything cached per process — the settings cache, the compiled views — so the
        // measurement is of the page rather than of the first request in the suite.
        $this->render('app.home');

        $small = $this->countQueries('app.home');

        $this->multiplyTheWork();

        $large = $this->countQueries('app.home');

        $this->assertSame(
            $small,
            $large,
            "The dashboard issued {$small} queries against a small workspace and {$large} against a large one. "
            .'Every figure on that page has to come from an aggregate or a capped select; a count that '
            .'grows with the data means something is looping.',
        );
    }

    #[Test]
    public function the_inbox_and_my_tasks_cost_the_same_however_much_there_is(): void
    {
        $this->render('app.inbox');
        $this->render('app.my-tasks');

        $inboxSmall = $this->countQueries('app.inbox');
        $tasksSmall = $this->countQueries('app.my-tasks');

        $this->multiplyTheWork();
        $this->seedNotifications();

        $this->assertSame($inboxSmall, $this->countQueries('app.inbox'));
        $this->assertSame($tasksSmall, $this->countQueries('app.my-tasks'));
    }

    /* ------------------------------------------------------------------ *
     * My Tasks
     * ------------------------------------------------------------------ */

    #[Test]
    public function my_tasks_changes_status_and_priority_in_place(): void
    {
        $task = Task::query()->where('assignee_id', $this->member->id)->firstOrFail();
        $project = Project::query()->findOrFail($task->project_id);
        $done = TaskStatus::query()
            ->where('project_id', $project->id)
            ->where('is_completed', true)
            ->firstOrFail();

        $component = Livewire::actingAs($this->member)
            ->test(MyTasks::class, ['workspace' => $this->workspace])
            ->assertOk();

        $component->call('setStatus', $task->id, $done->id);
        $this->assertNotNull($task->fresh()->completed_at, 'Moving into a completed column must stamp completed_at.');

        $component->call('setPriority', $task->id, 'urgent');
        $this->assertSame(Priority::Urgent, $task->fresh()->priority);

        $component->call('toggleComplete', $task->id);
        $this->assertNull($task->fresh()->completed_at, 'Reopening must clear the completion stamp.');
    }

    #[Test]
    public function my_tasks_quick_adds_a_task_assigned_to_the_person_adding_it(): void
    {
        $project = $this->projects[0];

        // The factory writes task numbers straight into the column without advancing the
        // project's counter, so the allocator would collide with seeded rows. Real creation
        // always goes through CreateTask, which owns both sides.
        DB::table('projects')->where('id', $project->id)->update(['task_number_seq' => 500]);

        Livewire::actingAs($this->member)
            ->test(MyTasks::class, ['workspace' => $this->workspace])
            ->set('newTitle', 'Write the release notes')
            ->set('newProjectId', $project->id)
            ->set('newDueDate', '2026-09-20')
            ->set('newPriority', 'high')
            ->call('quickAdd')
            ->assertHasNoErrors();

        $created = Task::query()->where('title', 'Write the release notes')->firstOrFail();

        $this->assertSame($this->member->id, $created->assignee_id);
        $this->assertSame(Priority::High, $created->priority);
        $this->assertSame('2026-09-20', $created->due_date?->toDateString());
    }

    #[Test]
    public function my_tasks_refuses_a_project_the_person_cannot_open(): void
    {
        $stranger = $this->makeWorkspace(['slug' => 'northwind']);
        $foreign = $this->makeProject($stranger, [], ['slug' => 'secret', 'key' => 'SEC']);

        Livewire::actingAs($this->member)
            ->test(MyTasks::class, ['workspace' => $this->workspace])
            ->set('newTitle', 'Should never exist')
            ->set('newProjectId', $foreign->id)
            ->call('quickAdd')
            ->assertHasErrors('newProjectId');

        $this->assertDatabaseMissing('tasks', ['title' => 'Should never exist']);
    }

    #[Test]
    public function every_my_tasks_tab_renders(): void
    {
        $component = Livewire::actingAs($this->member)
            ->test(MyTasks::class, ['workspace' => $this->workspace]);

        foreach (['today', 'upcoming', 'overdue', 'completed', 'all'] as $tab) {
            $component->call('selectTab', $tab)->assertOk();
        }

        $component->call('selectTab', 'nonsense');
        $this->assertSame('today', $component->get('tab'), 'An unknown tab must fall back, not filter on nothing.');
    }

    /* ------------------------------------------------------------------ *
     * Inbox
     * ------------------------------------------------------------------ */

    #[Test]
    public function marking_all_read_clears_only_what_the_filter_is_showing(): void
    {
        $component = Livewire::actingAs($this->member)
            ->test(Inbox::class, ['workspace' => $this->workspace])
            ->call('selectFilter', 'mentions');

        $this->assertGreaterThan(0, $this->unread('comment.mentioned'));
        $this->assertGreaterThan(0, $this->unread('task.assigned'));

        $component->call('markAllRead');

        $this->assertSame(0, $this->unread('comment.mentioned'));
        $this->assertGreaterThan(
            0,
            $this->unread('task.assigned'),
            'A filtered "mark all read" must not clear the categories it is not showing.',
        );
    }

    #[Test]
    public function a_row_can_be_marked_read_and_unread_again(): void
    {
        $row = DB::table('notifications')
            ->where('notifiable_id', $this->member->id)
            ->whereNull('read_at')
            ->first();

        $component = Livewire::actingAs($this->member)
            ->test(Inbox::class, ['workspace' => $this->workspace]);

        $component->call('markRead', $row->id);
        $this->assertNotNull(DB::table('notifications')->where('id', $row->id)->value('read_at'));

        $component->call('markUnread', $row->id);
        $this->assertNull(DB::table('notifications')->where('id', $row->id)->value('read_at'));
    }

    #[Test]
    public function every_inbox_filter_renders(): void
    {
        $component = Livewire::actingAs($this->member)
            ->test(Inbox::class, ['workspace' => $this->workspace]);

        foreach (['all', 'mentions', 'assigned', 'comments', 'updates', 'invitations', 'ai'] as $filter) {
            $component->call('selectFilter', $filter)->assertOk();
        }

        $component->call('selectFilter', 'nonsense');
        $this->assertSame('all', $component->get('filter'));
    }

    /* ------------------------------------------------------------------ *
     * Boundaries
     * ------------------------------------------------------------------ */

    #[Test]
    public function nobody_can_read_or_touch_another_persons_inbox(): void
    {
        $other = $this->makeMember($this->workspace, WorkspaceRole::Member);

        $row = DB::table('notifications')
            ->where('notifiable_id', $this->member->id)
            ->whereNull('read_at')
            ->first();

        Livewire::actingAs($other)
            ->test(Inbox::class, ['workspace' => $this->workspace])
            ->assertOk()
            ->assertDontSee('Something happened 1')
            ->call('markRead', $row->id);

        $this->assertNull(
            DB::table('notifications')->where('id', $row->id)->value('read_at'),
            'A notification addressed to somebody else was marked read. The inbox must filter on the reader first.',
        );
    }

    #[Test]
    public function a_guest_sees_nothing_from_the_projects_they_are_not_in(): void
    {
        $guest = $this->makeMember($this->workspace, WorkspaceRole::Guest);

        // Assigned to the guest, but in a project they were never added to.
        $task = Task::query()->where('project_id', $this->projects[0]->id)->firstOrFail();
        $task->forceFill(['assignee_id' => $guest->id])->save();

        Livewire::actingAs($guest)
            ->test(MyTasks::class, ['workspace' => $this->workspace])
            ->assertOk()
            ->assertDontSee($task->title);

        $this->actingAs($guest)->get(route('app.home', $this->workspace))->assertOk();
    }

    /* ------------------------------------------------------------------ *
     * Seeding and helpers
     * ------------------------------------------------------------------ */

    /**
     * @param list<User> $colleagues
     */
    private function seedProject(int $index, array $colleagues): Project
    {
        $project = $this->makeProject($this->workspace, [], [
            'slug' => 'p'.$index,
            'key' => 'P'.$index,
            'name' => 'Project '.$index,
            'health' => [ProjectHealth::OnTrack, ProjectHealth::AtRisk, ProjectHealth::OffTrack][$index % 3],
            'target_date' => Carbon::parse('2026-09-08')->addDays($index + 2),
            'progress' => 10 * $index,
        ]);

        foreach ($colleagues as $colleague) {
            $project->members()->attach($colleague->id, [
                'role' => 'member', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $todo = TaskStatus::query()->create([
            'workspace_id' => $this->workspace->id,
            'project_id' => $project->id,
            'name' => 'To Do', 'color' => 'blue', 'category' => StatusCategory::Todo,
            'position' => 1, 'is_default' => true, 'is_completed' => false,
        ]);

        TaskStatus::query()->create([
            'workspace_id' => $this->workspace->id,
            'project_id' => $project->id,
            'name' => 'Completed', 'color' => 'green', 'category' => StatusCategory::Done,
            'position' => 2, 'is_default' => false, 'is_completed' => true,
        ]);

        // Assignees alternate, and the dates are offset so that an *even* index — one the
        // acting member owns — lands on today. A default tab with no rows would make the
        // query-count assertions measure the wrong thing: Laravel skips the fetch and every
        // eager load when a paginator counts zero.
        for ($t = 0; $t < 10; $t++) {
            $this->makeTask($project, [
                'status_id' => $todo->id,
                'assignee_id' => $t % 2 === 0 ? $this->member->id : $colleagues[$t % 4]->id,
                'priority' => [Priority::Low, Priority::High, Priority::Urgent][$t % 3],
                'due_date' => Carbon::parse('2026-09-08')->addDays($t - 4),
                'completed_at' => null,
                'title' => 'Task '.$index.'-'.$t,
            ]);
        }

        Milestone::query()->create([
            'workspace_id' => $this->workspace->id,
            'project_id' => $project->id,
            'name' => 'Milestone '.$index,
            'status' => MilestoneStatus::InProgress,
            'due_date' => Carbon::parse('2026-09-08')->addDays($index),
            'position' => 1,
            'progress' => 20,
        ]);

        for ($a = 0; $a < 4; $a++) {
            Activity::query()->create([
                'workspace_id' => $this->workspace->id,
                'project_id' => $project->id,
                'subject_id' => 1,
                'subject_type' => (new Task)->getMorphClass(),
                'causer_id' => $a % 2 === 0 ? $this->member->id : null,
                'causer_type' => $a % 3 === 0 ? 'ai' : 'user',
                'event' => 'updated',
                'description' => null,
            ]);
        }

        return $project;
    }

    private function seedNotifications(): void
    {
        $categories = ['comment.mentioned', 'task.assigned', 'ai.action_executed', 'workspace.invitation'];

        for ($n = 0; $n < 24; $n++) {
            DB::table('notifications')->insert([
                'id' => (string) Str::uuid(),
                'type' => 'App\\Notifications\\Tasks\\TaskAssigned',
                'notifiable_type' => (new User)->getMorphClass(),
                'notifiable_id' => $this->member->id,
                'data' => json_encode([
                    'title' => 'Something happened '.$n,
                    'body' => 'A body line for row '.$n,
                    'url' => route('app.home', $this->workspace),
                    'actor_name' => 'Ada',
                ]),
                'read_at' => null,
                'workspace_id' => $this->workspace->id,
                'project_id' => null,
                'category' => $categories[$n % 4],
                'is_ai' => $n % 4 === 2 ? 1 : 0,
                'created_at' => now()->subMinutes($n * 30),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Several times the tasks, spread across the same projects and the same fortnight.
     */
    private function multiplyTheWork(): void
    {
        foreach ($this->projects as $project) {
            $status = TaskStatus::query()->where('project_id', $project->id)->firstOrFail();

            for ($t = 0; $t < 25; $t++) {
                $this->makeTask($project, [
                    'status_id' => $status->id,
                    'assignee_id' => $this->member->id,
                    'due_date' => Carbon::parse('2026-09-01')->addDays($t % 18),
                    'title' => 'Extra '.$project->getKey().'-'.$t,
                ]);
            }
        }
    }

    private function render(string $route): void
    {
        $this->actingAs($this->member)->get(route($route, $this->workspace))->assertOk();
    }

    private function countQueries(string $route): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->render($route);

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $count;
    }

    private function unread(string $category): int
    {
        return DB::table('notifications')
            ->where('notifiable_id', $this->member->id)
            ->where('category', $category)
            ->whereNull('read_at')
            ->count();
    }
}

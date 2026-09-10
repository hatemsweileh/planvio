<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Dependencies;

use App\Actions\Dependencies\CreateDependency;
use App\Actions\Dependencies\DeleteDependency;
use App\Actions\Dependencies\DependencyAcrossWorkspaces;
use App\Actions\Dependencies\DependencyCycleDetected;
use App\Actions\Dependencies\DependencyGraph;
use App\Actions\Dependencies\SelfDependency;
use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\CreateTaskData;
use App\Enums\DependencyType;
use App\Enums\StatusCategory;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `task_dependencies` is a directed graph and everything that reads it — the timeline, the
 * blocked-by panel, any scheduling order — assumes it is acyclic. Nothing in the schema
 * enforces that: the unique index rejects a repeated edge, not a loop three edges long.
 * These cases pin the check that does.
 */
final class DependencyCycleTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Project $project;

    private TaskStatus $status;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->makeWorkspace();
        $this->actor = $this->makeMember($this->workspace);
        $this->project = $this->makeProject($this->workspace);

        $this->status = TaskStatus::factory()->asDefault()->create([
            'project_id' => $this->project->getKey(),
            'workspace_id' => $this->workspace->getKey(),
            'category' => StatusCategory::Todo,
        ]);
    }

    /**
     * The case the whole class exists for: A depends on B, B depends on C, and C is then
     * asked to depend on A. Nothing in that ring could ever start.
     */
    #[Test]
    public function it_refuses_a_three_task_cycle(): void
    {
        [$a, $b, $c] = [$this->task('A'), $this->task('B'), $this->task('C')];

        $this->depend($a, $b);
        $this->depend($b, $c);

        try {
            $this->depend($c, $a);
            $this->fail('A -> B -> C -> A was accepted; the dependency graph now contains a cycle.');
        } catch (DependencyCycleDetected $exception) {
            $this->assertSame(
                [(int) $c->getKey(), (int) $a->getKey(), (int) $b->getKey(), (int) $c->getKey()],
                $exception->path,
                'The reported path should be the loop the edge would close.',
            );
        }

        $this->assertSame(2, TaskDependency::query()->count(), 'The refused edge was written anyway.');
    }

    #[Test]
    public function it_refuses_a_two_task_cycle(): void
    {
        [$a, $b] = [$this->task('A'), $this->task('B')];

        $this->depend($a, $b);

        $this->expectException(DependencyCycleDetected::class);

        $this->depend($b, $a);
    }

    #[Test]
    public function it_refuses_a_cycle_however_long_the_chain(): void
    {
        $tasks = [];

        for ($i = 0; $i < 12; $i++) {
            $tasks[] = $this->task('Task '.$i);
        }

        for ($i = 0; $i < 11; $i++) {
            $this->depend($tasks[$i], $tasks[$i + 1]);
        }

        $this->expectException(DependencyCycleDetected::class);

        $this->depend($tasks[11], $tasks[0]);
    }

    /**
     * `relates_to` is stored in the same directed table and walked by the same code, so a
     * ring built out of it hangs the same walks a blocking ring would.
     */
    #[Test]
    public function it_refuses_a_cycle_made_of_non_blocking_edges(): void
    {
        [$a, $b] = [$this->task('A'), $this->task('B')];

        $this->depend($a, $b, DependencyType::RelatesTo);

        $this->expectException(DependencyCycleDetected::class);

        $this->depend($b, $a, DependencyType::RelatesTo);
    }

    #[Test]
    public function the_reported_cycle_names_the_tasks_by_their_display_key(): void
    {
        [$a, $b] = [$this->task('A'), $this->task('B')];

        $this->depend($a, $b);

        try {
            $this->depend($b, $a);
            $this->fail('The cycle was not refused.');
        } catch (DependencyCycleDetected $exception) {
            $key = $this->project->key;

            $this->assertSame(
                "{$key}-2 -> {$key}-1 -> {$key}-2",
                $exception->pathLabel,
            );
        }
    }

    #[Test]
    public function it_refuses_a_task_depending_on_itself(): void
    {
        $task = $this->task('A');

        $this->expectException(SelfDependency::class);

        $this->depend($task, $task);
    }

    #[Test]
    public function it_refuses_an_edge_between_two_workspaces(): void
    {
        $task = $this->task('A');

        $otherWorkspace = $this->makeWorkspace();
        $otherProject = $this->makeProject($otherWorkspace);

        TaskStatus::factory()->asDefault()->create([
            'project_id' => $otherProject->getKey(),
            'workspace_id' => $otherWorkspace->getKey(),
        ]);

        $foreign = $this->app->make(CreateTask::class)(new CreateTaskData(
            project: $otherProject,
            actor: $this->makeMember($otherWorkspace),
            title: 'Somewhere else',
        ));

        $this->expectException(DependencyAcrossWorkspaces::class);

        $this->depend($task, $foreign);
    }

    /**
     * A diamond is not a cycle: two tasks may both wait for the same thing, and one task may
     * wait for two. Refusing those would make the check useless in practice.
     */
    #[Test]
    public function it_allows_a_diamond(): void
    {
        [$a, $b, $c, $d] = [$this->task('A'), $this->task('B'), $this->task('C'), $this->task('D')];

        $this->depend($a, $b);
        $this->depend($a, $c);
        $this->depend($b, $d);
        $this->depend($c, $d);

        $this->assertSame(4, TaskDependency::query()->count());
    }

    #[Test]
    public function asking_twice_for_the_same_edge_returns_the_same_row(): void
    {
        [$a, $b] = [$this->task('A'), $this->task('B')];

        $first = $this->depend($a, $b);
        $second = $this->depend($a, $b);

        $this->assertSame((int) $first->getKey(), (int) $second->getKey());
        $this->assertSame(1, TaskDependency::query()->count());
    }

    #[Test]
    public function asking_again_with_another_type_updates_the_edge_in_place(): void
    {
        [$a, $b] = [$this->task('A'), $this->task('B')];

        $first = $this->depend($a, $b, DependencyType::FinishToStart);
        $second = $this->depend($a, $b, DependencyType::Blocks);

        $this->assertSame((int) $first->getKey(), (int) $second->getKey());
        $this->assertSame(DependencyType::Blocks, $second->fresh()->type);
        $this->assertSame(1, TaskDependency::query()->count());
    }

    /**
     * Removing an edge cannot create a cycle, and it must free the pair for the reverse
     * direction — which is how somebody fixes a dependency they entered backwards.
     */
    #[Test]
    public function deleting_an_edge_frees_the_reverse_direction(): void
    {
        [$a, $b] = [$this->task('A'), $this->task('B')];

        $edge = $this->depend($a, $b);

        $this->app->make(DeleteDependency::class)($edge, $this->actor);

        $reversed = $this->depend($b, $a);

        $this->assertTrue($reversed->exists);
        $this->assertSame(1, TaskDependency::query()->count());
    }

    #[Test]
    public function the_graph_reports_no_path_between_unconnected_tasks(): void
    {
        [$a, $b] = [$this->task('A'), $this->task('B')];

        $this->assertNull(
            $this->app->make(DependencyGraph::class)->pathBetween((int) $a->getKey(), (int) $b->getKey()),
        );
    }

    /**
     * The search must survive a graph that is already broken — that is the one situation
     * where a naive recursive walk never returns. The loop is written straight into the
     * table, bypassing the action that would have refused it.
     */
    #[Test]
    public function the_graph_terminates_on_a_table_that_already_contains_a_loop(): void
    {
        [$a, $b, $c] = [$this->task('A'), $this->task('B'), $this->task('C')];

        foreach ([[$a, $b], [$b, $c], [$c, $a]] as [$from, $to]) {
            TaskDependency::query()->create([
                'workspace_id' => $this->workspace->getKey(),
                'task_id' => $from->getKey(),
                'depends_on_task_id' => $to->getKey(),
                'type' => DependencyType::FinishToStart,
            ]);
        }

        $path = $this->app->make(DependencyGraph::class)
            ->pathBetween((int) $a->getKey(), (int) $c->getKey());

        $this->assertSame([(int) $a->getKey(), (int) $b->getKey(), (int) $c->getKey()], $path);
    }

    private function task(string $title): Task
    {
        return $this->app->make(CreateTask::class)(new CreateTaskData(
            project: $this->project,
            actor: $this->actor,
            title: $title,
            status: $this->status,
        ));
    }

    private function depend(
        Task $task,
        Task $dependsOn,
        DependencyType $type = DependencyType::FinishToStart,
    ): TaskDependency {
        return $this->app->make(CreateDependency::class)($task, $dependsOn, $this->actor, $type);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Tasks;

use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\CreateTaskData;
use App\Actions\Tasks\TaskNumbers;
use App\Enums\StatusCategory;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The per-project task number is the one value in the system that two simultaneous creates
 * can genuinely collide on, so it is worth its own suite.
 *
 * What can be proved in-process is asserted directly. Real OS-level concurrency cannot be
 * staged inside a single SQLite test, so the two mechanisms that make the race impossible
 * are each pinned instead: the read-modify-write is shown to be indivisible and to run
 * inside the transaction that consumes it, and `unique(project_id, number)` is shown to
 * reject a collision outright — which is what would turn a regression in the locking into a
 * loud failure rather than two tasks called WEB-1.
 */
final class TaskNumberAllocationTest extends TestCase
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

    #[Test]
    public function it_numbers_tasks_sequentially_from_one(): void
    {
        $numbers = [];

        for ($i = 0; $i < 25; $i++) {
            $numbers[] = (int) $this->createTask()->number;
        }

        $this->assertSame(range(1, 25), $numbers);
        $this->assertSame(25, (int) $this->project->fresh()->task_number_seq);
    }

    #[Test]
    public function it_never_issues_the_same_number_twice(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $this->createTask();
        }

        $stored = Task::query()->forProject($this->project)->pluck('number')->all();

        $this->assertCount(40, $stored);
        $this->assertCount(40, array_unique($stored), 'A task number was issued twice.');
    }

    /**
     * The second allocation must see the first one's write, not the value the transaction
     * started with. Under MySQL's REPEATABLE READ a plain read would return the snapshot
     * taken when the transaction began — which is precisely the stale read the row lock
     * exists to prevent — and both allocations would hand back the same number.
     */
    #[Test]
    public function a_second_allocation_never_repeats_the_first(): void
    {
        $allocator = $this->app->make(TaskNumbers::class);

        [$first, $second, $third] = DB::transaction(fn (): array => [
            $allocator->allocate($this->project),
            $allocator->allocate($this->project),
            $allocator->allocate($this->project),
        ]);

        $this->assertSame([1, 2, 3], [$first, $second, $third]);
        $this->assertSame(3, (int) $this->project->fresh()->task_number_seq);
    }

    /**
     * An allocation nested inside an open one — the in-process stand-in for a second request
     * arriving mid-flight — still gets a fresh number rather than the one the outer
     * allocation is holding.
     */
    #[Test]
    public function an_allocation_started_while_another_is_open_gets_a_fresh_number(): void
    {
        $allocator = $this->app->make(TaskNumbers::class);

        $outer = null;
        $inner = null;

        DB::transaction(function () use ($allocator, &$outer, &$inner): void {
            $outer = $allocator->allocate($this->project);

            DB::transaction(function () use ($allocator, &$inner): void {
                $inner = $allocator->allocate($this->project);
            });
        });

        $this->assertSame(1, $outer);
        $this->assertSame(2, $inner);
    }

    /**
     * The backstop. If the locking were ever removed, two writers would both compute the
     * same `seq + 1`; this asserts the database refuses the second one rather than storing
     * two tasks that render as the same key.
     */
    #[Test]
    public function the_unique_index_rejects_a_repeated_number(): void
    {
        $task = $this->createTask();

        $this->expectException(QueryException::class);

        Task::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'project_id' => $this->project->getKey(),
            'number' => $task->number,
            'title' => 'Collides with the first task',
            'status_id' => $this->status->getKey(),
            'reporter_id' => $this->actor->getKey(),
            'created_by' => $this->actor->getKey(),
        ]);
    }

    #[Test]
    public function numbering_restarts_in_every_project(): void
    {
        $other = $this->makeProject($this->workspace);

        TaskStatus::factory()->asDefault()->create([
            'project_id' => $other->getKey(),
            'workspace_id' => $this->workspace->getKey(),
        ]);

        $first = $this->createTask();
        $second = $this->createTask();
        $elsewhere = $this->createTask($other);

        $this->assertSame(1, (int) $first->number);
        $this->assertSame(2, (int) $second->number);
        $this->assertSame(1, (int) $elsewhere->number);
    }

    private function createTask(?Project $project = null): Task
    {
        return $this->app->make(CreateTask::class)(new CreateTaskData(
            project: $project ?? $this->project,
            actor: $this->actor,
            title: 'A task',
        ));
    }
}

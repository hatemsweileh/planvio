<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Tasks;

use App\Actions\Tasks\BulkUpdateTasks;
use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\CreateTaskData;
use App\Actions\Tasks\TaskChanges;
use App\Actions\Tasks\TaskStatusNotInProject;
use App\Enums\Priority;
use App\Enums\StatusCategory;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BulkUpdateTasksTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Project $project;

    private TaskStatus $todo;

    private TaskStatus $done;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->makeWorkspace();
        $this->actor = $this->makeMember($this->workspace);
        $this->project = $this->makeProject($this->workspace);

        $this->todo = TaskStatus::factory()->asDefault()->create([
            'project_id' => $this->project->getKey(),
            'workspace_id' => $this->workspace->getKey(),
            'category' => StatusCategory::Todo,
            'position' => 1,
        ]);

        $this->done = TaskStatus::factory()->create([
            'project_id' => $this->project->getKey(),
            'workspace_id' => $this->workspace->getKey(),
            'category' => StatusCategory::Done,
            'is_completed' => true,
            'position' => 2,
        ]);
    }

    #[Test]
    public function it_updates_every_task_and_records_one_activity_each(): void
    {
        $ids = $this->tasks(12);

        $changed = $this->app->make(BulkUpdateTasks::class)(
            $ids,
            TaskChanges::make()->priority(Priority::Urgent),
            $this->actor,
        );

        $this->assertSame(12, $changed);
        $this->assertSame(12, Task::query()->where('priority', Priority::Urgent->value)->count());
        $this->assertSame(12, Activity::query()->where('event', 'updated')->count());
    }

    /**
     * The point of the chunking is that a large bulk edit costs a handful of statements, not
     * two per task. Counting the queries is the only way to hold that.
     */
    #[Test]
    public function it_writes_a_bounded_number_of_statements_per_chunk(): void
    {
        $ids = $this->tasks(20);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->app->make(BulkUpdateTasks::class)(
            $ids,
            TaskChanges::make()->priority(Priority::High),
            $this->actor,
            chunkSize: 10,
        );

        $queries = DB::getRawQueryLog();
        DB::disableQueryLog();

        $writes = array_filter($queries, static function (array $query): bool {
            // Identifier quoting differs per driver, so compare on the bare statement.
            $sql = strtolower(str_replace(['"', '`', '[', ']'], '', (string) $query['raw_query']));

            return str_starts_with($sql, 'update tasks') || str_starts_with($sql, 'insert into activities');
        });

        // Two chunks, each one UPDATE plus one INSERT.
        $this->assertCount(4, $writes, 'Bulk update should write one statement per kind per chunk.');
    }

    #[Test]
    public function tasks_that_already_match_are_left_alone(): void
    {
        $ids = $this->tasks(5);

        $bulk = $this->app->make(BulkUpdateTasks::class);

        $bulk($ids, TaskChanges::make()->priority(Priority::Low), $this->actor);
        $second = $bulk($ids, TaskChanges::make()->priority(Priority::Low), $this->actor);

        $this->assertSame(0, $second);
        $this->assertSame(5, Activity::query()->where('event', 'updated')->count());
    }

    #[Test]
    public function moving_a_batch_into_a_done_column_stamps_the_completion(): void
    {
        $ids = $this->tasks(4);

        $this->app->make(BulkUpdateTasks::class)(
            $ids,
            TaskChanges::make()->status($this->done),
            $this->actor,
        );

        $this->assertSame(4, Task::query()->whereNotNull('completed_at')->count());
        $this->assertSame(4, Task::query()->where('progress', 100)->count());
    }

    /**
     * A task already sitting in the target column keeps the completion date it has. Stamping
     * the whole batch would silently rewrite the history of everything that was already done.
     */
    #[Test]
    public function a_task_already_in_the_target_column_keeps_its_completion_date(): void
    {
        $alreadyDone = $this->app->make(CreateTask::class)(new CreateTaskData(
            project: $this->project,
            actor: $this->actor,
            title: 'Finished last week',
            status: $this->done,
        ));

        $originalCompletedAt = $alreadyDone->fresh()->completed_at;
        $stillOpen = $this->app->make(CreateTask::class)(new CreateTaskData(
            project: $this->project,
            actor: $this->actor,
            title: 'Still open',
        ));

        $this->travelTo(now()->addHour());

        $this->app->make(BulkUpdateTasks::class)(
            [(int) $alreadyDone->getKey(), (int) $stillOpen->getKey()],
            TaskChanges::make()->status($this->done)->priority(Priority::Urgent),
            $this->actor,
        );

        $this->travelBack();

        $this->assertTrue($originalCompletedAt->equalTo($alreadyDone->fresh()->completed_at));
        $this->assertNotNull($stillOpen->fresh()->completed_at);
    }

    #[Test]
    public function it_refuses_a_column_from_another_project(): void
    {
        $other = $this->makeProject($this->workspace);

        $foreign = TaskStatus::factory()->create([
            'project_id' => $other->getKey(),
            'workspace_id' => $this->workspace->getKey(),
        ]);

        $ids = $this->tasks(2);

        $this->expectException(TaskStatusNotInProject::class);

        $this->app->make(BulkUpdateTasks::class)(
            $ids,
            TaskChanges::make()->status($foreign),
            $this->actor,
        );
    }

    #[Test]
    public function an_empty_change_set_does_nothing(): void
    {
        $ids = $this->tasks(3);

        $changed = $this->app->make(BulkUpdateTasks::class)($ids, TaskChanges::make(), $this->actor);

        $this->assertSame(0, $changed);
        $this->assertSame(0, Activity::query()->where('event', 'updated')->count());
    }

    /**
     * @return list<int>
     */
    private function tasks(int $count): array
    {
        $ids = [];
        $create = $this->app->make(CreateTask::class);

        for ($i = 1; $i <= $count; $i++) {
            $ids[] = (int) $create(new CreateTaskData(
                project: $this->project,
                actor: $this->actor,
                title: "Task {$i}",
                status: $this->todo,
            ))->getKey();
        }

        return $ids;
    }
}

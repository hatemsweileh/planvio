<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Tasks;

use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\CreateTaskData;
use App\Actions\Tasks\MoveTask;
use App\Actions\Tasks\ReorderTasks;
use App\Enums\StatusCategory;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Services\TaskOrderingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fractional ordering, and the renumbering that catches it when the decimals run out.
 *
 * The assertions are written against the *order* of the column wherever possible rather
 * than against particular position values, because the order is the thing the product
 * promises; the numbers are an implementation detail that renormalisation is allowed to
 * rewrite at any time.
 */
final class TaskPositionTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Project $project;

    private TaskStatus $todo;

    private TaskStatus $doing;

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
            'name' => 'To do',
            'category' => StatusCategory::Todo,
            'position' => 1,
        ]);

        $this->doing = TaskStatus::factory()->create([
            'project_id' => $this->project->getKey(),
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'Doing',
            'category' => StatusCategory::InProgress,
            'position' => 2,
        ]);
    }

    #[Test]
    public function new_tasks_are_appended_with_a_gap_between_them(): void
    {
        $first = $this->createTask('First');
        $second = $this->createTask('Second');

        $this->assertSame('1000.0000000000', (string) $first->position);
        $this->assertSame('2000.0000000000', (string) $second->position);
    }

    #[Test]
    public function a_drop_between_two_cards_takes_the_midpoint(): void
    {
        $first = $this->createTask('First');
        $second = $this->createTask('Second');
        $third = $this->createTask('Third');

        $this->move($third, $this->todo, $first, $second);

        $this->assertSame('1500.0000000000', (string) $third->fresh()->position);
        $this->assertSame(['First', 'Third', 'Second'], $this->columnTitles($this->todo));
    }

    #[Test]
    public function a_drop_above_the_first_card_stays_above_it(): void
    {
        $first = $this->createTask('First');
        $second = $this->createTask('Second');
        $third = $this->createTask('Third');

        $this->move($third, $this->todo, null, $first);

        $this->assertSame(['Third', 'First', 'Second'], $this->columnTitles($this->todo));
    }

    #[Test]
    public function a_drop_with_no_anchors_lands_at_the_end(): void
    {
        $first = $this->createTask('First');
        $second = $this->createTask('Second');

        $this->move($first, $this->todo, null, null);

        $this->assertSame(['Second', 'First'], $this->columnTitles($this->todo));
    }

    #[Test]
    public function moving_across_columns_carries_the_status_with_it(): void
    {
        $task = $this->createTask('First');

        $this->move($task, $this->doing, null, null);

        $this->assertSame((int) $this->doing->getKey(), (int) $task->fresh()->status_id);
        $this->assertSame([], $this->columnTitles($this->todo));
        $this->assertSame(['First'], $this->columnTitles($this->doing));
    }

    /**
     * The client sends the cards it dropped between, and the server decides everything else.
     * Here the board is stale: it claims a card sits directly under the first one when a
     * second card has since been inserted there. The drop still lands under the anchor the
     * person aimed at, and nothing else in the column is renumbered behind their back.
     */
    #[Test]
    public function a_stale_anchor_pair_does_not_reshuffle_the_column(): void
    {
        $first = $this->createTask('First');
        $second = $this->createTask('Second');
        $third = $this->createTask('Third');
        $fourth = $this->createTask('Fourth');

        $positionsBefore = $this->positions([$first, $second, $third]);

        // The client believes the column is First, Third and drops Fourth between them.
        $this->move($fourth, $this->todo, $first, $third);

        $this->assertSame(['First', 'Fourth', 'Second', 'Third'], $this->columnTitles($this->todo));
        $this->assertSame($positionsBefore, $this->positions([$first, $second, $third]));
    }

    #[Test]
    public function a_card_cannot_be_dropped_against_itself(): void
    {
        $first = $this->createTask('First');
        $second = $this->createTask('Second');

        $this->move($second, $this->todo, $second, $second);

        $this->assertSame(['First', 'Second'], $this->columnTitles($this->todo));
    }

    /**
     * The renormalise path.
     *
     * Two neighbours are pushed one unit of the column's precision apart — decimal(20,10),
     * so there is literally no value left between them. The drop must still succeed: the
     * column is renumbered onto fixed steps and the midpoint is taken again.
     */
    #[Test]
    public function an_exhausted_gap_renumbers_the_column_and_still_lands_in_place(): void
    {
        $first = $this->createTask('First');
        $second = $this->createTask('Second');
        $third = $this->createTask('Third');

        $this->setPosition($first, '1.0000000000');
        $this->setPosition($second, '1.0000000001');

        $this->assertTrue(
            $this->app->make(TaskOrderingService::class)->needsRenormalisation(1.0, 1.0000000001),
            'The gap should already be exhausted before the move.',
        );

        $this->move($third, $this->todo, $first, $second);

        $this->assertSame(['First', 'Third', 'Second'], $this->columnTitles($this->todo));

        $positions = $this->positions([$first, $third, $second]);

        $this->assertCount(3, array_unique($positions), 'Renumbering left two cards on the same position.');
        $this->assertSame('1000.0000000000', $positions[0]);
        $this->assertSame('2000.0000000000', $positions[2]);
    }

    #[Test]
    public function repeated_drops_into_the_same_gap_keep_working(): void
    {
        $first = $this->createTask('First');
        $last = $this->createTask('Last');

        $expected = ['First'];

        for ($i = 1; $i <= 45; $i++) {
            $task = $this->createTask("Drop {$i}");
            $this->move($task, $this->todo, $first, $last);
            array_splice($expected, 1, 0, ["Drop {$i}"]);
        }

        $this->assertSame([...$expected, 'Last'], $this->columnTitles($this->todo));
    }

    #[Test]
    public function the_midpoint_of_two_neighbours_sits_strictly_between_them(): void
    {
        $ordering = $this->app->make(TaskOrderingService::class);

        $this->assertSame(1500.0, $ordering->positionBetween(1000.0, 2000.0));
        $this->assertFalse($ordering->needsRenormalisation(1000.0, 2000.0));
    }

    #[Test]
    public function reordering_renumbers_the_whole_column_in_the_order_given(): void
    {
        $first = $this->createTask('First');
        $second = $this->createTask('Second');
        $third = $this->createTask('Third');

        $written = $this->app->make(ReorderTasks::class)(
            $this->todo,
            [(int) $third->getKey(), (int) $first->getKey(), (int) $second->getKey()],
            $this->actor,
        );

        $this->assertSame(3, $written);
        $this->assertSame(['Third', 'First', 'Second'], $this->columnTitles($this->todo));
    }

    /**
     * Ids that are not in the column are discarded, and cards the caller forgot keep their
     * relative order at the end rather than falling off the board.
     */
    #[Test]
    public function reordering_ignores_unknown_ids_and_keeps_the_ones_left_out(): void
    {
        $first = $this->createTask('First');
        $second = $this->createTask('Second');
        $third = $this->createTask('Third');

        $this->app->make(ReorderTasks::class)(
            $this->todo,
            [(int) $third->getKey(), 987654],
            $this->actor,
        );

        $this->assertSame(['Third', 'First', 'Second'], $this->columnTitles($this->todo));
    }

    private function createTask(string $title): Task
    {
        return $this->app->make(CreateTask::class)(new CreateTaskData(
            project: $this->project,
            actor: $this->actor,
            title: $title,
            status: $this->todo,
        ));
    }

    private function move(Task $task, TaskStatus $status, ?Task $before, ?Task $after): Task
    {
        return $this->app->make(MoveTask::class)(
            $task,
            $status,
            $before === null ? null : (int) $before->getKey(),
            $after === null ? null : (int) $after->getKey(),
            $this->actor,
        );
    }

    /**
     * @return list<string>
     */
    private function columnTitles(TaskStatus $status): array
    {
        return Task::query()
            ->where('status_id', $status->getKey())
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('title')
            ->all();
    }

    /**
     * @param list<Task> $tasks
     * @return list<string>
     */
    private function positions(array $tasks): array
    {
        return array_map(
            static fn (Task $task): string => (string) $task->fresh()->position,
            $tasks,
        );
    }

    private function setPosition(Task $task, string $position): void
    {
        DB::table('tasks')->where('id', $task->getKey())->update(['position' => $position]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\App\Tasks;

use App\Enums\Priority;
use App\Enums\StatusCategory;
use App\Enums\WorkspaceRole;
use App\Livewire\App\Tasks\TaskBoard;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * The Kanban board.
 *
 * The assertions that matter are about ordering. A drop reports two neighbour ids and the
 * server computes the position between them, so every test here checks the order the
 * database ends up in rather than what the component says about it — the board is only
 * correct if a reload shows the same thing the drag showed.
 */
final class TaskBoardTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $member;

    private Project $project;

    private TaskStatus $todo;

    private TaskStatus $doing;

    private TaskStatus $done;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->makeWorkspace(['slug' => 'acme']);
        $this->member = $this->makeMember($this->workspace, WorkspaceRole::Owner);
        $this->project = $this->makeProject($this->workspace, [], ['slug' => 'website', 'key' => 'WEB']);

        $this->todo = $this->column('To Do', StatusCategory::Todo, 1, ['is_default' => true]);
        $this->doing = $this->column('In Progress', StatusCategory::InProgress, 2);
        $this->done = $this->column('Completed', StatusCategory::Done, 3);
    }

    /* ------------------------------------------------------------------ *
     * Rendering
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_board_draws_a_column_per_status_with_its_count(): void
    {
        $this->task('First', $this->todo, 1000);
        $this->task('Second', $this->todo, 2000);
        $this->task('Underway', $this->doing, 1000);

        $this->board()
            ->assertSee('To Do')
            ->assertSee('In Progress')
            ->assertSee('Completed')
            ->assertSee('First')
            ->assertSee('Second')
            ->assertSee('Underway');
    }

    #[Test]
    public function a_project_with_no_columns_says_so_rather_than_showing_nothing(): void
    {
        TaskStatus::query()->where('project_id', $this->project->getKey())->delete();

        $this->board()->assertSee('This project has no columns');
    }

    #[Test]
    public function completed_work_is_hidden_until_it_is_asked_for(): void
    {
        $closed = $this->task('Shipped', $this->done, 1000);
        $closed->forceFill(['completed_at' => Carbon::now()])->save();

        $this->board()
            ->assertDontSee('Shipped')
            ->set('includeCompleted', true)
            ->assertSee('Shipped');
    }

    /* ------------------------------------------------------------------ *
     * Moving a card — the contract with the drag handler
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_card_dropped_between_two_others_lands_between_them(): void
    {
        $a = $this->task('A', $this->todo, 1000);
        $b = $this->task('B', $this->todo, 2000);
        $c = $this->task('C', $this->todo, 3000);
        $mover = $this->task('Mover', $this->todo, 4000);

        // Dropped under A and above B: the two ids the browser reports, never an index.
        $this->board()->call('moveTask', $mover->getKey(), $this->todo->getKey(), $a->getKey(), $b->getKey());

        $this->assertSame(
            ['A', 'Mover', 'B', 'C'],
            $this->orderIn($this->todo),
            'The card did not land between the two neighbours the drop reported.',
        );
    }

    #[Test]
    public function a_card_dropped_at_the_top_of_a_column_goes_first(): void
    {
        $first = $this->task('First', $this->todo, 1000);
        $this->task('Second', $this->todo, 2000);
        $mover = $this->task('Mover', $this->todo, 3000);

        // No card above it, `first` below it.
        $this->board()->call('moveTask', $mover->getKey(), $this->todo->getKey(), null, $first->getKey());

        $this->assertSame(['Mover', 'First', 'Second'], $this->orderIn($this->todo));
    }

    #[Test]
    public function a_card_dropped_at_the_bottom_goes_last(): void
    {
        $this->task('First', $this->todo, 1000);
        $last = $this->task('Last', $this->todo, 2000);
        $mover = $this->task('Mover', $this->todo, 500);

        $this->board()->call('moveTask', $mover->getKey(), $this->todo->getKey(), $last->getKey(), null);

        $this->assertSame(['First', 'Last', 'Mover'], $this->orderIn($this->todo));
    }

    #[Test]
    public function moving_a_card_to_another_column_changes_its_status_and_its_place(): void
    {
        $top = $this->task('Already here', $this->doing, 1000);
        $mover = $this->task('Mover', $this->todo, 1000);

        $this->board()->call('moveTask', $mover->getKey(), $this->doing->getKey(), $top->getKey(), null);

        $mover->refresh();

        $this->assertSame((int) $this->doing->getKey(), (int) $mover->status_id);
        $this->assertSame(['Already here', 'Mover'], $this->orderIn($this->doing));
        $this->assertSame([], $this->orderIn($this->todo));
    }

    #[Test]
    public function moving_a_card_into_a_completed_column_closes_it(): void
    {
        $mover = $this->task('Mover', $this->todo, 1000);

        $this->board()->call('moveTask', $mover->getKey(), $this->done->getKey(), null, null);

        $mover->refresh();

        $this->assertNotNull($mover->completed_at, 'A task in a done column must carry a completion stamp.');
        $this->assertSame(100, $mover->progress);
    }

    #[Test]
    public function a_stale_anchor_still_lands_the_card_in_the_right_column(): void
    {
        $real = $this->task('Real', $this->doing, 1000);
        $mover = $this->task('Mover', $this->todo, 1000);

        // The browser names a card that no longer exists. The move must still happen, and
        // must not invent a position from the fiction.
        $this->board()->call('moveTask', $mover->getKey(), $this->doing->getKey(), 999999, null);

        $mover->refresh();

        $this->assertSame((int) $this->doing->getKey(), (int) $mover->status_id);
        $this->assertContains('Mover', $this->orderIn($this->doing));
        $this->assertContains('Real', $this->orderIn($this->doing));
    }

    #[Test]
    public function a_task_from_another_project_cannot_be_dropped_onto_this_board(): void
    {
        $otherProject = $this->makeProject($this->workspace, [], ['slug' => 'other', 'key' => 'OTH']);
        $foreign = $this->makeTask($otherProject, ['title' => 'Foreign']);
        $before = (int) $foreign->status_id;

        $this->board()->call('moveTask', $foreign->getKey(), $this->todo->getKey(), null, null);

        $this->assertSame($before, (int) $foreign->refresh()->status_id);
    }

    #[Test]
    public function the_status_menu_moves_a_card_without_any_dragging(): void
    {
        $mover = $this->task('Mover', $this->todo, 1000);

        $this->board()->call('moveToStatus', $mover->getKey(), $this->doing->getKey());

        $this->assertSame((int) $this->doing->getKey(), (int) $mover->refresh()->status_id);
    }

    #[Test]
    public function a_guest_cannot_move_a_card(): void
    {
        $guest = $this->makeMember($this->workspace, WorkspaceRole::Guest);
        $this->project->members()->attach($guest->getKey(), [
            'role' => 'guest', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $mover = $this->task('Mover', $this->todo, 1000);

        $this->inWorkspace($this->workspace, function () use ($guest, $mover): void {
            Livewire::actingAs($guest)
                ->test(TaskBoard::class, ['workspace' => $this->workspace, 'project' => $this->project])
                ->call('moveTask', $mover->getKey(), $this->doing->getKey(), null, null)
                ->assertForbidden();
        });

        $this->assertSame((int) $this->todo->getKey(), (int) $mover->refresh()->status_id);
    }

    /* ------------------------------------------------------------------ *
     * Paging and filtering
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_column_loads_one_page_of_cards_and_grows_on_demand(): void
    {
        $page = (int) config('planvio.pagination.board_column');

        for ($n = 1; $n <= $page + 3; $n++) {
            $this->task('Card '.$n, $this->todo, $n * 1000);
        }

        $component = $this->board();

        $component->assertSee('Card 1')->assertDontSee('Card '.($page + 3));

        $component->call('loadMore', $this->todo->getKey())->assertSee('Card '.($page + 3));
    }

    #[Test]
    public function the_filter_bar_narrows_every_column_at_once(): void
    {
        $this->task('Urgent thing', $this->todo, 1000, ['priority' => Priority::Urgent]);
        $this->task('Ordinary thing', $this->doing, 1000);

        $this->board()
            ->set('priorities', [Priority::Urgent->value])
            ->assertSee('Urgent thing')
            ->assertDontSee('Ordinary thing');
    }

    #[Test]
    public function every_filter_is_carried_in_the_url_so_a_board_can_be_shared(): void
    {
        $reflection = new ReflectionClass(TaskBoard::class);

        foreach ([
            'search', 'statusIds', 'assigneeIds', 'priorities', 'tagIds',
            'milestoneIds', 'dueRange', 'overdueOnly', 'unassignedOnly', 'includeCompleted',
        ] as $property) {
            $this->assertNotEmpty(
                $reflection->getProperty($property)->getAttributes(Url::class),
                "The `{$property}` filter is not in the URL, so a filtered board cannot be linked to.",
            );
        }
    }

    /**
     * The board shares its filter bar with the list, so it inherits the same exposure: the
     * due chip prints its label out of a map keyed by the range, and the range comes from a
     * query string anyone can edit.
     */
    #[Test]
    public function an_unknown_due_range_in_the_query_string_is_discarded(): void
    {
        $this->task('Design the header', $this->todo, 1);

        $this->inWorkspace($this->workspace, fn () => Livewire::actingAs($this->member)
            ->withQueryParams(['due' => 'whenever'])
            ->test(TaskBoard::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->assertSet('dueRange', '')
            ->assertSee('Design the header'));
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    private function board(): Testable
    {
        return $this->inWorkspace($this->workspace, fn () => Livewire::actingAs($this->member)
            ->test(TaskBoard::class, ['workspace' => $this->workspace, 'project' => $this->project]));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function column(string $name, StatusCategory $category, int $position, array $attributes = []): TaskStatus
    {
        return TaskStatus::factory()->create([
            'project_id' => $this->project->getKey(),
            'workspace_id' => $this->workspace->getKey(),
            'name' => $name,
            'color' => $category->color(),
            'category' => $category,
            'position' => $position,
            'is_completed' => $category->isClosed(),
        ] + $attributes);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function task(string $title, TaskStatus $status, float $position, array $attributes = []): Task
    {
        return $this->makeTask($this->project, [
            'title' => $title,
            'status_id' => $status->getKey(),
            'position' => $position,
        ] + $attributes);
    }

    /**
     * The titles in a column, in the order the database would hand them back.
     *
     * @return list<string>
     */
    private function orderIn(TaskStatus $status): array
    {
        return Task::withoutWorkspaceScope()
            ->where('status_id', $status->getKey())
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('title')
            ->all();
    }
}

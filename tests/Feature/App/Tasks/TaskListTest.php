<?php

declare(strict_types=1);

namespace Tests\Feature\App\Tasks;

use App\Enums\Priority;
use App\Enums\StatusCategory;
use App\Enums\ViewType;
use App\Enums\WorkspaceRole;
use App\Livewire\App\Tasks\TaskList;
use App\Models\Activity;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\SavedView;
use App\Models\Tag;
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
 * The task list.
 *
 * Two things are worth asserting here beyond "it renders": that a filter really narrows the
 * query rather than merely hiding rows, and that a bulk edit refuses the tasks the acting
 * person may not touch. Both are places where a list view quietly becomes a security hole.
 */
final class TaskListTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $member;

    private Project $project;

    private TaskStatus $todo;

    private TaskStatus $doing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->makeWorkspace(['slug' => 'acme']);
        $this->member = $this->makeMember($this->workspace, WorkspaceRole::Owner);
        $this->project = $this->makeProject($this->workspace, [], ['slug' => 'website', 'key' => 'WEB']);

        $this->todo = $this->column('To Do', StatusCategory::Todo, 1, ['is_default' => true]);
        $this->doing = $this->column('In Progress', StatusCategory::InProgress, 2);
    }

    /* ------------------------------------------------------------------ *
     * Rendering
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_lists_the_projects_tasks(): void
    {
        $this->task('Design the header', $this->todo);
        $this->task('Wire the footer', $this->doing);

        $this->list()
            ->assertSee('Design the header')
            ->assertSee('Wire the footer')
            ->assertSee('WEB-');
    }

    #[Test]
    public function an_empty_project_offers_the_next_action_rather_than_a_blank_table(): void
    {
        $this->list()
            ->assertSee('No tasks yet')
            ->assertSee('New task');
    }

    #[Test]
    public function filtering_to_nothing_says_so_and_offers_a_way_back(): void
    {
        $this->task('Design the header', $this->todo);

        $this->list()
            ->set('search', 'nothing matches this')
            ->assertSee('Nothing matches these filters')
            ->assertSee('Clear filters');
    }

    /* ------------------------------------------------------------------ *
     * Filters
     * ------------------------------------------------------------------ */

    #[Test]
    public function search_matches_the_title_and_the_task_key(): void
    {
        $header = $this->task('Design the header', $this->todo);
        $this->task('Wire the footer', $this->todo);

        $this->list()->set('search', 'header')->assertSee('Design the header')->assertDontSee('Wire the footer');
        $this->list()->set('search', 'WEB-'.$header->number)->assertSee('Design the header')->assertDontSee('Wire the footer');
    }

    #[Test]
    public function the_status_filter_narrows_the_query(): void
    {
        $this->task('In backlog', $this->todo);
        $this->task('Underway', $this->doing);

        $this->list()
            ->set('statusIds', [$this->doing->getKey()])
            ->assertSee('Underway')
            ->assertDontSee('In backlog');
    }

    #[Test]
    public function unassigned_only_and_assignee_are_both_honoured(): void
    {
        $other = $this->makeMember($this->workspace);

        $this->task('Nobody has this', $this->todo);
        $this->task('Theirs', $this->todo, ['assignee_id' => $other->getKey()]);

        $this->list()->set('unassignedOnly', true)
            ->assertSee('Nobody has this')->assertDontSee('Theirs');

        $this->list()->set('assigneeIds', [$other->getKey()])
            ->assertSee('Theirs')->assertDontSee('Nobody has this');
    }

    #[Test]
    public function the_overdue_filter_excludes_everything_that_is_not_late(): void
    {
        $this->task('Late', $this->todo, ['due_date' => Carbon::today()->subWeek()->toDateString()]);
        $this->task('Soon', $this->todo, ['due_date' => Carbon::today()->addWeek()->toDateString()]);

        $this->list()->set('overdueOnly', true)->assertSee('Late')->assertDontSee('Soon');
    }

    #[Test]
    public function the_tag_filter_requires_every_selected_tag(): void
    {
        $urgent = Tag::factory()->named('Urgent')->create(['workspace_id' => $this->workspace->getKey()]);
        $client = Tag::factory()->named('Client')->create(['workspace_id' => $this->workspace->getKey()]);

        $both = $this->task('Both tags', $this->todo);
        $one = $this->task('One tag', $this->todo);

        $both->tags()->attach([$urgent->getKey(), $client->getKey()]);
        $one->tags()->attach($urgent->getKey());

        $this->list()
            ->set('tagIds', [$urgent->getKey(), $client->getKey()])
            ->assertSee('Both tags')
            ->assertDontSee('One tag');
    }

    #[Test]
    public function completed_tasks_are_hidden_until_asked_for(): void
    {
        $done = $this->column('Completed', StatusCategory::Done, 3);
        $task = $this->task('Shipped', $done);
        $task->forceFill(['completed_at' => Carbon::now()])->save();

        $this->list()->assertDontSee('Shipped')->set('includeCompleted', true)->assertSee('Shipped');
    }

    /* ------------------------------------------------------------------ *
     * Sorting and grouping
     * ------------------------------------------------------------------ */

    #[Test]
    public function sorting_by_a_column_toggles_direction_on_a_second_click(): void
    {
        $this->list()
            ->call('sortBy', 'title')
            ->assertSet('sort', 'title')
            ->assertSet('direction', 'asc')
            ->call('sortBy', 'title')
            ->assertSet('direction', 'desc');
    }

    #[Test]
    public function sorting_by_priority_uses_the_enum_weight_not_the_alphabet(): void
    {
        $this->task('Least', $this->todo, ['priority' => Priority::None]);
        $this->task('Most', $this->todo, ['priority' => Priority::Urgent]);

        $component = $this->list()->call('sortBy', 'priority')->assertSet('direction', 'asc');

        $titles = collect($component->instance()->tasks->items())->pluck('title')->all();

        $this->assertSame(['Least', 'Most'], $titles);
    }

    #[Test]
    public function grouping_splits_the_page_into_labelled_groups(): void
    {
        $this->task('In backlog', $this->todo);
        $this->task('Underway', $this->doing);

        $component = $this->list()->call('groupBy', 'status')->assertSet('group', 'status');

        $labels = collect($component->instance()->groups)->pluck('label')->all();

        $this->assertContains('To Do', $labels);
        $this->assertContains('In Progress', $labels);
    }

    #[Test]
    public function a_column_can_be_hidden_but_the_key_and_title_cannot(): void
    {
        $component = $this->list()
            ->call('toggleColumn', 'milestone')
            ->assertSet('hiddenColumns', ['milestone']);

        $component->call('toggleColumn', 'title')->assertSet('hiddenColumns', ['milestone']);
    }

    /* ------------------------------------------------------------------ *
     * Inline edits
     * ------------------------------------------------------------------ */

    #[Test]
    public function status_priority_and_assignee_can_be_changed_from_the_row(): void
    {
        $task = $this->task('Design the header', $this->todo);
        $other = $this->makeMember($this->workspace);

        $this->list()
            ->call('setStatus', $task->getKey(), $this->doing->getKey())
            ->call('setPriority', $task->getKey(), Priority::Urgent->value)
            ->call('setAssignee', $task->getKey(), $other->getKey());

        $task->refresh();

        $this->assertSame((int) $this->doing->getKey(), (int) $task->status_id);
        $this->assertSame(Priority::Urgent, $task->priority);
        $this->assertSame((int) $other->getKey(), (int) $task->assignee_id);
    }

    /* ------------------------------------------------------------------ *
     * Bulk edits
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_bulk_status_change_touches_every_selected_task(): void
    {
        $one = $this->task('One', $this->todo);
        $two = $this->task('Two', $this->todo);

        $this->list()
            ->set('selected', [$one->getKey(), $two->getKey()])
            ->call('bulkStatus', $this->doing->getKey())
            ->assertSet('selected', [])
            ->assertDispatched('planvio-notify');

        $this->assertSame((int) $this->doing->getKey(), (int) $one->refresh()->status_id);
        $this->assertSame((int) $this->doing->getKey(), (int) $two->refresh()->status_id);
    }

    #[Test]
    public function a_bulk_edit_writes_one_activity_row_per_task(): void
    {
        $one = $this->task('One', $this->todo);
        $two = $this->task('Two', $this->todo);

        $this->list()
            ->set('selected', [$one->getKey(), $two->getKey()])
            ->call('bulkPriority', Priority::High->value);

        $this->assertSame(
            2,
            Activity::withoutWorkspaceScope()
                ->where('subject_type', (new Task)->getMorphClass())
                ->whereIn('subject_id', [$one->getKey(), $two->getKey()])
                ->where('event', 'updated')
                ->count(),
        );
    }

    #[Test]
    public function bulk_tagging_and_bulk_milestone_both_apply(): void
    {
        $tag = Tag::factory()->named('Urgent')->create(['workspace_id' => $this->workspace->getKey()]);
        $milestone = Milestone::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'project_id' => $this->project->getKey(),
            'name' => 'Launch',
        ]);

        $task = $this->task('One', $this->todo);

        $this->list()
            ->set('selected', [$task->getKey()])
            ->call('bulkTag', $tag->getKey())
            ->set('selected', [$task->getKey()])
            ->call('bulkMilestone', $milestone->getKey());

        $task->refresh();

        $this->assertTrue($task->tags()->whereKey($tag->getKey())->exists());
        $this->assertSame((int) $milestone->getKey(), (int) $task->milestone_id);
    }

    #[Test]
    public function a_bulk_delete_removes_only_what_the_actor_may_delete(): void
    {
        $mine = $this->task('Mine', $this->todo, ['assignee_id' => null]);

        $this->list()
            ->set('selected', [$mine->getKey()])
            ->call('bulkDelete')
            ->assertSet('confirmingBulkDelete', false);

        $this->assertSoftDeleted('tasks', ['id' => $mine->getKey()]);
    }

    #[Test]
    public function a_member_who_may_not_edit_a_task_cannot_bulk_edit_it(): void
    {
        // A plain member may only update tasks they report or are assigned to (the `~` cell).
        $plain = $this->makeMember($this->workspace, WorkspaceRole::Member);
        $theirs = $this->task('Not theirs', $this->todo);

        $this->inWorkspace($this->workspace, function () use ($plain, $theirs): void {
            Livewire::actingAs($plain)
                ->test(TaskList::class, ['workspace' => $this->workspace, 'project' => $this->project])
                ->set('selected', [$theirs->getKey()])
                ->call('bulkPriority', Priority::Urgent->value)
                ->assertDispatched('planvio-notify', type: 'error');
        });

        $this->assertSame(Priority::Medium, $theirs->refresh()->priority);
    }

    #[Test]
    public function a_task_from_another_project_cannot_be_selected_into_a_bulk_edit(): void
    {
        $otherProject = $this->makeProject($this->workspace, [], ['slug' => 'other', 'key' => 'OTH']);
        $foreign = $this->makeTask($otherProject, ['title' => 'Foreign', 'priority' => Priority::Medium]);

        $this->list()
            ->set('selected', [$foreign->getKey()])
            ->call('bulkPriority', Priority::Urgent->value);

        $this->assertSame(Priority::Medium, $foreign->refresh()->priority);
    }

    /* ------------------------------------------------------------------ *
     * Query-string hardening
     * ------------------------------------------------------------------ */

    /**
     * The grouping is printed by looking itself up in a label map, so a value the map has
     * never heard of used to reach the view as an array subscript and take the page down
     * with it. Anyone can type `?group=` — an unknown one has to land on "no grouping".
     */
    #[Test]
    public function an_unknown_grouping_in_the_query_string_falls_back_to_no_grouping(): void
    {
        $this->task('Design the header', $this->todo);

        $this->inWorkspace($this->workspace, fn () => Livewire::actingAs($this->member)
            ->withQueryParams(['group' => 'due'])
            ->test(TaskList::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->assertSet('group', 'none')
            ->assertSee('Design the header'));
    }

    #[Test]
    public function an_unknown_sort_or_direction_in_the_query_string_falls_back_to_the_default(): void
    {
        $this->task('Design the header', $this->todo);

        $this->inWorkspace($this->workspace, fn () => Livewire::actingAs($this->member)
            ->withQueryParams(['sort' => 'wat', 'dir' => 'sideways'])
            ->test(TaskList::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->assertSet('sort', 'position')
            ->assertSet('direction', 'asc')
            ->assertSee('Design the header'));
    }

    /**
     * Same shape of bug on the filter bar, which the board shares: the due chip prints its
     * own label out of a map keyed by the range.
     */
    #[Test]
    public function an_unknown_due_range_in_the_query_string_is_discarded(): void
    {
        $this->task('Design the header', $this->todo);

        $this->inWorkspace($this->workspace, fn () => Livewire::actingAs($this->member)
            ->withQueryParams(['due' => 'whenever'])
            ->test(TaskList::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->assertSet('dueRange', '')
            ->assertSee('Design the header'));
    }

    #[Test]
    public function a_recognised_grouping_and_due_range_survive_the_query_string(): void
    {
        $this->task('Design the header', $this->todo);

        $this->inWorkspace($this->workspace, fn () => Livewire::actingAs($this->member)
            ->withQueryParams(['group' => 'status', 'due' => 'week'])
            ->test(TaskList::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->assertSet('group', 'status')
            ->assertSet('dueRange', 'week'));
    }

    /* ------------------------------------------------------------------ *
     * Saved views
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_current_filter_set_can_be_saved_and_loaded_again(): void
    {
        $this->task('Late', $this->todo, ['due_date' => Carbon::today()->subWeek()->toDateString()]);

        $component = $this->list()
            ->set('overdueOnly', true)
            ->set('unassignedOnly', true)
            ->set('newViewName', 'Overdue and unassigned')
            ->call('saveCurrentView');

        $view = SavedView::withoutWorkspaceScope()->firstWhere('name', 'Overdue and unassigned');

        $this->assertNotNull($view);
        $this->assertSame(ViewType::List, $view->type);
        $this->assertTrue($view->filters['overdueOnly']);
        $this->assertTrue($view->filters['unassignedOnly']);

        $component->call('clearFilters')
            ->assertSet('overdueOnly', false)
            ->call('applySavedView', $view->getKey())
            ->assertSet('overdueOnly', true)
            ->assertSet('unassignedOnly', true)
            ->assertSet('appliedViewId', (int) $view->getKey());
    }

    #[Test]
    public function a_saved_view_can_be_pinned_and_deleted(): void
    {
        $this->list()->set('newViewName', 'Mine')->call('saveCurrentView');

        $view = SavedView::withoutWorkspaceScope()->firstWhere('name', 'Mine');

        $this->list()->call('togglePinnedView', $view->getKey());
        $this->assertTrue($view->refresh()->is_pinned);

        $this->list()->call('deleteSavedView', $view->getKey());
        $this->assertDatabaseMissing('saved_views', ['id' => $view->getKey()]);
    }

    #[Test]
    public function a_saved_view_from_another_project_is_not_offered_here(): void
    {
        $otherProject = $this->makeProject($this->workspace, [], ['slug' => 'other', 'key' => 'OTH']);

        SavedView::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'project_id' => $otherProject->getKey(),
            'user_id' => $this->member->getKey(),
            'name' => 'Somewhere else',
            'type' => ViewType::List,
            'filters' => [],
        ]);

        $this->list()->assertDontSee('Somewhere else');
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    private function list(): Testable
    {
        return $this->inWorkspace($this->workspace, fn () => Livewire::actingAs($this->member)
            ->test(TaskList::class, ['workspace' => $this->workspace, 'project' => $this->project]));
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
    private function task(string $title, TaskStatus $status, array $attributes = []): Task
    {
        return $this->makeTask($this->project, [
            'title' => $title,
            'status_id' => $status->getKey(),
        ] + $attributes);
    }
}

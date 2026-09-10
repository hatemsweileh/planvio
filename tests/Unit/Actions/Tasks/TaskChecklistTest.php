<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Tasks;

use App\Actions\Tasks\ChecklistItemNotOnTask;
use App\Actions\Tasks\CreateChecklistItem;
use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\CreateTaskData;
use App\Actions\Tasks\DeleteChecklistItem;
use App\Actions\Tasks\ReorderChecklistItems;
use App\Actions\Tasks\ToggleChecklistItem;
use App\Actions\Tasks\UpdateChecklistItem;
use App\Enums\StatusCategory;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TaskChecklistTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Project $project;

    private User $actor;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->makeWorkspace();
        $this->actor = $this->makeMember($this->workspace);
        $this->project = $this->makeProject($this->workspace);

        TaskStatus::factory()->asDefault()->create([
            'project_id' => $this->project->getKey(),
            'workspace_id' => $this->workspace->getKey(),
            'category' => StatusCategory::Todo,
        ]);

        $this->task = $this->app->make(CreateTask::class)(new CreateTaskData(
            project: $this->project,
            actor: $this->actor,
            title: 'Release checklist',
        ));
    }

    #[Test]
    public function items_are_appended_in_order(): void
    {
        $first = $this->item('Write the notes');
        $second = $this->item('Tag the release');

        $this->assertSame(1, $first->position);
        $this->assertSame(2, $second->position);
    }

    /**
     * Checklist items carry no workspace of their own, so the activity has to be recorded
     * against the task — which is also where anyone reading the feed looks for it.
     */
    #[Test]
    public function adding_an_item_is_recorded_against_the_task(): void
    {
        $item = $this->item('Write the notes');

        $activity = Activity::query()->where('event', 'checklist_item_added')->firstOrFail();

        $this->assertSame($this->task->getMorphClass(), $activity->subject_type);
        $this->assertSame((int) $this->task->getKey(), (int) $activity->subject_id);
        $this->assertSame((int) $item->getKey(), $activity->properties['item_id']);
        $this->assertSame((int) $this->workspace->getKey(), (int) $activity->workspace_id);
    }

    #[Test]
    public function ticking_an_item_records_who_did_it_and_when(): void
    {
        $item = $this->item('Write the notes');

        $this->app->make(ToggleChecklistItem::class)($this->task, $item, true, $this->actor);

        $fresh = $item->fresh();

        $this->assertTrue($fresh->is_done);
        $this->assertNotNull($fresh->completed_at);
        $this->assertSame((int) $this->actor->getKey(), (int) $fresh->completed_by);
    }

    /**
     * Unticking has to clear the marks as well as the flag, or the item claims somebody
     * finished work that is once again outstanding.
     */
    #[Test]
    public function unticking_an_item_clears_the_completion_marks(): void
    {
        $item = $this->item('Write the notes');
        $toggle = $this->app->make(ToggleChecklistItem::class);

        $toggle($this->task, $item, true, $this->actor);
        $toggle($this->task, $item, false, $this->actor);

        $fresh = $item->fresh();

        $this->assertFalse($fresh->is_done);
        $this->assertNull($fresh->completed_at);
        $this->assertNull($fresh->completed_by);
    }

    #[Test]
    public function toggling_to_the_state_it_is_already_in_records_nothing(): void
    {
        $item = $this->item('Write the notes');
        $toggle = $this->app->make(ToggleChecklistItem::class);

        $toggle($this->task, $item, true, $this->actor);
        $toggle($this->task, $item, true, $this->actor);

        $this->assertSame(1, Activity::query()->where('event', 'checklist_item_completed')->count());
    }

    #[Test]
    public function renaming_an_item_to_the_same_title_records_nothing(): void
    {
        $item = $this->item('Write the notes');

        $this->app->make(UpdateChecklistItem::class)($this->task, $item, 'Write the notes', $this->actor);

        $this->assertSame(0, Activity::query()->where('event', 'checklist_item_updated')->count());
    }

    #[Test]
    public function it_refuses_an_item_that_belongs_to_another_task(): void
    {
        $other = $this->app->make(CreateTask::class)(new CreateTaskData(
            project: $this->project,
            actor: $this->actor,
            title: 'A different task',
        ));

        $item = $this->item('Write the notes');

        $this->expectException(ChecklistItemNotOnTask::class);

        $this->app->make(ToggleChecklistItem::class)($other, $item, true, $this->actor);
    }

    #[Test]
    public function deleting_an_item_removes_the_row_but_keeps_the_history(): void
    {
        $item = $this->item('Write the notes');

        $this->app->make(DeleteChecklistItem::class)($this->task, $item, $this->actor);

        $this->assertDatabaseMissing('task_checklist_items', ['id' => $item->getKey()]);
        $this->assertDatabaseHas('activities', ['event' => 'checklist_item_removed']);
    }

    #[Test]
    public function reordering_renumbers_the_list_in_the_order_given(): void
    {
        $first = $this->item('One');
        $second = $this->item('Two');
        $third = $this->item('Three');

        $written = $this->app->make(ReorderChecklistItems::class)(
            $this->task,
            [(int) $third->getKey(), (int) $first->getKey(), (int) $second->getKey()],
            $this->actor,
        );

        $this->assertSame(3, $written);
        $this->assertSame(['Three', 'One', 'Two'], $this->titles());
    }

    #[Test]
    public function reordering_ignores_ids_from_other_tasks(): void
    {
        $first = $this->item('One');
        $second = $this->item('Two');

        $this->app->make(ReorderChecklistItems::class)(
            $this->task,
            [(int) $second->getKey(), 424242],
            $this->actor,
        );

        $this->assertSame(['Two', 'One'], $this->titles());
    }

    private function item(string $title): TaskChecklistItem
    {
        return $this->app->make(CreateChecklistItem::class)($this->task, $title, $this->actor);
    }

    /**
     * @return list<string>
     */
    private function titles(): array
    {
        return TaskChecklistItem::query()
            ->forTask($this->task)
            ->ordered()
            ->pluck('title')
            ->all();
    }
}

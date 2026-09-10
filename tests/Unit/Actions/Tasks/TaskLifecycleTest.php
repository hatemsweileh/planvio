<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Tasks;

use App\Actions\Tasks\AssigneeNotInWorkspace;
use App\Actions\Tasks\AssignTask;
use App\Actions\Tasks\ChangeTaskStatus;
use App\Actions\Tasks\ConvertToSubtask;
use App\Actions\Tasks\CreateSubtask;
use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\CreateTaskData;
use App\Actions\Tasks\DeleteTask;
use App\Actions\Tasks\DuplicateTask;
use App\Actions\Tasks\InvalidTaskAttributes;
use App\Actions\Tasks\RestoreTask;
use App\Actions\Tasks\SubtaskCycleDetected;
use App\Actions\Tasks\TaskChanges;
use App\Actions\Tasks\TaskStatusNotInProject;
use App\Actions\Tasks\UnwatchTask;
use App\Actions\Tasks\UpdateTask;
use App\Actions\Tasks\WatchTask;
use App\Enums\Priority;
use App\Enums\StatusCategory;
use App\Events\Tasks\TaskStatusChanged as TaskStatusChangedEvent;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\Tasks\TaskStatusChanged as TaskStatusChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TaskLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Project $project;

    private TaskStatus $todo;

    private TaskStatus $done;

    private TaskStatus $cancelled;

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

        $this->done = TaskStatus::factory()->create([
            'project_id' => $this->project->getKey(),
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'Done',
            'category' => StatusCategory::Done,
            'is_completed' => true,
            'position' => 2,
        ]);

        $this->cancelled = TaskStatus::factory()->create([
            'project_id' => $this->project->getKey(),
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'Cancelled',
            'category' => StatusCategory::Cancelled,
            'position' => 3,
        ]);
    }

    /* ----------------------------------------------------------------- *
     * Creation
     * ----------------------------------------------------------------- */

    #[Test]
    public function it_creates_a_task_in_the_default_column_with_the_actor_as_reporter(): void
    {
        $task = $this->task('Write the release notes');

        $this->assertSame((int) $this->todo->getKey(), (int) $task->status_id);
        $this->assertSame((int) $this->actor->getKey(), (int) $task->reporter_id);
        $this->assertSame((int) $this->actor->getKey(), (int) $task->created_by);
        $this->assertNull($task->completed_at);
        $this->assertDatabaseHas('activities', [
            'subject_type' => $task->getMorphClass(),
            'subject_id' => $task->getKey(),
            'event' => 'created',
        ]);
    }

    #[Test]
    public function the_reporter_starts_out_watching_the_task(): void
    {
        $task = $this->task('Write the release notes');

        $this->assertTrue($task->watchers()->whereKey($this->actor->getKey())->exists());
    }

    #[Test]
    public function creating_straight_into_a_done_column_stamps_the_completion(): void
    {
        $task = $this->task('Already handled', $this->done);

        $this->assertNotNull($task->completed_at);
        $this->assertSame(100, (int) $task->progress);
    }

    #[Test]
    public function it_refuses_a_column_from_another_project(): void
    {
        $other = $this->makeProject($this->workspace);

        $foreignStatus = TaskStatus::factory()->create([
            'project_id' => $other->getKey(),
            'workspace_id' => $this->workspace->getKey(),
        ]);

        $this->expectException(TaskStatusNotInProject::class);

        $this->task('Wrong board', $foreignStatus);
    }

    #[Test]
    public function it_refuses_a_due_date_before_the_start_date(): void
    {
        $this->expectException(InvalidTaskAttributes::class);

        $this->app->make(CreateTask::class)(new CreateTaskData(
            project: $this->project,
            actor: $this->actor,
            title: 'Backwards',
            startDate: Carbon::parse('2026-03-10'),
            dueDate: Carbon::parse('2026-03-01'),
        ));
    }

    /* ----------------------------------------------------------------- *
     * Status
     * ----------------------------------------------------------------- */

    #[Test]
    public function moving_to_a_done_column_stamps_completed_at_and_moving_back_clears_it(): void
    {
        $task = $this->task('Ship it');
        $change = $this->app->make(ChangeTaskStatus::class);

        $change($task, $this->done, $this->actor);
        $this->assertNotNull($task->fresh()->completed_at);

        $change($task, $this->todo, $this->actor);
        $this->assertNull($task->fresh()->completed_at);
    }

    /**
     * Cancelled closes the task — no further work is expected — but it does not mean the
     * work was finished, so progress is left where it was.
     */
    #[Test]
    public function cancelling_closes_the_task_without_claiming_it_was_finished(): void
    {
        $task = $this->task('Abandoned');

        $this->app->make(ChangeTaskStatus::class)($task, $this->cancelled, $this->actor);

        $fresh = $task->fresh();

        $this->assertNotNull($fresh->completed_at);
        $this->assertSame(0, (int) $fresh->progress);
    }

    #[Test]
    public function moving_to_the_column_a_task_is_already_in_does_nothing(): void
    {
        Event::fake([TaskStatusChangedEvent::class]);

        $task = $this->task('Standing still');

        $this->app->make(ChangeTaskStatus::class)($task, $this->todo, $this->actor);

        Event::assertNotDispatched(TaskStatusChangedEvent::class);
        $this->assertSame(0, Activity::query()->where('event', 'status_changed')->count());
    }

    #[Test]
    public function it_notifies_the_watchers_but_not_the_person_who_moved_it(): void
    {
        Notification::fake();

        $task = $this->task('Ship it');
        $watcher = $this->makeMember($this->workspace);

        $this->app->make(WatchTask::class)($task, $watcher, $watcher);
        $this->app->make(ChangeTaskStatus::class)($task, $this->done, $this->actor);

        Notification::assertSentTo($watcher, TaskStatusChangedNotification::class);
        Notification::assertNotSentTo($this->actor, TaskStatusChangedNotification::class);
    }

    /* ----------------------------------------------------------------- *
     * Assignment
     * ----------------------------------------------------------------- */

    #[Test]
    public function assigning_a_task_makes_the_assignee_a_watcher(): void
    {
        $task = $this->task('Ship it');
        $assignee = $this->makeMember($this->workspace);

        $this->app->make(AssignTask::class)($task, $assignee, $this->actor);

        $this->assertSame((int) $assignee->getKey(), (int) $task->fresh()->assignee_id);
        $this->assertTrue($task->watchers()->whereKey($assignee->getKey())->exists());
    }

    #[Test]
    public function it_refuses_an_assignee_from_outside_the_workspace(): void
    {
        $task = $this->task('Ship it');
        $stranger = User::factory()->create();

        $this->expectException(AssigneeNotInWorkspace::class);

        $this->app->make(AssignTask::class)($task, $stranger, $this->actor);
    }

    #[Test]
    public function reassigning_to_the_same_person_records_nothing(): void
    {
        $task = $this->task('Ship it');
        $assignee = $this->makeMember($this->workspace);
        $assign = $this->app->make(AssignTask::class);

        $assign($task, $assignee, $this->actor);
        $assign($task, $assignee, $this->actor);

        $this->assertSame(1, Activity::query()->where('event', 'assigned')->count());
    }

    #[Test]
    public function unassigning_clears_the_assignee(): void
    {
        $task = $this->task('Ship it');
        $assignee = $this->makeMember($this->workspace);
        $assign = $this->app->make(AssignTask::class);

        $assign($task, $assignee, $this->actor);
        $assign($task, null, $this->actor);

        $this->assertNull($task->fresh()->assignee_id);
        $this->assertSame(1, Activity::query()->where('event', 'unassigned')->count());
    }

    /* ----------------------------------------------------------------- *
     * Editing
     * ----------------------------------------------------------------- */

    #[Test]
    public function it_writes_only_the_columns_that_actually_change(): void
    {
        $task = $this->task('Ship it');

        $this->app->make(UpdateTask::class)(
            $task,
            TaskChanges::make()->title('Ship it')->priority(Priority::Urgent),
            $this->actor,
        );

        $activity = Activity::query()->where('event', 'updated')->firstOrFail();

        $this->assertSame(['priority'], array_keys($activity->properties['changes']));
        $this->assertSame(Priority::Urgent, $task->fresh()->priority);
    }

    #[Test]
    public function an_edit_that_changes_nothing_records_nothing(): void
    {
        $task = $this->task('Ship it');

        $this->app->make(UpdateTask::class)(
            $task,
            TaskChanges::make()->title('Ship it'),
            $this->actor,
        );

        $this->assertSame(0, Activity::query()->where('event', 'updated')->count());
    }

    /**
     * A change set carrying a status is handed to the action that owns the completion rules
     * rather than written as a plain column, so the two paths cannot drift.
     */
    #[Test]
    public function editing_the_status_through_update_still_stamps_the_completion(): void
    {
        $task = $this->task('Ship it');

        $this->app->make(UpdateTask::class)(
            $task,
            TaskChanges::make()->status($this->done)->priority(Priority::High),
            $this->actor,
        );

        $fresh = $task->fresh();

        $this->assertNotNull($fresh->completed_at);
        $this->assertSame(Priority::High, $fresh->priority);
    }

    #[Test]
    public function it_refuses_progress_outside_the_allowed_range(): void
    {
        $task = $this->task('Ship it');

        $this->expectException(InvalidTaskAttributes::class);

        $this->app->make(UpdateTask::class)($task, TaskChanges::make()->progress(140), $this->actor);
    }

    /* ----------------------------------------------------------------- *
     * Subtasks
     * ----------------------------------------------------------------- */

    #[Test]
    public function a_subtask_is_created_under_its_parent(): void
    {
        $parent = $this->task('Parent');

        $subtask = $this->app->make(CreateSubtask::class)($parent, new CreateTaskData(
            project: $this->project,
            actor: $this->actor,
            title: 'Child',
        ));

        $this->assertSame((int) $parent->getKey(), (int) $subtask->parent_id);
        $this->assertDatabaseHas('activities', [
            'subject_id' => $parent->getKey(),
            'event' => 'subtask_created',
        ]);
    }

    #[Test]
    public function a_task_cannot_become_a_subtask_of_its_own_descendant(): void
    {
        $grandparent = $this->task('Grandparent');
        $parent = $this->task('Parent');
        $child = $this->task('Child');

        $convert = $this->app->make(ConvertToSubtask::class);

        $convert($parent, $grandparent, $this->actor);
        $convert($child, $parent, $this->actor);

        $this->expectException(SubtaskCycleDetected::class);

        $convert($grandparent, $child, $this->actor);
    }

    #[Test]
    public function promoting_a_subtask_clears_its_parent(): void
    {
        $parent = $this->task('Parent');
        $child = $this->task('Child');
        $convert = $this->app->make(ConvertToSubtask::class);

        $convert($child, $parent, $this->actor);
        $convert($child, null, $this->actor);

        $this->assertNull($child->fresh()->parent_id);
    }

    /* ----------------------------------------------------------------- *
     * Deletion
     * ----------------------------------------------------------------- */

    #[Test]
    public function deleting_a_task_takes_its_subtasks_with_it(): void
    {
        $parent = $this->task('Parent');
        $child = $this->task('Child');
        $grandchild = $this->task('Grandchild');

        $convert = $this->app->make(ConvertToSubtask::class);
        $convert($child, $parent, $this->actor);
        $convert($grandchild, $child, $this->actor);

        $this->app->make(DeleteTask::class)($parent, $this->actor);

        $this->assertSoftDeleted('tasks', ['id' => $parent->getKey()]);
        $this->assertSoftDeleted('tasks', ['id' => $child->getKey()]);
        $this->assertSoftDeleted('tasks', ['id' => $grandchild->getKey()]);
    }

    /**
     * A subtask deleted on its own earlier is a separate decision, and restoring the parent
     * must not quietly undo it.
     */
    #[Test]
    public function restoring_brings_back_only_what_went_down_together(): void
    {
        $parent = $this->task('Parent');
        $cascaded = $this->task('Cascaded');
        $deletedEarlier = $this->task('Deleted earlier');

        $convert = $this->app->make(ConvertToSubtask::class);
        $convert($cascaded, $parent, $this->actor);
        $convert($deletedEarlier, $parent, $this->actor);

        $delete = $this->app->make(DeleteTask::class);
        $delete($deletedEarlier, $this->actor);

        Carbon::setTestNow(Carbon::now()->addMinute());
        $delete($parent, $this->actor);
        Carbon::setTestNow();

        $this->app->make(RestoreTask::class)($parent->fresh(), $this->actor);

        $this->assertNull(Task::query()->whereKey($parent->getKey())->first()?->deleted_at);
        $this->assertNull(Task::query()->whereKey($cascaded->getKey())->first()?->deleted_at);
        $this->assertSoftDeleted('tasks', ['id' => $deletedEarlier->getKey()]);
    }

    /* ----------------------------------------------------------------- *
     * Duplication and watching
     * ----------------------------------------------------------------- */

    #[Test]
    public function a_copy_gets_its_own_number_and_no_completion(): void
    {
        $task = $this->task('Ship it', $this->done);

        $copy = $this->app->make(DuplicateTask::class)($task, $this->actor);

        $this->assertNotSame((int) $task->number, (int) $copy->number);
        $this->assertNull($copy->completed_at);
        $this->assertSame(0, (int) $copy->progress);
        $this->assertStringContainsString('Ship it', $copy->title);
    }

    #[Test]
    public function a_copy_brings_the_subtasks_across(): void
    {
        $parent = $this->task('Parent');
        $child = $this->task('Child');

        $this->app->make(ConvertToSubtask::class)($child, $parent, $this->actor);

        $copy = $this->app->make(DuplicateTask::class)($parent, $this->actor);

        $this->assertSame(1, Task::query()->where('parent_id', $copy->getKey())->count());
    }

    #[Test]
    public function watching_twice_leaves_one_subscription(): void
    {
        $task = $this->task('Ship it');
        $watcher = $this->makeMember($this->workspace);
        $watch = $this->app->make(WatchTask::class);

        $watch($task, $watcher, $watcher);
        $watch($task, $watcher, $watcher);

        $this->assertSame(1, $task->watchers()->whereKey($watcher->getKey())->count());
        $this->assertSame(1, Activity::query()->where('event', 'watched')->count());
    }

    #[Test]
    public function unwatching_a_task_nobody_watched_records_nothing(): void
    {
        $task = $this->task('Ship it');
        $stranger = $this->makeMember($this->workspace);

        $this->app->make(UnwatchTask::class)($task, $stranger, $stranger);

        $this->assertSame(0, Activity::query()->where('event', 'unwatched')->count());
    }

    private function task(string $title, ?TaskStatus $status = null): Task
    {
        return $this->app->make(CreateTask::class)(new CreateTaskData(
            project: $this->project,
            actor: $this->actor,
            title: $title,
            status: $status,
        ));
    }
}

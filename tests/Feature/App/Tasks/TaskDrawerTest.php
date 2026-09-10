<?php

declare(strict_types=1);

namespace Tests\Feature\App\Tasks;

use App\Enums\DependencyType;
use App\Enums\Priority;
use App\Enums\StatusCategory;
use App\Enums\WorkspaceRole;
use App\Livewire\App\Tasks\Show;
use App\Livewire\App\Tasks\TaskDrawer;
use App\Models\Comment;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskChecklistItem;
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
 * The task detail surface.
 *
 * Everything is asserted through the drawer, and the page is then asserted to behave the
 * same way — they share a trait and a partial, and the point of the last test here is that
 * nothing has quietly forked them.
 */
final class TaskDrawerTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $member;

    private Project $project;

    private TaskStatus $todo;

    private TaskStatus $doing;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->makeWorkspace(['slug' => 'acme']);
        $this->member = $this->makeMember($this->workspace, WorkspaceRole::Owner);
        $this->project = $this->makeProject($this->workspace, [], ['slug' => 'website', 'key' => 'WEB']);

        $this->todo = $this->column('To Do', StatusCategory::Todo, 1, ['is_default' => true]);
        $this->doing = $this->column('In Progress', StatusCategory::InProgress, 2);

        $this->task = $this->makeTask($this->project, [
            'title' => 'Design the header',
            'status_id' => $this->todo->getKey(),
            'description' => '<p>The original description.</p>',
        ]);

        // The factory writes task numbers directly, leaving `projects.task_number_seq`
        // behind. CreateTask allocates from that counter, so it is caught up here or the
        // first subtask created through the product collides on unique(project_id, number).
        $this->project->forceFill([
            'task_number_seq' => (int) Task::withoutWorkspaceScope()
                ->where('project_id', $this->project->getKey())
                ->max('number'),
        ])->save();
    }

    /* ------------------------------------------------------------------ *
     * Opening
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_drawer_costs_nothing_until_a_task_is_opened(): void
    {
        $this->drawer()
            ->assertSet('open', false)
            ->assertSet('taskId', null)
            ->assertDontSee('Design the header');
    }

    #[Test]
    public function opening_a_task_shows_it(): void
    {
        $this->drawer()
            ->call('openTask', $this->task->getKey())
            ->assertSet('open', true)
            ->assertSee('Design the header')
            ->assertSee('WEB-'.$this->task->number)
            ->assertSee('The original description.');
    }

    #[Test]
    public function a_task_from_another_workspace_never_opens(): void
    {
        $foreignProject = $this->makeProject($this->makeWorkspace(['slug' => 'northwind']));
        $foreign = $this->makeTask($foreignProject, ['title' => 'Confidential merger']);

        $this->drawer()
            ->call('openTask', $foreign->getKey())
            ->assertSet('open', false)
            ->assertSet('taskId', null)
            ->assertDontSee('Confidential merger');
    }

    /* ------------------------------------------------------------------ *
     * The inline property grid
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_title_is_editable_in_place(): void
    {
        $this->open()
            ->set('title', 'Design the masthead')
            ->call('saveTitle')
            ->assertDispatched('planvio-notify');

        $this->assertSame('Design the masthead', $this->task->refresh()->title);
    }

    #[Test]
    public function an_empty_title_is_refused_and_the_field_snaps_back(): void
    {
        $this->open()->set('title', '   ')->call('saveTitle')->assertSet('title', 'Design the header');

        $this->assertSame('Design the header', $this->task->refresh()->title);
    }

    #[Test]
    public function status_priority_assignee_and_milestone_all_edit_in_place(): void
    {
        $other = $this->makeMember($this->workspace);
        $milestone = Milestone::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'project_id' => $this->project->getKey(),
            'name' => 'Launch',
        ]);

        $this->open()
            ->call('setStatus', $this->doing->getKey())
            ->call('setPriority', Priority::Urgent->value)
            ->call('setAssignee', $other->getKey())
            ->call('setMilestone', $milestone->getKey());

        $this->task->refresh();

        $this->assertSame((int) $this->doing->getKey(), (int) $this->task->status_id);
        $this->assertSame(Priority::Urgent, $this->task->priority);
        $this->assertSame((int) $other->getKey(), (int) $this->task->assignee_id);
        $this->assertSame((int) $milestone->getKey(), (int) $this->task->milestone_id);
    }

    #[Test]
    public function a_status_from_another_project_is_ignored(): void
    {
        $otherProject = $this->makeProject($this->workspace, [], ['slug' => 'other', 'key' => 'OTH']);
        $foreignStatus = TaskStatus::factory()->create([
            'project_id' => $otherProject->getKey(),
            'workspace_id' => $this->workspace->getKey(),
        ]);

        $this->open()->call('setStatus', $foreignStatus->getKey());

        $this->assertSame((int) $this->todo->getKey(), (int) $this->task->refresh()->status_id);
    }

    #[Test]
    public function the_dates_save_and_an_impossible_pair_is_rejected(): void
    {
        $component = $this->open()
            ->set('startDate', Carbon::today()->toDateString())
            ->set('dueDate', Carbon::today()->addWeek()->toDateString());

        $this->task->refresh();
        $this->assertSame(Carbon::today()->toDateString(), $this->task->start_date?->toDateString());
        $this->assertSame(Carbon::today()->addWeek()->toDateString(), $this->task->due_date?->toDateString());

        // Due before start: the Action refuses and the field is restored from the record.
        $component->set('dueDate', Carbon::today()->subWeek()->toDateString())
            ->assertDispatched('planvio-notify', type: 'error');

        $this->assertSame(
            Carbon::today()->addWeek()->toDateString(),
            $this->task->refresh()->due_date?->toDateString(),
        );
    }

    #[Test]
    public function the_estimate_is_entered_in_hours_and_stored_in_minutes(): void
    {
        $this->open()->set('estimateHours', '2.5');

        $this->assertSame(150, $this->task->refresh()->estimate_minutes);
    }

    #[Test]
    public function a_tag_can_be_added_and_taken_off_again(): void
    {
        $tag = Tag::factory()->named('Urgent')->create(['workspace_id' => $this->workspace->getKey()]);

        $component = $this->open()->call('toggleTag', $tag->getKey());
        $this->assertTrue($this->task->tags()->whereKey($tag->getKey())->exists());

        $component->call('toggleTag', $tag->getKey());
        $this->assertFalse($this->task->tags()->whereKey($tag->getKey())->exists());
    }

    /* ------------------------------------------------------------------ *
     * Description
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_description_is_re_sanitised_on_the_server(): void
    {
        $this->open()
            ->call('startEditingDescription')
            ->set('descriptionDraft', '<p>Safe copy.</p><script>alert(1)</script>')
            ->call('saveDescription')
            ->assertSet('editingDescription', false);

        $description = (string) $this->task->refresh()->description;

        $this->assertStringContainsString('Safe copy.', $description);
        $this->assertStringNotContainsString('<script', $description);
    }

    /* ------------------------------------------------------------------ *
     * Checklist and subtasks
     * ------------------------------------------------------------------ */

    #[Test]
    public function checklist_items_can_be_added_ticked_renamed_reordered_and_removed(): void
    {
        $component = $this->open()
            ->set('newChecklistTitle', 'Sketch it')
            ->call('addChecklistItem')
            ->assertSet('newChecklistTitle', '')
            ->set('newChecklistTitle', 'Review it')
            ->call('addChecklistItem');

        $items = TaskChecklistItem::query()->forTask($this->task)->ordered()->get();
        $this->assertSame(['Sketch it', 'Review it'], $items->pluck('title')->all());

        $component->call('toggleChecklistItem', $items[0]->getKey());
        $this->assertTrue($items[0]->refresh()->is_done);

        $component->call('startRenamingChecklistItem', $items[0]->getKey())
            ->set('renamingChecklistTitle', 'Sketch the header')
            ->call('saveChecklistItem');
        $this->assertSame('Sketch the header', $items[0]->refresh()->title);

        $component->call('reorderChecklist', [$items[1]->getKey(), $items[0]->getKey()]);
        $this->assertSame(
            [$items[1]->getKey(), $items[0]->getKey()],
            TaskChecklistItem::query()->forTask($this->task)->ordered()->pluck('id')->all(),
        );

        $component->call('deleteChecklistItem', $items[0]->getKey());
        $this->assertDatabaseMissing('task_checklist_items', ['id' => $items[0]->getKey()]);
    }

    #[Test]
    public function a_checklist_item_on_another_task_cannot_be_touched_from_here(): void
    {
        $otherTask = $this->makeTask($this->project, ['title' => 'Other']);
        $item = TaskChecklistItem::query()->create([
            'task_id' => $otherTask->getKey(),
            'title' => 'Theirs',
            'is_done' => false,
            'position' => 1,
        ]);

        $this->open()->call('toggleChecklistItem', $item->getKey());

        $this->assertFalse($item->refresh()->is_done);
    }

    #[Test]
    public function a_subtask_is_created_under_the_open_task(): void
    {
        $this->open()->set('newSubtaskTitle', 'Draft the logo lockup')->call('addSubtask');

        $subtask = Task::withoutWorkspaceScope()->firstWhere('title', 'Draft the logo lockup');

        $this->assertNotNull($subtask);
        $this->assertSame((int) $this->task->getKey(), (int) $subtask->parent_id);
        $this->assertSame((int) $this->project->getKey(), (int) $subtask->project_id);
    }

    /* ------------------------------------------------------------------ *
     * Dependencies
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_dependency_can_be_added_warned_about_and_removed(): void
    {
        $blocker = $this->makeTask($this->project, [
            'title' => 'Sign off the brand',
            'status_id' => $this->todo->getKey(),
        ]);

        $component = $this->open()
            ->set('dependencyType', DependencyType::Blocks->value)
            ->call('addDependency', $blocker->getKey())
            ->assertSee('Sign off the brand')
            ->assertSee('Blocked by');

        $dependency = $this->task->dependencies()->firstOrFail();

        $component->call('removeDependency', $dependency->getKey());

        $this->assertDatabaseMissing('task_dependencies', ['id' => $dependency->getKey()]);
    }

    #[Test]
    public function a_task_cannot_be_made_to_depend_on_itself(): void
    {
        $this->open()
            ->call('addDependency', $this->task->getKey())
            ->assertDispatched('planvio-notify', type: 'error');

        $this->assertSame(0, $this->task->dependencies()->count());
    }

    /* ------------------------------------------------------------------ *
     * Watchers, comments and reactions
     * ------------------------------------------------------------------ */

    #[Test]
    public function watching_is_a_toggle(): void
    {
        $component = $this->open();

        // CreateTask never ran for a factory task, so nobody is watching it yet.
        $component->call('toggleWatch');
        $this->assertTrue($this->task->watchers()->whereKey($this->member->getKey())->exists());

        $component->call('toggleWatch');
        $this->assertFalse($this->task->watchers()->whereKey($this->member->getKey())->exists());
    }

    #[Test]
    public function a_comment_can_be_posted_replied_to_and_reacted_to(): void
    {
        $component = $this->open()
            ->set('commentDraft', 'The header needs the new logo.')
            ->call('postComment')
            ->assertSet('commentDraft', '')
            ->assertSee('The header needs the new logo.');

        $comment = Comment::withoutWorkspaceScope()->latest('id')->firstOrFail();

        $component->call('startReply', $comment->getKey())
            ->set('replyDraft', 'Agreed.')
            ->call('postReply')
            ->assertSet('replyingToId', null)
            ->assertSee('Agreed.');

        $component->call('toggleReaction', $comment->getKey(), '👍');
        $this->assertSame(1, $comment->reactions()->count());

        $component->call('toggleReaction', $comment->getKey(), '👍');
        $this->assertSame(0, $comment->reactions()->count());
    }

    #[Test]
    public function an_unknown_reaction_is_refused(): void
    {
        $component = $this->open()->set('commentDraft', 'Hello.')->call('postComment');
        $comment = Comment::withoutWorkspaceScope()->latest('id')->firstOrFail();

        $component->call('toggleReaction', $comment->getKey(), '<script>');

        $this->assertSame(0, $comment->reactions()->count());
    }

    #[Test]
    public function a_comment_on_another_task_cannot_be_deleted_from_here(): void
    {
        $otherTask = $this->makeTask($this->project, ['title' => 'Other']);

        $comment = Comment::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'commentable_id' => $otherTask->getKey(),
            'commentable_type' => $otherTask->getMorphClass(),
            'user_id' => $this->member->getKey(),
            'body' => '<p>Theirs.</p>',
        ]);

        $this->open()->call('deleteCommentById', $comment->getKey());

        $this->assertDatabaseHas('comments', ['id' => $comment->getKey(), 'deleted_at' => null]);
    }

    /* ------------------------------------------------------------------ *
     * The activity trail
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_activity_tab_reads_back_what_actually_happened(): void
    {
        $this->open()
            ->call('setPriority', Priority::Urgent->value)
            ->set('detailTab', 'activity')
            ->assertSee('updated this task');
    }

    /* ------------------------------------------------------------------ *
     * Deleting
     * ------------------------------------------------------------------ */

    #[Test]
    public function deleting_the_task_closes_the_drawer(): void
    {
        $this->open()
            ->call('confirmTaskDeletion')
            ->assertSet('confirmingTaskDeletion', true)
            ->call('deleteTask')
            ->assertSet('open', false)
            ->assertSet('taskId', null)
            ->assertDispatched('task-deleted');

        $this->assertSoftDeleted('tasks', ['id' => $this->task->getKey()]);
    }

    #[Test]
    public function a_guest_cannot_edit_the_task_they_are_allowed_to_read(): void
    {
        $guest = $this->makeMember($this->workspace, WorkspaceRole::Guest);
        $this->project->members()->attach($guest->getKey(), [
            'role' => 'guest', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->inWorkspace($this->workspace, function () use ($guest): void {
            Livewire::actingAs($guest)
                ->test(TaskDrawer::class)
                ->call('openTask', $this->task->getKey())
                ->assertSee('Design the header')
                ->set('title', 'Renamed by a guest')
                ->call('saveTitle')
                ->assertForbidden();
        });

        $this->assertSame('Design the header', $this->task->refresh()->title);
    }

    /* ------------------------------------------------------------------ *
     * The page and the drawer are the same thing
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_full_page_renders_and_edits_exactly_as_the_drawer_does(): void
    {
        $this->inWorkspace($this->workspace, function (): void {
            Livewire::actingAs($this->member)
                ->test(Show::class, ['workspace' => $this->workspace, 'task' => $this->task])
                ->assertSee('Design the header')
                ->assertSee('The original description.')
                ->call('setPriority', Priority::High->value)
                ->set('newChecklistTitle', 'From the page')
                ->call('addChecklistItem');
        });

        $this->task->refresh();

        $this->assertSame(Priority::High, $this->task->priority);
        $this->assertTrue($this->task->checklistItems()->where('title', 'From the page')->exists());
    }

    #[Test]
    public function the_full_page_sends_you_back_to_the_list_once_the_task_is_gone(): void
    {
        $this->inWorkspace($this->workspace, function (): void {
            Livewire::actingAs($this->member)
                ->test(Show::class, ['workspace' => $this->workspace, 'task' => $this->task])
                ->call('deleteTask')
                ->assertRedirect(route('app.projects.tasks', [$this->workspace, $this->project]));
        });

        $this->assertSoftDeleted('tasks', ['id' => $this->task->getKey()]);
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    private function drawer(): Testable
    {
        // The tenant stays bound for the whole test, the way SetCurrentWorkspace binds it
        // for the whole request. Binding it only while the component is constructed would
        // test a state the product never reaches.
        $this->actingInWorkspace($this->member, $this->workspace);

        return Livewire::test(TaskDrawer::class);
    }

    private function open(): Testable
    {
        return $this->drawer()->call('openTask', $this->task->getKey());
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
}

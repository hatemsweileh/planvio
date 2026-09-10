<?php

declare(strict_types=1);

namespace App\Livewire\App\Concerns;

use App\Actions\Attachments\DeleteAttachment;
use App\Actions\Attachments\StoreAttachment;
use App\Actions\Comments\AddReaction;
use App\Actions\Comments\CreateComment;
use App\Actions\Comments\DeleteComment;
use App\Actions\Comments\RemoveReaction;
use App\Actions\Dependencies\CreateDependency;
use App\Actions\Dependencies\DeleteDependency;
use App\Actions\Tags\SyncTags;
use App\Actions\Tasks\CreateChecklistItem;
use App\Actions\Tasks\CreateSubtask;
use App\Actions\Tasks\CreateTaskData;
use App\Actions\Tasks\DeleteChecklistItem;
use App\Actions\Tasks\DeleteTask;
use App\Actions\Tasks\ReorderChecklistItems;
use App\Actions\Tasks\TaskChanges;
use App\Actions\Tasks\ToggleChecklistItem;
use App\Actions\Tasks\UnwatchTask;
use App\Actions\Tasks\UpdateChecklistItem;
use App\Actions\Tasks\UpdateTask;
use App\Actions\Tasks\WatchTask;
use App\Enums\DependencyType;
use App\Enums\Priority;
use App\Exceptions\UploadRejected;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskDependency;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Services\HtmlSanitizer;
use App\Support\CurrentWorkspace;
use DomainException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * Everything a task detail surface can do.
 *
 * There are two of those surfaces — the drawer that slides over the board and the full page
 * a notification link opens — and they must not drift apart. They share this trait and the
 * `_body` partial, so a field added here appears in both, behaves the same in both, and is
 * authorised the same in both.
 *
 * The rules the whole trait obeys:
 *
 *   - **Nothing is written except through an Action.** Every mutation here resolves the
 *     record, asks the Gate, and hands the work to `App\Actions\*`. The component never
 *     touches a column directly, so the activity feed, the notifications and the domain
 *     invariants all happen whichever surface the edit came from.
 *   - **Authorisation is this layer's job.** Actions deliberately do not check it
 *     (ARCHITECTURE.md §2), so every method below starts with `$this->authorize(...)`.
 *   - **Relations are eager loaded, always.** `Model::preventLazyLoading()` is on outside
 *     production and a detail view touches a dozen relations; each is named in
 *     {@see loadedTask()} rather than discovered at render time.
 *   - **HTML from the client is never trusted.** The editor emits markup and the server
 *     re-sanitises it with {@see HtmlSanitizer} before it reaches a column.
 */
trait EditsTask
{
    public ?int $taskId = null;

    /** Inline-editable header. */
    public string $title = '';

    public string $descriptionDraft = '';

    public bool $editingDescription = false;

    public string $startDate = '';

    public string $dueDate = '';

    public string $estimateHours = '';

    public string $newChecklistTitle = '';

    public ?int $renamingChecklistId = null;

    public string $renamingChecklistTitle = '';

    public string $newSubtaskTitle = '';

    public string $dependencySearch = '';

    public string $dependencyType = DependencyType::Blocks->value;

    public string $commentDraft = '';

    public ?int $replyingToId = null;

    public string $replyDraft = '';

    /** 'comments' or 'activity'. */
    public string $detailTab = 'comments';

    /** @var array<int, TemporaryUploadedFile> */
    public array $newFiles = [];

    public bool $confirmingTaskDeletion = false;

    /** The emoji offered under every comment. Short on purpose: a reaction is a signal. */
    private const REACTIONS = ['👍', '🎉', '👀', '🙏', '❤️'];

    /* ------------------------------------------------------------------ *
     * Reading
     * ------------------------------------------------------------------ */

    /**
     * The task with every relation the detail body touches, or null when nothing is open.
     */
    #[Computed]
    public function task(): ?Task
    {
        if ($this->taskId === null) {
            return null;
        }

        $task = $this->loadedTask();

        if (! $task instanceof Task || ! Gate::allows('view', $task)) {
            return null;
        }

        return $task;
    }

    private function loadedTask(): ?Task
    {
        $task = Task::query()
            ->whereKey($this->taskId)
            ->with([
                'project',
                'status',
                'assignee',
                'reporter',
                'creator',
                'milestone',
                'parent.project',
                'subtasks' => static fn ($query) => $query->orderBy('position')->orderBy('id'),
                'subtasks.status',
                'subtasks.assignee',
                'checklistItems',
                'tags',
                'watchers',
                'attachments.uploader',
                'dependencies.dependsOnTask.status',
                'dependencies.dependsOnTask.project',
                'dependents.task.status',
                'dependents.task.project',
            ])
            ->first();

        if (! $task instanceof Task) {
            return null;
        }

        $project = $task->getRelation('project');

        // Subtasks and dependants share this project, so handing them the instance already
        // in memory is what keeps `$subtask->key` from becoming a query per row.
        foreach ($task->getRelation('subtasks') as $subtask) {
            $subtask->setRelation('project', $project);
        }

        // The body links to workspace-scoped routes, so the tenant has to be a model, not
        // an id. The bound one is used when it is the right one; otherwise it is read.
        $workspace = $this->taskWorkspace();

        if ($workspace instanceof Workspace && (int) $workspace->getKey() === (int) $task->workspace_id) {
            $task->setRelation('workspace', $workspace);
        } else {
            $task->load('workspace');
        }

        return $task;
    }

    /**
     * The comment thread, newest last, with its replies and reactions.
     *
     * @return EloquentCollection<int, Comment>
     */
    #[Computed]
    public function comments(): EloquentCollection
    {
        $task = $this->task;

        if (! $task instanceof Task) {
            return new EloquentCollection;
        }

        return $task->comments()
            ->whereNull('parent_id')
            ->with(['user', 'reactions.user', 'replies.user', 'replies.reactions.user'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * The activity trail. Capped: a task worked on for a year has a feed nobody scrolls.
     *
     * @return EloquentCollection<int, Activity>
     */
    #[Computed]
    public function activities(): EloquentCollection
    {
        $task = $this->task;

        if (! $task instanceof Task) {
            return new EloquentCollection;
        }

        return $task->activities()
            ->with('causer')
            ->latest('id')
            ->limit((int) config('planvio.pagination.activity', 30))
            ->get();
    }

    /**
     * @return EloquentCollection<int, TaskStatus>
     */
    #[Computed]
    public function taskStatuses(): EloquentCollection
    {
        $task = $this->task;

        if (! $task instanceof Task) {
            return new EloquentCollection;
        }

        return TaskStatus::query()->where('project_id', $task->project_id)->ordered()->get();
    }

    /**
     * @return EloquentCollection<int, Milestone>
     */
    #[Computed]
    public function taskMilestones(): EloquentCollection
    {
        $task = $this->task;

        if (! $task instanceof Task) {
            return new EloquentCollection;
        }

        return Milestone::query()->where('project_id', $task->project_id)->ordered()->get(['id', 'name', 'status']);
    }

    /**
     * @return EloquentCollection<int, User>
     */
    #[Computed]
    public function taskMembers(): EloquentCollection
    {
        $workspace = $this->workspaceOfOpenTask();

        if (! $workspace instanceof Workspace) {
            return new EloquentCollection;
        }

        return $workspace->members()
            ->where('users.is_active', true)
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.avatar_path']);
    }

    /**
     * @return EloquentCollection<int, Tag>
     */
    #[Computed]
    public function taskTags(): EloquentCollection
    {
        $workspace = $this->workspaceOfOpenTask();

        if (! $workspace instanceof Workspace) {
            return new EloquentCollection;
        }

        return Tag::query()->forWorkspace($workspace)->ordered()->get(['id', 'name', 'color']);
    }

    /**
     * Candidate tasks for a new dependency: the same project, never this task itself.
     *
     * @return EloquentCollection<int, Task>
     */
    #[Computed]
    public function dependencyCandidates(): EloquentCollection
    {
        $task = $this->task;
        $term = trim($this->dependencySearch);

        if (! $task instanceof Task || mb_strlen($term) < 2) {
            return new EloquentCollection;
        }

        $existing = $task->getRelation('dependencies')
            ->map(static fn (TaskDependency $row): int => (int) $row->depends_on_task_id)
            ->all();

        $candidates = Task::query()
            ->where('project_id', $task->project_id)
            ->whereKeyNot($task->getKey())
            ->when($existing !== [], static fn ($query) => $query->whereKeyNot($existing))
            ->where(static function ($query) use ($term): void {
                $query->where('title', 'like', '%'.$term.'%');

                if (ctype_digit($term)) {
                    $query->orWhere('number', (int) $term);
                }
            })
            ->with('status')
            ->orderBy('number')
            ->limit(8)
            ->get();

        foreach ($candidates as $candidate) {
            $candidate->setRelation('project', $task->getRelation('project'));
        }

        return $candidates;
    }

    /**
     * @return list<string>
     */
    public function reactionChoices(): array
    {
        return self::REACTIONS;
    }

    /**
     * Which blocking dependencies are still open — the reason for the warning strip.
     *
     * @return Collection<int, Task>
     */
    public function blockers(): Collection
    {
        $task = $this->task;

        if (! $task instanceof Task) {
            return new Collection;
        }

        return $task->getRelation('dependencies')
            ->filter(static fn (TaskDependency $row): bool => $row->type->isBlocking())
            ->map(static fn (TaskDependency $row): ?Task => $row->getRelation('dependsOnTask'))
            ->filter(static fn (?Task $blocker): bool => $blocker instanceof Task && ! $blocker->is_completed)
            ->values();
    }

    public function isWatching(): bool
    {
        $task = $this->task;
        $user = auth()->user();

        if (! $task instanceof Task || ! $user instanceof User) {
            return false;
        }

        return $task->getRelation('watchers')->contains('id', $user->getKey());
    }

    /* ------------------------------------------------------------------ *
     * The header
     * ------------------------------------------------------------------ */

    public function saveTitle(): void
    {
        $task = $this->authorizedTask('update');
        $title = trim($this->title);

        if ($title === '') {
            $this->title = $task->title;

            return;
        }

        if ($title === $task->title) {
            return;
        }

        app(UpdateTask::class)($task, TaskChanges::make()->title(mb_substr($title, 0, 255)), $this->actor());

        $this->afterTaskChange(__('Title updated.'));
    }

    public function startEditingDescription(): void
    {
        $task = $this->authorizedTask('update');

        $this->descriptionDraft = (string) $task->description;
        $this->editingDescription = true;
    }

    public function cancelEditingDescription(): void
    {
        $this->editingDescription = false;
        $this->descriptionDraft = '';
    }

    public function saveDescription(): void
    {
        $task = $this->authorizedTask('update');

        // The editor is a convenience, not an authority: whatever markup it produced is
        // re-sanitised here before it can reach the column.
        $clean = app(HtmlSanitizer::class)->sanitize($this->descriptionDraft);

        app(UpdateTask::class)($task, TaskChanges::make()->description($clean === '' ? null : $clean), $this->actor());

        $this->editingDescription = false;
        $this->descriptionDraft = '';

        $this->afterTaskChange(__('Description saved.'));
    }

    /* ------------------------------------------------------------------ *
     * The property grid
     * ------------------------------------------------------------------ */

    public function setStatus(int $statusId): void
    {
        $task = $this->authorizedTask('changeStatus');

        $status = TaskStatus::query()
            ->where('project_id', $task->project_id)
            ->whereKey($statusId)
            ->first();

        if (! $status instanceof TaskStatus) {
            return;
        }

        app(UpdateTask::class)($task, TaskChanges::make()->status($status), $this->actor());

        $this->afterTaskChange(__('Moved to :status', ['status' => $status->name]));
    }

    public function setPriority(string $priority): void
    {
        $task = $this->authorizedTask('update');
        $case = Priority::tryFrom($priority);

        if (! $case instanceof Priority) {
            return;
        }

        app(UpdateTask::class)($task, TaskChanges::make()->priority($case), $this->actor());

        $this->afterTaskChange(__('Priority updated.'));
    }

    public function setAssignee(?int $userId): void
    {
        $task = $this->authorizedTask('assign');
        $assignee = $userId === null ? null : $this->workspaceMember($userId);

        if ($userId !== null && ! $assignee instanceof User) {
            return;
        }

        app(UpdateTask::class)($task, TaskChanges::make()->assignee($assignee), $this->actor());

        $this->afterTaskChange($assignee === null ? __('Unassigned.') : __('Assigned to :name', ['name' => $assignee->name]));
    }

    public function setMilestone(?int $milestoneId): void
    {
        $task = $this->authorizedTask('update');

        $milestone = $milestoneId === null
            ? null
            : Milestone::query()->where('project_id', $task->project_id)->whereKey($milestoneId)->first();

        if ($milestoneId !== null && ! $milestone instanceof Milestone) {
            return;
        }

        app(UpdateTask::class)($task, TaskChanges::make()->milestone($milestone), $this->actor());

        $this->afterTaskChange(__('Milestone updated.'));
    }

    public function updatedStartDate(): void
    {
        $this->saveDate('start');
    }

    public function updatedDueDate(): void
    {
        $this->saveDate('due');
    }

    private function saveDate(string $which): void
    {
        $task = $this->authorizedTask('update');
        $raw = trim($which === 'start' ? $this->startDate : $this->dueDate);
        $date = $raw === '' ? null : $this->parseDate($raw);

        if ($raw !== '' && ! $date instanceof Carbon) {
            $this->hydrateFrom($task);

            return;
        }

        $changes = $which === 'start'
            ? TaskChanges::make()->startDate($date)
            : TaskChanges::make()->dueDate($date);

        try {
            app(UpdateTask::class)($task, $changes, $this->actor());
        } catch (DomainException $exception) {
            $this->hydrateFrom($task->refresh());
            $this->dispatch('planvio-notify', type: 'error', message: $exception->getMessage());

            return;
        }

        $this->afterTaskChange(__('Dates updated.'));
    }

    public function updatedEstimateHours(): void
    {
        $task = $this->authorizedTask('update');
        $raw = trim($this->estimateHours);

        if ($raw !== '' && ! is_numeric($raw)) {
            $this->hydrateFrom($task);

            return;
        }

        $minutes = $raw === '' ? null : (int) round(((float) $raw) * 60);

        if ($minutes !== null && $minutes < 0) {
            $this->hydrateFrom($task);

            return;
        }

        app(UpdateTask::class)($task, TaskChanges::make()->estimateMinutes($minutes), $this->actor());

        $this->afterTaskChange(__('Estimate updated.'));
    }

    public function toggleTag(int $tagId): void
    {
        $task = $this->authorizedTask('update');

        $current = $task->getRelation('tags')->modelKeys();
        $current = array_map(intval(...), $current);

        $next = in_array($tagId, $current, true)
            ? array_values(array_diff($current, [$tagId]))
            : [...$current, $tagId];

        app(SyncTags::class)($task, $next, $this->actor());

        $this->afterTaskChange(__('Tags updated.'));
    }

    /* ------------------------------------------------------------------ *
     * Watchers
     * ------------------------------------------------------------------ */

    public function toggleWatch(): void
    {
        $task = $this->authorizedTask('watch');
        $user = $this->actor();

        if ($this->isWatching()) {
            app(UnwatchTask::class)($task, $user, $user);
            $this->afterTaskChange(__('You are no longer watching this task.'));

            return;
        }

        app(WatchTask::class)($task, $user, $user);

        $this->afterTaskChange(__('You are watching this task.'));
    }

    public function addWatcher(int $userId): void
    {
        $task = $this->authorizedTask('update');
        $watcher = $this->workspaceMember($userId);

        if (! $watcher instanceof User) {
            return;
        }

        app(WatchTask::class)($task, $watcher, $this->actor());

        $this->afterTaskChange(__(':name is now watching.', ['name' => $watcher->name]));
    }

    public function removeWatcher(int $userId): void
    {
        $task = $this->authorizedTask('update');
        $watcher = $this->workspaceMember($userId);

        if (! $watcher instanceof User) {
            return;
        }

        app(UnwatchTask::class)($task, $watcher, $this->actor());

        $this->afterTaskChange(__('Watcher removed.'));
    }

    /* ------------------------------------------------------------------ *
     * Checklist
     * ------------------------------------------------------------------ */

    public function addChecklistItem(): void
    {
        $task = $this->authorizedTask('update');
        $title = trim($this->newChecklistTitle);

        if ($title === '') {
            return;
        }

        app(CreateChecklistItem::class)($task, mb_substr($title, 0, 255), $this->actor());

        $this->newChecklistTitle = '';

        $this->afterTaskChange();
    }

    public function toggleChecklistItem(int $itemId): void
    {
        $task = $this->authorizedTask('update');
        $item = $this->checklistItem($task, $itemId);

        if (! $item instanceof TaskChecklistItem) {
            return;
        }

        app(ToggleChecklistItem::class)($task, $item, ! $item->is_done, $this->actor());

        $this->afterTaskChange();
    }

    public function startRenamingChecklistItem(int $itemId): void
    {
        $task = $this->authorizedTask('update');
        $item = $this->checklistItem($task, $itemId);

        if (! $item instanceof TaskChecklistItem) {
            return;
        }

        $this->renamingChecklistId = $itemId;
        $this->renamingChecklistTitle = $item->title;
    }

    public function saveChecklistItem(): void
    {
        $task = $this->authorizedTask('update');
        $itemId = $this->renamingChecklistId;
        $title = trim($this->renamingChecklistTitle);

        $this->renamingChecklistId = null;
        $this->renamingChecklistTitle = '';

        if ($itemId === null || $title === '') {
            return;
        }

        $item = $this->checklistItem($task, $itemId);

        if (! $item instanceof TaskChecklistItem) {
            return;
        }

        app(UpdateChecklistItem::class)($task, $item, mb_substr($title, 0, 255), $this->actor());

        $this->afterTaskChange();
    }

    public function deleteChecklistItem(int $itemId): void
    {
        $task = $this->authorizedTask('update');
        $item = $this->checklistItem($task, $itemId);

        if (! $item instanceof TaskChecklistItem) {
            return;
        }

        app(DeleteChecklistItem::class)($task, $item, $this->actor());

        $this->afterTaskChange();
    }

    /**
     * @param array<array-key, mixed> $orderedIds
     */
    public function reorderChecklist(array $orderedIds): void
    {
        $task = $this->authorizedTask('update');

        $ids = [];

        foreach ($orderedIds as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        if ($ids === []) {
            return;
        }

        app(ReorderChecklistItems::class)($task, $ids, $this->actor());

        $this->afterTaskChange();
    }

    private function checklistItem(Task $task, int $itemId): ?TaskChecklistItem
    {
        return TaskChecklistItem::query()
            ->where('task_id', $task->getKey())
            ->whereKey($itemId)
            ->first();
    }

    /* ------------------------------------------------------------------ *
     * Subtasks
     * ------------------------------------------------------------------ */

    public function addSubtask(): void
    {
        $task = $this->authorizedTask('update');
        $title = trim($this->newSubtaskTitle);

        if ($title === '') {
            return;
        }

        $project = $task->getRelation('project');

        $this->authorize('create', [Task::class, $project]);

        app(CreateSubtask::class)($task, new CreateTaskData(
            project: $project,
            actor: $this->actor(),
            title: mb_substr($title, 0, 255),
            status: $task->getRelation('status'),
            priority: $task->priority,
        ));

        $this->newSubtaskTitle = '';

        $this->afterTaskChange(__('Subtask added.'));
    }

    /* ------------------------------------------------------------------ *
     * Dependencies
     * ------------------------------------------------------------------ */

    public function addDependency(int $dependsOnTaskId): void
    {
        $task = $this->authorizedTask('update');

        $dependsOn = Task::query()
            ->where('project_id', $task->project_id)
            ->whereKey($dependsOnTaskId)
            ->first();

        if (! $dependsOn instanceof Task) {
            return;
        }

        $this->authorize('view', $dependsOn);

        $type = DependencyType::tryFrom($this->dependencyType) ?? DependencyType::Blocks;

        try {
            app(CreateDependency::class)($task, $dependsOn, $this->actor(), $type);
        } catch (DomainException $exception) {
            $this->dispatch('planvio-notify', type: 'error', message: $exception->getMessage());

            return;
        }

        $this->dependencySearch = '';

        $this->afterTaskChange(__('Dependency added.'));
    }

    public function removeDependency(int $dependencyId): void
    {
        $task = $this->authorizedTask('update');

        $dependency = TaskDependency::query()
            ->where('task_id', $task->getKey())
            ->whereKey($dependencyId)
            ->first();

        if (! $dependency instanceof TaskDependency) {
            return;
        }

        app(DeleteDependency::class)($dependency, $this->actor());

        $this->afterTaskChange(__('Dependency removed.'));
    }

    /* ------------------------------------------------------------------ *
     * Attachments
     * ------------------------------------------------------------------ */

    public function updatedNewFiles(): void
    {
        $task = $this->authorizedTask('attach');
        $stored = 0;

        foreach ($this->newFiles as $file) {
            if (! $file instanceof TemporaryUploadedFile) {
                continue;
            }

            try {
                app(StoreAttachment::class)($task, $file, $this->actor());
                $stored++;
            } catch (UploadRejected $exception) {
                $this->dispatch('planvio-notify', type: 'error', message: $exception->getMessage());
            }
        }

        $this->newFiles = [];

        if ($stored > 0) {
            $this->afterTaskChange(trans_choice('{1}:count file attached.|[2,*]:count files attached.', $stored, ['count' => $stored]));

            return;
        }

        $this->afterTaskChange();
    }

    public function deleteAttachment(int $attachmentId): void
    {
        $task = $this->authorizedTask('view');

        $attachment = Attachment::query()
            ->where('attachable_type', $task->getMorphClass())
            ->where('attachable_id', $task->getKey())
            ->whereKey($attachmentId)
            ->first();

        if (! $attachment instanceof Attachment) {
            return;
        }

        $this->authorize('delete', $attachment);

        app(DeleteAttachment::class)($attachment, $this->actor());

        $this->afterTaskChange(__('File removed.'));
    }

    /* ------------------------------------------------------------------ *
     * Comments
     * ------------------------------------------------------------------ */

    public function postComment(): void
    {
        $task = $this->authorizedTask('comment');
        $body = trim($this->commentDraft);

        if ($body === '') {
            return;
        }

        $this->authorize('create', [Comment::class, $task]);

        try {
            app(CreateComment::class)($task, $this->actor(), $this->paragraphs($body));
        } catch (DomainException $exception) {
            $this->dispatch('planvio-notify', type: 'error', message: $exception->getMessage());

            return;
        }

        $this->commentDraft = '';
        $this->detailTab = 'comments';

        $this->afterTaskChange();
    }

    public function startReply(int $commentId): void
    {
        $this->replyingToId = $commentId;
        $this->replyDraft = '';
    }

    public function cancelReply(): void
    {
        $this->replyingToId = null;
        $this->replyDraft = '';
    }

    public function postReply(): void
    {
        $task = $this->authorizedTask('comment');
        $body = trim($this->replyDraft);
        $parentId = $this->replyingToId;

        if ($body === '' || $parentId === null) {
            return;
        }

        $parent = $this->commentOn($task, $parentId);

        if (! $parent instanceof Comment) {
            return;
        }

        $this->authorize('reply', $parent);

        try {
            app(CreateComment::class)($task, $this->actor(), $this->paragraphs($body), $parent);
        } catch (DomainException $exception) {
            $this->dispatch('planvio-notify', type: 'error', message: $exception->getMessage());

            return;
        }

        $this->replyingToId = null;
        $this->replyDraft = '';

        $this->afterTaskChange();
    }

    public function deleteCommentById(int $commentId): void
    {
        $task = $this->authorizedTask('view');
        $comment = $this->commentOn($task, $commentId);

        if (! $comment instanceof Comment) {
            return;
        }

        $this->authorize('delete', $comment);

        app(DeleteComment::class)($comment, $this->actor());

        $this->afterTaskChange(__('Comment deleted.'));
    }

    public function toggleReaction(int $commentId, string $emoji): void
    {
        $task = $this->authorizedTask('view');
        $comment = $this->commentOn($task, $commentId);

        if (! $comment instanceof Comment || ! in_array($emoji, self::REACTIONS, true)) {
            return;
        }

        $this->authorize('react', $comment);

        $user = $this->actor();

        $mine = $comment->reactions()
            ->where('user_id', $user->getKey())
            ->where('emoji', $emoji)
            ->exists();

        if ($mine) {
            app(RemoveReaction::class)($comment, $user, $emoji);
        } else {
            app(AddReaction::class)($comment, $user, $emoji);
        }

        $this->afterTaskChange();
    }

    private function commentOn(Task $task, int $commentId): ?Comment
    {
        return Comment::query()
            ->where('commentable_type', $task->getMorphClass())
            ->where('commentable_id', $task->getKey())
            ->whereKey($commentId)
            ->first();
    }

    /**
     * Plain text from a textarea, turned into the minimal markup the sanitiser keeps.
     */
    private function paragraphs(string $text): string
    {
        $blocks = preg_split("/\n{2,}/", trim($text)) ?: [];
        $html = '';

        foreach ($blocks as $block) {
            $block = trim($block);

            if ($block === '') {
                continue;
            }

            $html .= '<p>'.nl2br(e($block), false).'</p>';
        }

        return $html;
    }

    /* ------------------------------------------------------------------ *
     * Deleting the task
     * ------------------------------------------------------------------ */

    public function confirmTaskDeletion(): void
    {
        $this->authorizedTask('delete');

        $this->confirmingTaskDeletion = true;
    }

    public function deleteTask(): void
    {
        $task = $this->authorizedTask('delete');
        $project = $task->getRelation('project');

        app(DeleteTask::class)($task, $this->actor());

        $this->confirmingTaskDeletion = false;

        $this->dispatch('planvio-notify', type: 'success', message: __('Task deleted.'));
        $this->dispatch('task-deleted', taskId: (int) $task->getKey());

        $this->afterTaskDeleted($project);
    }

    /* ------------------------------------------------------------------ *
     * AI
     * ------------------------------------------------------------------ */

    /**
     * Hand the task to the assistant with the objective already written.
     *
     * The panel is where an agent run is visible and auditable, so the drawer composes a
     * prompt and opens it rather than answering in a corner of the screen.
     */
    public function aiPrompts(): array
    {
        $task = $this->task;

        if (! $task instanceof Task) {
            return [];
        }

        $reference = $task->key.' — '.$task->title;

        return [
            'summarise' => [
                'label' => __('Summarise'),
                'prompt' => __('Summarise task :task, including its status, blockers and what is left to do.', ['task' => $reference]),
            ],
            'subtasks' => [
                'label' => __('Break into subtasks'),
                'prompt' => __('Break task :task into subtasks and create them.', ['task' => $reference]),
            ],
            'checklist' => [
                'label' => __('Suggest a checklist'),
                'prompt' => __('Suggest a checklist for task :task and add the items.', ['task' => $reference]),
            ],
            'comment' => [
                'label' => __('Draft a comment'),
                'prompt' => __('Draft a progress comment for task :task that I can review before posting.', ['task' => $reference]),
            ],
        ];
    }

    /* ------------------------------------------------------------------ *
     * Plumbing
     * ------------------------------------------------------------------ */

    /**
     * The task, freshly read, with the Gate consulted first.
     *
     * Every mutating method funnels through here: the record is re-read on each request so
     * a stale drawer cannot write against a task that has since moved, and the ability is
     * checked before the Action is ever constructed.
     */
    private function authorizedTask(string $ability): Task
    {
        $task = $this->task;

        abort_if(! $task instanceof Task, 404);

        $this->authorize($ability, $task);

        return $task;
    }

    private function actor(): User
    {
        $user = auth()->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }

    private function workspaceMember(int $userId): ?User
    {
        return $this->taskMembers->firstWhere('id', $userId);
    }

    /**
     * The tenant the open task belongs to.
     *
     * The record is the authority here rather than whatever happens to be bound: the drawer
     * is opened from a queue of contexts — a list, a board, a notification link, a job that
     * bound nothing — and the option lists it draws must belong to the task on screen.
     */
    private function workspaceOfOpenTask(): ?Workspace
    {
        $workspace = $this->task?->workspace;

        return $workspace instanceof Workspace ? $workspace : $this->taskWorkspace();
    }

    private function taskWorkspace(): ?Workspace
    {
        if (property_exists($this, 'workspace') && $this->workspace instanceof Workspace) {
            return $this->workspace;
        }

        return app(CurrentWorkspace::class)->get();
    }

    private function parseDate(string $value): ?Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Refill the inline-editable fields from the record.
     */
    protected function hydrateFrom(Task $task): void
    {
        $this->title = (string) $task->title;
        $this->startDate = $task->start_date?->toDateString() ?? '';
        $this->dueDate = $task->due_date?->toDateString() ?? '';
        $this->estimateHours = $task->estimate_minutes === null
            ? ''
            : rtrim(rtrim(number_format($task->estimate_minutes / 60, 2, '.', ''), '0'), '.');
        $this->editingDescription = false;
        $this->descriptionDraft = '';
        $this->confirmingTaskDeletion = false;
        $this->renamingChecklistId = null;
        $this->renamingChecklistTitle = '';
        $this->replyingToId = null;
        $this->replyDraft = '';
    }

    /**
     * Every write ends here: drop the memoised reads, tell the surrounding list or board
     * that something moved, and say so.
     */
    protected function afterTaskChange(?string $message = null): void
    {
        unset($this->task, $this->comments, $this->activities);

        $task = $this->task;

        if ($task instanceof Task) {
            $this->hydrateFrom($task);
        }

        $this->dispatch('task-updated', taskId: $this->taskId);

        if ($message !== null) {
            $this->dispatch('planvio-notify', type: 'success', message: $message);
        }
    }

    /**
     * What happens once the task is gone: the drawer closes, the page navigates.
     */
    abstract protected function afterTaskDeleted(Project $project): void;
}

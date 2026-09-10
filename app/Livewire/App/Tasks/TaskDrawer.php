<?php

declare(strict_types=1);

namespace App\Livewire\App\Tasks;

use App\Livewire\App\Concerns\EditsTask;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Task detail, over whatever you were looking at.
 *
 * Mounted once by the app shell, so anything anywhere can open a task with
 * `$dispatch('open-task', { taskId: 42 })` — a row in the list, a card on the board, a
 * mention in a comment. Closing it returns you to the same scroll position and the same
 * filters, which is the whole reason the detail is a drawer and not a page.
 *
 * It costs nothing until it is used: with no task open the component renders an empty
 * container and issues no query at all, which matters because it is on every page in the
 * product.
 *
 * The body is {@see EditsTask} plus the `_body` partial, both
 * shared with the full page at `app.tasks.show`. There is exactly one implementation of
 * "edit a task" in Planvio.
 */
final class TaskDrawer extends Component
{
    use EditsTask;
    use WithFileUploads;

    public bool $open = false;

    #[On('open-task')]
    public function openTask(int $taskId): void
    {
        $this->reset(['detailTab', 'commentDraft', 'newChecklistTitle', 'newSubtaskTitle', 'dependencySearch']);

        $this->taskId = $taskId;

        unset($this->task, $this->comments, $this->activities, $this->taskStatuses, $this->taskMilestones, $this->taskMembers, $this->taskTags, $this->dependencyCandidates);

        $task = $this->task;

        if (! $task instanceof Task) {
            // Deleted, or never visible to this person. Either way there is nothing to show
            // and saying which would answer a question they were not entitled to ask.
            $this->taskId = null;
            $this->open = false;

            return;
        }

        $this->hydrateFrom($task);

        $this->open = true;
    }

    /**
     * The drawer closes from Alpine — the scrim, the escape key — and the entangled
     * property brings that back here so the record can be released.
     */
    public function updatedOpen(bool $value): void
    {
        if (! $value) {
            $this->closeDrawer();
        }
    }

    /**
     * Closing keeps the record loaded on purpose: the drawer slides out over two hundred
     * milliseconds, and clearing the task first would empty it mid-animation. It also makes
     * reopening the same task instant.
     */
    public function closeDrawer(): void
    {
        $this->open = false;
        $this->confirmingTaskDeletion = false;
    }

    public function render(): View
    {
        return view('livewire.app.tasks.task-drawer');
    }

    protected function afterTaskDeleted(Project $project): void
    {
        $this->open = false;
        $this->taskId = null;

        unset($this->task, $this->comments, $this->activities);
    }
}

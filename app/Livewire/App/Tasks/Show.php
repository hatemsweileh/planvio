<?php

declare(strict_types=1);

namespace App\Livewire\App\Tasks;

use App\Livewire\App\Concerns\EditsTask;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * A task on its own page.
 *
 * The same record normally opens in a drawer over the list or the board, so that closing it
 * returns you to your scroll position and your filters. This route is what a link in an
 * email, a notification or a chat message resolves to, where there is no list behind it —
 * and it is the surface a phone gets, where a drawer is just a worse page.
 *
 * It is the same component underneath: {@see EditsTask} carries the behaviour and
 * `livewire/app/tasks/_body.blade.php` carries the markup, both shared with
 * {@see TaskDrawer}. Only the chrome around them differs.
 */
#[Layout('layouts.app')]
final class Show extends Component
{
    use EditsTask;
    use WithFileUploads;

    public Workspace $workspace;

    public function mount(Workspace $workspace, Task $task): void
    {
        $this->authorize('view', $task);

        $this->workspace = $workspace;
        $this->taskId = (int) $task->getKey();

        $loaded = $this->task;

        abort_if(! $loaded instanceof Task, 404);

        $this->hydrateFrom($loaded);
    }

    public function render(): View
    {
        $task = $this->task;

        return view('livewire.app.tasks.show')
            ->title($task instanceof Task ? $task->key.' · '.$task->title : __('Task'));
    }

    /**
     * There is no page left once the task is gone, so the list it belonged to is where the
     * person goes next.
     */
    protected function afterTaskDeleted(Project $project): void
    {
        $this->redirectRoute('app.projects.tasks', [$this->workspace, $project], navigate: true);
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\App\Time;

use App\Livewire\App\Time\Concerns\ManagesTime;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The clock on its own, for embedding.
 *
 * `@livewire('app.time.timer', ['workspace' => $workspace, 'project' => $project])` puts a
 * start/stop control anywhere — a task page, a project header — without that screen having
 * to know anything about `time_entries`. It shares {@see ManagesTime} with the timesheet,
 * so the same authorization runs and the same one-clock rule applies wherever it appears.
 */
final class Timer extends Component
{
    use ManagesTime;

    public Workspace $workspace;

    public ?Project $project = null;

    /** Show the running elapsed time, ticking. Off inside a dense toolbar. */
    public bool $showElapsed = true;

    public function mount(Workspace $workspace, ?Project $project = null): void
    {
        $this->authorize('view', $workspace);

        $this->workspace = $workspace;
        $this->project = $project;
        $this->formProject = $project?->getKey() === null ? '' : (string) $project->getKey();
        $this->formDate = $this->today()->toDateString();
    }

    protected function timeWorkspace(): Workspace
    {
        return $this->workspace;
    }

    protected function timeProject(): ?Project
    {
        return $this->project;
    }

    protected function refreshTimeData(): void
    {
        // Anything else on the page that shows hours needs to hear about this; the screens
        // that embed the timer listen for it and drop their own caches.
        $this->dispatch('planvio-time-changed');
    }

    public function render(): View
    {
        return view('livewire.app.time.timer');
    }
}

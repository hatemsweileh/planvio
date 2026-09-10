<?php

declare(strict_types=1);

namespace App\Livewire\App\Projects;

use App\Livewire\App\Calendar\Concerns\BuildsCalendar;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One project's calendar.
 *
 * Identical to the workspace calendar in everything but scope — the same trait draws both —
 * so a task chip behaves the same way, drags the same way and opens the same drawer
 * wherever you meet it.
 */
#[Layout('layouts.app')]
final class Calendar extends Component
{
    use BuildsCalendar;

    public Workspace $workspace;

    public Project $project;

    public function mount(Workspace $workspace, Project $project): void
    {
        $this->authorize('view', $project);

        $this->workspace = $workspace;
        $this->project = $project;
    }

    protected function scopedProject(): ?Project
    {
        return $this->project;
    }

    protected function calendarWorkspace(): Workspace
    {
        return $this->workspace;
    }

    public function render(): View
    {
        return view('livewire.app.projects.calendar')
            ->title($this->project->name.' · '.__('Calendar'));
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\App\Calendar;

use App\Livewire\App\Calendar\Concerns\BuildsCalendar;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Everything dated across the workspace, on one grid.
 *
 * The behaviour lives in {@see BuildsCalendar}, which the project calendar uses too. The
 * only difference between the two screens is the answer to `scopedProject()`: null here,
 * so the grid spans every project the person can open and gains a project filter.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    use BuildsCalendar;

    public Workspace $workspace;

    public function mount(Workspace $workspace): void
    {
        $this->authorize('view', $workspace);

        $this->workspace = $workspace;
    }

    protected function scopedProject(): ?Project
    {
        return null;
    }

    protected function calendarWorkspace(): Workspace
    {
        return $this->workspace;
    }

    public function render(): View
    {
        return view('livewire.app.calendar.index')->title(__('Calendar'));
    }
}

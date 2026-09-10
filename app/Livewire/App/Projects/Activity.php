<?php

declare(strict_types=1);

namespace App\Livewire\App\Projects;

use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
final class Activity extends Component
{
    public Workspace $workspace;

    public Project $project;

    public function mount(Workspace $workspace, Project $project): void
    {
        $this->authorize('view', $project);

        $this->workspace = $workspace;
        $this->project = $project;
    }

    public function render(): View
    {
        return view('livewire.app.projects.activity')
            ->title($this->project->name.' · '.__('Activity'));
    }
}

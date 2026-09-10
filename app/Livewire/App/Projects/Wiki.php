<?php

declare(strict_types=1);

namespace App\Livewire\App\Projects;

use App\Livewire\App\Projects\Concerns\ManagesWikiTree;
use App\Models\Project;
use App\Models\WikiPage as Page;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The project's knowledge base: the document rail on the left, and — with no page selected
 * — a reading list of what is in here and what changed recently.
 *
 * The landing view is deliberately not an empty frame waiting for a click. A wiki nobody
 * opens is a wiki nobody writes in, so the first thing this screen does is show the
 * documents that actually moved.
 */
#[Layout('layouts.app')]
final class Wiki extends Component
{
    use ManagesWikiTree;

    public Workspace $workspace;

    public Project $project;

    public function mount(Workspace $workspace, Project $project): void
    {
        $this->authorize('view', $project);
        $this->authorize('viewAny', [Page::class, $project]);

        $this->workspace = $workspace;
        $this->project = $project;
    }

    /**
     * The pages touched most recently, with the person who touched them.
     *
     * @return Collection<int, Page>
     */
    #[Computed]
    public function recent(): Collection
    {
        return Page::query()
            ->forProject($this->project)
            ->visibleTo($this->actor())
            ->with(['editor:id,name,avatar_path', 'author:id,name,avatar_path'])
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get();
    }

    public function render(): View
    {
        return view('livewire.app.projects.wiki')
            ->title($this->project->name.' · '.__('Wiki'));
    }
}

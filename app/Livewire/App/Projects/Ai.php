<?php

declare(strict_types=1);

namespace App\Livewire\App\Projects;

use App\Livewire\App\Ai\Concerns\ConductsConversation;
use App\Livewire\App\Ai\Concerns\DecidesApprovals;
use App\Models\AiToolRun;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The assistant, inside one project.
 *
 * Structurally the AI workspace with the scope decided for it: the same gate, the same
 * queued run, the same tool trace, the same approval card. What it adds is that every run
 * started here carries `ai_runs.project_id`, which is what makes the project's own run
 * history, its policy overrides (`ai_policies.project_id`) and its approvals mean anything.
 *
 * The scope is locked rather than merely preselected. A conversation on a project's tab that
 * could be re-aimed at the workspace would leave a thread whose transcript no longer
 * explains its own tool calls — and, worse, a project-scoped policy silently stopping to
 * apply halfway down the page.
 */
#[Layout('layouts.app')]
final class Ai extends Component
{
    use ConductsConversation;
    use DecidesApprovals;

    public Project $project;

    public function mount(Workspace $workspace, Project $project): void
    {
        $this->authorize('view', $project);
        $this->authorize('ai.use', $project);

        $this->workspace = $workspace;
        $this->project = $project;
        $this->projectScope = (int) $project->getKey();
    }

    /**
     * This screen is the scope. {@see ConductsConversation::scopeTo()} honours it.
     */
    protected function scopeIsLocked(): bool
    {
        return true;
    }

    protected function afterApprovalDecision(AiToolRun $toolRun): void
    {
        unset($this->pendingApprovals);

        $runId = $toolRun->ai_run_id;

        if ($runId !== null) {
            $this->activeRunId = (int) $runId;
        }

        $this->forgetConversationState();
    }

    protected function actor(): User
    {
        $user = auth()->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }

    public function render(): View
    {
        return view('livewire.app.projects.ai')
            ->title($this->project->name.' · '.__('AI'));
    }
}

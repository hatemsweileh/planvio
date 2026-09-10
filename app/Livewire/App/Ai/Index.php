<?php

declare(strict_types=1);

namespace App\Livewire\App\Ai;

use App\Livewire\App\Ai\Concerns\ConductsConversation;
use App\Livewire\App\Ai\Concerns\DecidesApprovals;
use App\Models\AiToolRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The AI workspace: the whole of the agent, in one screen.
 *
 * Three panes, and the reason there are three rather than three screens is that they are the
 * same story told at different distances. The conversation is what is happening; the
 * approvals queue is what is waiting on a person; the run history is what already happened.
 * Splitting them across routes would let somebody use the first without ever seeing the
 * other two, which is exactly the failure mode an agent product cannot afford.
 *
 * The transcript is not a chat log. Every tool call the agent made is drawn from
 * `ai_tool_runs` — the tool, a readable line of its arguments, what came back, how long it
 * took, and a status dot — because "the assistant says it created three tasks" and "three
 * tasks were created" are different claims, and only one of them is evidence.
 *
 * ## Where the panes are gated
 *
 * `mount()` requires `ai.use`, which is what the route and the sidebar item already require.
 * The approvals pane additionally needs `ai.approve` and the runs pane `ai.view_logs`; both
 * are asked before the tab is offered *and* inside the component that draws it, because a
 * tab nobody can see is not an authorisation.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    use ConductsConversation;
    use DecidesApprovals;

    private const PANES = ['chat', 'approvals', 'runs'];

    /**
     * A question carried over from the assistant drawer, so that handing a half-typed
     * thought to the full workspace does not lose it.
     */
    #[Url(as: 'ask', except: '')]
    public string $ask = '';

    #[Url(as: 'pane', except: 'chat')]
    public string $pane = 'chat';

    public function mount(Workspace $workspace): void
    {
        $this->authorize('view', $workspace);
        $this->authorize('ai.use', $workspace);

        $this->workspace = $workspace;

        // The drawer hands the text over rather than the answer: the run belongs here,
        // where its tool calls and its approvals are visible.
        $this->ask = mb_substr(trim($this->ask), 0, 2000);
        $this->draft = $this->ask;

        if (! in_array($this->pane, self::PANES, true) || ! $this->canSee($this->pane)) {
            $this->pane = 'chat';
        }
    }

    /* ------------------------------------------------------------------ *
     * Panes
     * ------------------------------------------------------------------ */

    public function showPane(string $pane): void
    {
        if (! in_array($pane, self::PANES, true) || ! $this->canSee($pane)) {
            return;
        }

        $this->pane = $pane;
    }

    /**
     * Whether this person may be offered $pane at all.
     */
    public function canSee(string $pane): bool
    {
        return match ($pane) {
            'approvals' => Gate::allows('ai.approve', $this->workspace),
            'runs' => Gate::allows('ai.view_logs', $this->workspace),
            default => true,
        };
    }

    /* ------------------------------------------------------------------ *
     * Wiring
     * ------------------------------------------------------------------ */

    /**
     * A decision taken on the card inside the conversation changes the thread as well as the
     * queue: an approval puts the run back on the queue, so the status line has to start
     * again rather than stay on "waiting for a decision".
     */
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
        return view('livewire.app.ai.index')->title(__('AI'));
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\App\Ai;

use App\Livewire\App\Ai\Concerns\ConductsConversation;
use App\Livewire\App\Ai\Concerns\DecidesApprovals;
use App\Models\AiToolRun;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * The assistant drawer, mounted once by the app shell and opened from anywhere: the `A`
 * shortcut, the sparkle in the header, the create menu, a task's AI actions, the dashboard's
 * insights card, and the "Ask Planvio AI" row in the command palette — all of which dispatch
 * `open-ai-panel`, optionally carrying a prompt, an intent, and the record they were opened
 * from.
 *
 * Opening is pure Alpine, so the drawer is on screen in the same frame as the keystroke. The
 * server is only asked once the panel has something to do.
 *
 * ## It runs here; it does not hand off
 *
 * An earlier version of this component redirected to the AI workspace with the question in
 * the query string. That was the wrong trade: it threw away the page somebody was looking
 * at, which is precisely the context they wanted the assistant to have. The drawer now runs
 * the same machinery as the full screen ({@see ConductsConversation}) in a narrower column —
 * the same gate, the same policy, the same queued run, the same tool trace, the same
 * approval card. Nothing is hidden in here that the full workspace would have shown; the
 * link to it is one click away and the thread is the same thread.
 *
 * ## Scope
 *
 * The drawer inherits the record the page is about. Open it on a project and the run is
 * scoped to that project; open it from a task's AI menu and the task comes with it. That is
 * what makes "Summarise this" mean anything at all, and it is resolved from the route rather
 * than from anything the caller passes, except where a caller names a record explicitly.
 */
final class Panel extends Component
{
    use ConductsConversation;
    use DecidesApprovals;

    /**
     * Whether the drawer has ever been opened on this page.
     *
     * It is mounted by the app shell on *every* screen, so a drawer that rendered its
     * conversation, its policy lookup and its project list on page load would put six
     * queries on every page in the product for a panel nobody had asked for. The shell
     * renders the chrome; the body arrives with the first open, which already costs a round
     * trip for the context the caller sent.
     */
    public bool $opened = false;

    /**
     * The prepared objectives behind the intent buttons scattered through the product.
     *
     * They are written here rather than at each call site so that "Review my workspace" asks
     * for the same thing from the dashboard as it does from anywhere else, and so that the
     * read-only ones stay read-only: an objective that asks for a report is what keeps the
     * dashboard's insights card an analysis rather than an action.
     *
     * @return array<string, string>
     */
    private function intents(): array
    {
        return [
            'review' => __('Review this workspace and report back: what is late, what is at risk, what is waiting on somebody, and what needs a decision this week. Read only — do not change anything.'),
            'create' => __('Help me set up a new project. Ask me what it is for, then propose the milestones and the first tasks before creating anything.'),
        ];
    }

    public function mount(): void
    {
        $workspace = app(CurrentWorkspace::class)->get();

        if (! $workspace instanceof Workspace || ! Gate::allows('ai.use', $workspace)) {
            return;
        }

        $this->workspace = $workspace;
        $this->inheritRouteScope();
    }

    /* ------------------------------------------------------------------ *
     * Opening
     * ------------------------------------------------------------------ */

    /**
     * Everything the drawer needs on open, in one round trip.
     *
     * A prompt is put in the composer rather than sent: the person asked for the assistant,
     * not for the assistant to have already acted, and seeing the exact words before they go
     * is the whole difference. An *intent* is sent, because an intent only ever arrives from
     * a button whose label already said what it would do.
     */
    public function begin(?int $projectId = null, ?int $taskId = null, string $prompt = '', string $intent = ''): void
    {
        if (! $this->workspace instanceof Workspace) {
            return;
        }

        $this->authorize('ai.use', $this->workspace);

        $this->opened = true;

        $this->focusOn($projectId, $taskId);

        $intent = trim($intent);

        if ($intent !== '' && isset($this->intents()[$intent])) {
            $this->ask($this->intents()[$intent]);

            return;
        }

        $prompt = trim($prompt);

        if ($prompt !== '') {
            $this->draft = mb_substr($prompt, 0, 2000);
        }
    }

    /**
     * Point the drawer at a record. A change of scope starts a new thread rather than
     * quietly re-aiming the one on screen: a conversation about one task that suddenly
     * concerns another is a conversation nobody can audit.
     */
    private function focusOn(?int $projectId, ?int $taskId): void
    {
        if ($projectId === null && $taskId === null) {
            return;
        }

        $before = [$this->projectScope, $this->taskScope];

        $task = $taskId === null ? null : Task::query()->whereKey($taskId)->first();

        if ($task instanceof Task && Gate::allows('view', $task)) {
            $this->taskScope = (int) $task->getKey();
            $this->projectScope = (int) $task->project_id;
        } elseif ($projectId !== null) {
            $project = Project::query()->whereKey($projectId)->first();

            if ($project instanceof Project && Gate::allows('useAi', $project)) {
                $this->projectScope = (int) $project->getKey();
                $this->taskScope = null;
            }
        }

        if ($before !== [$this->projectScope, $this->taskScope]) {
            $this->conversationId = null;
            $this->activeRunId = null;
            $this->refusal = null;

            $this->forgetConversationState();
        }
    }

    /**
     * The record the page is already about, taken from the route rather than from the DOM.
     *
     * Route model binding has already resolved and scoped these — `scopeBindings()` looks a
     * project up through its workspace — so a parameter from another tenant never arrives
     * here at all.
     */
    private function inheritRouteScope(): void
    {
        $route = request()->route();

        if ($route === null) {
            return;
        }

        $task = $route->parameter('task');

        if ($task instanceof Task) {
            $this->taskScope = (int) $task->getKey();
            $this->projectScope = (int) $task->project_id;

            return;
        }

        $project = $route->parameter('project');

        if ($project instanceof Project) {
            $this->projectScope = (int) $project->getKey();
        }
    }

    /* ------------------------------------------------------------------ *
     * Wiring
     * ------------------------------------------------------------------ */

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
        return view('livewire.app.ai.panel');
    }
}

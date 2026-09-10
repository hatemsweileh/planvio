<?php

declare(strict_types=1);

namespace App\Livewire\App\Ai;

use App\Livewire\App\Ai\Concerns\DecidesApprovals;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The approvals queue: every agent action in this workspace waiting on a person.
 *
 * This is the most important screen in the product, and the reason is narrow. Everywhere
 * else, the assistant is a convenience. Here it has stopped, one step short of changing
 * something real, and asked a named human to take responsibility for the change. What the
 * card shows is therefore not a summary of the request — it *is* the request: the tool, the
 * arguments exactly as they will execute, the measured blast radius the tool counted before
 * anything ran, who the run is acting for, the risk level, and when the request stops being
 * valid.
 *
 * Deciding is deliberately asymmetric. Rejecting is one click, because refusing is always
 * the safe outcome and nobody should have to work for it. Approving a destructive call asks
 * for the tool's name to be typed, because that click is the last thing between a sentence a
 * model produced and a project that no longer exists.
 *
 * The queue polls slowly. New requests arrive from a queue worker rather than from anything
 * this page did, and an approvals screen that is stale for fifteen seconds costs nothing —
 * an approval that has already expired is refused at execution time regardless of what this
 * page last drew.
 *
 * It renders two ways: as a pane inside the AI workspace, where somebody arrives by hand,
 * and on its own route, which is where the "an action needs your approval" notification
 * points. The layout attribute only applies to the second — a nested component ignores it.
 */
#[Layout('layouts.app')]
final class Approvals extends Component
{
    use DecidesApprovals;

    public Workspace $workspace;

    public function mount(Workspace $workspace): void
    {
        $this->authorize('view', $workspace);
        $this->authorize('ai.approve', $workspace);

        $this->workspace = $workspace;
    }

    public function refreshQueue(): void
    {
        unset($this->pendingApprovals);
    }

    protected function actor(): User
    {
        $user = auth()->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }

    public function render(): View
    {
        return view('livewire.app.ai.approvals')->title(__('Approvals'));
    }
}

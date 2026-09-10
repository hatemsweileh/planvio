<?php

declare(strict_types=1);

namespace App\Actions\Milestones;

use App\Enums\MilestoneStatus;
use App\Events\Milestones\MilestoneCompleted;
use App\Models\Milestone;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Marks a milestone as reached.
 *
 * Its own action rather than an UpdateMilestone call because completing is the transition
 * people actually take, and because the three columns that record it — status, timestamp and
 * progress — have to move together.
 *
 * Completing a completed milestone is a no-op: `completed_at` is the record of when the
 * milestone was reached, and a second click must not move it to today.
 */
final class CompleteMilestone
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function __invoke(Milestone $milestone, ?User $actor = null): Milestone
    {
        if ($milestone->status === MilestoneStatus::Completed && $milestone->completed_at !== null) {
            return $milestone;
        }

        $previous = $milestone->status ?? MilestoneStatus::Planned;

        DB::transaction(function () use ($milestone, $actor, $previous): void {
            $milestone->status = MilestoneStatus::Completed;
            $milestone->completed_at ??= now();
            $milestone->progress = 100;
            $milestone->save();

            $this->activity->forUser($actor)->log($milestone, 'completed', [
                'changes' => [
                    'status' => ['old' => $previous->value, 'new' => MilestoneStatus::Completed->value],
                ],
                'completed_at' => $milestone->completed_at,
            ]);
        });

        event(new MilestoneCompleted($milestone, $actor));

        return $milestone;
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Milestones;

use App\Enums\MilestoneStatus;
use App\Events\Milestones\MilestoneUpdated;
use App\Exceptions\DomainException;
use App\Exceptions\NotAMember;
use App\Models\Milestone;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Applies a partial change to a milestone.
 *
 * The one rule the caller cannot express directly is the relationship between `status` and
 * `completed_at`: moving into `completed` stamps the timestamp and pins progress at 100,
 * moving out of it clears the timestamp. Letting those drift apart produces a milestone that
 * reads as finished on a board and as open in a report.
 */
final class UpdateMilestone
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function __invoke(
        Milestone $milestone,
        MilestoneAttributes $attributes,
        ?User $actor = null,
    ): Milestone {
        $columns = $attributes->toColumns();

        if ($attributes->name !== null && trim($attributes->name) === '') {
            throw new DomainException(__('A milestone needs a name.'));
        }

        if (isset($columns['owner_id'])) {
            $this->assertMember($milestone, (int) $columns['owner_id']);
        }

        $wasCompleted = $milestone->status === MilestoneStatus::Completed;

        $milestone->fill($columns);

        $isCompleted = $milestone->status === MilestoneStatus::Completed;

        if ($isCompleted && ! $wasCompleted) {
            $milestone->completed_at = now();

            if ($attributes->progress === null) {
                $milestone->progress = 100;
            }
        }

        if (! $isCompleted && $wasCompleted) {
            $milestone->completed_at = null;
        }

        if (! $milestone->isDirty()) {
            return $milestone;
        }

        $changes = ActivityLogger::changes($milestone);

        DB::transaction(function () use ($milestone, $actor, $changes): void {
            $milestone->save();

            $this->activity->forUser($actor)->log(
                $milestone,
                $milestone->wasChanged('status') ? 'status_changed' : 'updated',
                $changes === [] ? [] : ['changes' => $changes],
            );
        });

        event(new MilestoneUpdated($milestone, $changes, $actor));

        return $milestone;
    }

    private function assertMember(Milestone $milestone, int $userId): void
    {
        $isMember = WorkspaceMember::withoutWorkspaceScope()
            ->where('workspace_id', $milestone->workspace_id)
            ->where('user_id', $userId)
            ->exists();

        if (! $isMember) {
            throw NotAMember::ofWorkspace($milestone->workspace, $userId);
        }
    }
}

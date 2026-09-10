<?php

declare(strict_types=1);

namespace App\Actions\Milestones;

use App\Enums\MilestoneStatus;
use App\Events\Milestones\MilestoneCreated;
use App\Exceptions\DomainException;
use App\Exceptions\NotAMember;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Adds a milestone to a project.
 *
 * `workspace_id` is copied from the project rather than taken from the ambient binding: a
 * milestone filed under a different tenant than its project is a leak the scope cannot catch,
 * because every query for it starts from the project.
 *
 * A milestone created as already completed gets its `completed_at` stamped here, so the
 * timestamp and the status agree from the first row.
 */
final class CreateMilestone
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function __invoke(
        Project $project,
        MilestoneAttributes $attributes,
        ?User $actor = null,
    ): Milestone {
        $columns = $attributes->toColumns();

        if (! array_key_exists('name', $columns)) {
            throw new DomainException(__('A milestone needs a name.'));
        }

        if (isset($columns['owner_id'])) {
            $this->assertMember($project, (int) $columns['owner_id']);
        }

        $milestone = DB::transaction(function () use ($project, $columns, $actor): Milestone {
            $milestone = new Milestone;
            $milestone->fill($columns);

            $milestone->workspace_id = $project->workspace_id;
            $milestone->project_id = $project->getKey();
            $milestone->status ??= MilestoneStatus::Planned;
            $milestone->position ??= $this->nextPosition($project);
            $milestone->progress ??= 0;

            if ($milestone->status === MilestoneStatus::Completed) {
                $milestone->completed_at = now();
                $milestone->progress = 100;
            }

            $milestone->save();

            $this->activity->forUser($actor)->log($milestone, 'created', [
                'name' => $milestone->name,
                'due_date' => $milestone->due_date,
            ]);

            return $milestone;
        });

        event(new MilestoneCreated($milestone, $actor));

        return $milestone;
    }

    private function nextPosition(Project $project): int
    {
        $highest = Milestone::query()
            ->withoutWorkspaceScope()
            ->where('project_id', $project->getKey())
            ->max('position');

        return $highest === null ? 0 : ((int) $highest) + 1;
    }

    private function assertMember(Project $project, int $userId): void
    {
        $isMember = WorkspaceMember::withoutWorkspaceScope()
            ->where('workspace_id', $project->workspace_id)
            ->where('user_id', $userId)
            ->exists();

        if (! $isMember) {
            throw NotAMember::ofWorkspace($project->workspace, $userId);
        }
    }
}

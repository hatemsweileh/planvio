<?php

declare(strict_types=1);

namespace App\Ai\Context;

use App\Ai\Agent\AgentContext;
use App\Enums\AuthorType;
use App\Enums\Permission;
use App\Enums\WorkspaceRole;
use App\Models\Activity;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;

/**
 * What has happened recently, capped at `config('ai.context.max_activities')`.
 *
 * The activity feed is the easiest place in the product to leak a project by accident,
 * because an activity row names its subject. Two filters keep it honest:
 *
 *  - rows belonging to a project are restricted to the projects the acting user can open,
 *    resolved once through `Project::visibleTo()` rather than row by row;
 *  - rows belonging to no project — workspace-level events — are withheld from guests, who
 *    are in the workspace for one project and have no business watching the organisation.
 *
 * When a project is focused the feed narrows to it, because that is what the run is about
 * and a workspace-wide feed would spend the budget on noise.
 *
 * Activity descriptions and property values are user-written; the fragment is untrusted.
 */
final class ActivityContextProvider implements ContextSource
{
    use ContributesContext;

    public function key(): string
    {
        return 'activity';
    }

    public function supports(AgentContext $context): bool
    {
        return $context->can(Permission::WorkspaceView);
    }

    public function provide(AgentContext $context): array
    {
        $project = $context->project;

        if ($project !== null && $context->cannot(Permission::ProjectView, $project)) {
            return [];
        }

        $query = Activity::query()->where('workspace_id', $context->workspaceId());

        if ($project !== null) {
            $query->where('project_id', $project->getKey());
        } else {
            $visible = $this->visibleProjectIds($context);
            $includeWorkspaceLevel = $context->workspaceRole() !== WorkspaceRole::Guest;

            if ($visible === [] && ! $includeWorkspaceLevel) {
                return [];
            }

            $query->where(function (Builder $scope) use ($visible, $includeWorkspaceLevel): void {
                if ($includeWorkspaceLevel) {
                    $scope->whereNull('project_id');
                }

                if ($visible !== []) {
                    $scope->orWhereIn('project_id', $visible);
                }
            });
        }

        $activities = $query
            ->with('causer:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($this->limit('max_activities', 25))
            ->get()
            // Newest first for the cap, oldest first for the prompt: a feed read backwards
            // invites the model to describe an earlier event as the latest one.
            ->reverse()
            ->values();

        if ($activities->isEmpty()) {
            return [];
        }

        $timezone = $context->resolvedTimezone();
        $lines = [];

        foreach ($activities as $activity) {
            $actor = $activity->causer_type === AuthorType::Ai
                ? 'Planvio AI'.($activity->causer?->name === null ? '' : ' acting for '.$activity->causer->name)
                : ($activity->causer?->name ?? 'the system');

            $lines[] = sprintf(
                '%s · %s · %s on %s%s',
                Facts::dateTime($activity->created_at, $timezone) ?? 'unknown time',
                $actor,
                $activity->event,
                $this->subjectLabel($activity),
                $activity->description === null ? '' : ' — '.Facts::excerpt($activity->description),
            );
        }

        $heading = $project === null
            ? 'Recent activity in this workspace'
            : 'Recent activity in '.$project->key;

        $source = $project === null
            ? 'activity:workspace:'.$context->workspaceId()
            : 'activity:project:'.(int) $project->getKey();

        return [
            ContextFragment::make($source, Facts::for($heading)->bullets('events', $lines)->toString()),
        ];
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    /**
     * @return list<int>
     */
    private function visibleProjectIds(AgentContext $context): array
    {
        return Project::query()
            ->forWorkspace($context->workspace)
            ->visibleTo($context->user)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * The subject as a short type-and-id label. The subject's own title is not loaded: the
     * morph target may be soft-deleted or in a project the acting user cannot open, and
     * resolving it here would reintroduce the leak the id filter just closed.
     */
    private function subjectLabel(Activity $activity): string
    {
        $type = is_string($activity->subject_type) && $activity->subject_type !== ''
            ? $activity->subject_type
            : 'record';

        $separator = strrpos($type, '\\');
        $short = $separator === false ? $type : substr($type, $separator + 1);

        return $short.' #'.(int) $activity->subject_id;
    }

    private function limit(string $key, int $default): int
    {
        $configured = config('ai.context.'.$key);

        return is_int($configured) && $configured > 0 ? $configured : $default;
    }
}

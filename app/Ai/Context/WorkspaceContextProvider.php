<?php

declare(strict_types=1);

namespace App\Ai\Context;

use App\Ai\Agent\AgentContext;
use App\Enums\Permission;
use App\Models\Project;
use App\Models\WorkspaceMember;

/**
 * The workspace the run is bound to: what it is, who the acting user is inside it, and which
 * projects that person can actually open.
 *
 * The project list is the part with teeth. It is built from `Project::visibleTo()` — the same
 * subquery the product's own project list uses — and then every surviving row is put through
 * `AgentContext::can()` individually. That looks redundant and is not: the scope is a query
 * convenience and the policy is the authority (ARCHITECTURE.md §3), and this is the one place
 * a guest could otherwise learn the names of projects they were never added to. A name is a
 * leak; so is a count, which is why the count reported here is of the rows that survived the
 * policy rather than of the rows the query returned.
 *
 * The clock is emitted as a separate, trusted fragment. It contains nothing anybody typed —
 * a resolved timezone identifier and the instant derived from it — so it does not need the
 * untrusted wrapper, and keeping it separate means the model still knows what day it is even
 * when the budget has taken everything else away.
 */
final class WorkspaceContextProvider implements ContextSource
{
    use ContributesContext;

    private const WEEKDAYS = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    public function key(): string
    {
        return 'workspace';
    }

    /**
     * A run for somebody who is not a member of the bound workspace contributes nothing —
     * not even the workspace's name.
     */
    public function supports(AgentContext $context): bool
    {
        return $context->can(Permission::WorkspaceView);
    }

    public function provide(AgentContext $context): array
    {
        $workspace = $context->workspace;
        $now = $context->now();

        $fragments = [
            ContextFragment::trusted(
                'clock',
                Facts::for('Current time')
                    ->add('now', $now->format('Y-m-d H:i'))
                    ->add('date', $now->toDateString())
                    ->add('day', $now->format('l'))
                    ->add('timezone', $context->resolvedTimezone())
                    ->add('week starts on', self::WEEKDAYS[(int) $workspace->week_starts_on] ?? 'Monday')
                    ->toString(),
            ),
        ];

        [$lines, $hasMore] = $this->visibleProjects($context);

        $facts = Facts::for('Workspace')
            ->add('name', $workspace->name)
            ->add('description', Facts::excerpt($workspace->description))
            ->add('your role', $context->workspaceRole())
            ->add('currency', $workspace->currency)
            ->add('date format', $workspace->date_format)
            ->add('locale', $workspace->locale)
            ->count('members', $this->memberCount($context))
            ->add('active projects you can see', $hasMore
                ? 'more than '.count($lines)
                : (string) count($lines));

        $fragments[] = ContextFragment::make('workspace:'.$context->workspaceId(), $facts->toString());

        if ($lines !== []) {
            $projects = Facts::for('Projects you can open')->bullets('active', $lines);

            if ($hasMore) {
                $projects->line('(first '.count($lines).' by name; use search_projects for the rest)');
            }

            $fragments[] = ContextFragment::make(
                'workspace:'.$context->workspaceId().':projects',
                $projects->toString(),
            );
        }

        return $fragments;
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    /**
     * The active projects the acting user may open, as display lines, plus whether the cap
     * cut the list short.
     *
     * One row beyond the cap is fetched so "there are more" can be stated without counting
     * the whole workspace.
     *
     * @return array{0: list<string>, 1: bool}
     */
    private function visibleProjects(AgentContext $context): array
    {
        $limit = $this->limit('max_projects', 25);

        $projects = Project::query()
            ->forWorkspace($context->workspace)
            ->visibleTo($context->user)
            ->active()
            ->with('status:id,name')
            ->orderBy('name')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $projects->count() > $limit;
        $timezone = $context->resolvedTimezone();
        $lines = [];

        foreach ($projects->take($limit) as $project) {
            // The scope narrowed the set; the policy decides it.
            if ($context->cannot(Permission::ProjectView, $project)) {
                continue;
            }

            $parts = array_values(array_filter([
                $project->health?->value,
                $project->status?->name,
                $project->priority === null ? null : 'priority '.$project->priority->value,
                $project->target_date === null ? null : 'target '.Facts::date($project->target_date, $timezone),
                'progress '.(int) $project->progress.'%',
            ]));

            $lines[] = sprintf('%s — %s (%s)', $project->key, $project->name, implode(', ', $parts));
        }

        return [$lines, $hasMore];
    }

    private function memberCount(AgentContext $context): int
    {
        return WorkspaceMember::query()
            ->where('workspace_id', $context->workspaceId())
            ->count();
    }

    private function limit(string $key, int $default): int
    {
        $configured = config('ai.context.'.$key);

        return is_int($configured) && $configured > 0 ? $configured : $default;
    }
}

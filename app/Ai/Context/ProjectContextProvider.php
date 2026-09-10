<?php

declare(strict_types=1);

namespace App\Ai\Context;

use App\Ai\Agent\AgentContext;
use App\Enums\MilestoneStatus;
use App\Enums\Permission;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Services\ProjectHealthCalculator;

/**
 * The project the run is focused on, stated as facts.
 *
 * "Facts, not prose" is the whole design here. The health line carries the reason codes and
 * the numbers behind them — `overdue_tasks (7 of 21 open, oldest due 2026-08-02)` — rather
 * than a sentence saying the project is at risk, because the model is asked to label its own
 * analysis and distinguish it from what the system recorded. Handing it a conclusion dressed
 * as data makes that impossible, and a conclusion nobody can re-derive a month later is worse
 * than no conclusion.
 *
 * Budget figures appear only when the acting user holds `budget.view` for this project.
 * Everything else is gated on `project.view` for the project itself, which is what stops a
 * guest who guessed an id from getting a summary of it.
 */
final class ProjectContextProvider implements ContextSource
{
    use ContributesContext;

    /**
     * `config('ai.context')` has no milestone cap; a project with a hundred milestones is
     * not a reason to spend the whole prompt on them.
     */
    private const MAX_MILESTONES = 10;

    public function __construct(
        private readonly ProjectHealthCalculator $health = new ProjectHealthCalculator,
    ) {}

    public function key(): string
    {
        return 'project';
    }

    public function supports(AgentContext $context): bool
    {
        $project = $context->project;

        return $project !== null
            && $context->isInWorkspace($project)
            && $context->can(Permission::ProjectView, $project);
    }

    public function provide(AgentContext $context): array
    {
        $project = $context->project;

        if ($project === null) {
            return [];
        }

        $id = (int) $project->getKey();
        $timezone = $context->resolvedTimezone();

        $fragments = [
            ContextFragment::make('project:'.$id, $this->summary($context, $project, $timezone)),
        ];

        $members = $this->members($project);

        if ($members !== []) {
            $fragments[] = ContextFragment::make(
                'project:'.$id.':members',
                Facts::for('Project members')->bullets('members', $members)->toString(),
            );
        }

        $milestones = $this->milestones($context, $project, $timezone);

        if ($milestones !== null) {
            $fragments[] = ContextFragment::make('project:'.$id.':milestones', $milestones);
        }

        return $fragments;
    }

    /* ------------------------------------------------------------------ *
     * Sections
     * ------------------------------------------------------------------ */

    private function summary(AgentContext $context, Project $project, string $timezone): string
    {
        $counts = $this->taskCounts($context, $project);
        $assessment = $this->health->calculate($project, $context->today());

        $facts = Facts::for('Focused project')
            ->add('key', $project->key)
            ->add('name', $project->name)
            ->add('type', $project->type)
            ->add('status', $project->status?->name)
            ->add('priority', $project->priority)
            ->add('health', $assessment->health)
            ->add('health set manually', $assessment->manual)
            ->add('description', Facts::excerpt($project->description))
            ->add('owner', $project->owner?->name)
            ->add('manager', $project->manager?->name)
            ->add('client', $project->client_name)
            ->add('department', $project->department)
            ->add('start date', Facts::date($project->start_date, $timezone))
            ->add('target date', Facts::date($project->target_date, $timezone))
            ->add('completed at', Facts::dateTime($project->completed_at, $timezone))
            ->add('archived', $project->is_archived)
            ->count('progress %', (int) $project->progress)
            ->count('tasks', $counts['total'])
            ->count('open tasks', $counts['open'])
            ->count('completed tasks', $counts['total'] - $counts['open'])
            ->count('overdue tasks', $counts['overdue'])
            ->count('unassigned open tasks', $counts['unassigned']);

        if ($context->can(Permission::BudgetView, $project) && $project->budget !== null) {
            $facts->add('budget', $project->budget.' '.($project->currency ?? $context->workspace->currency));
        }

        $facts->bullets('health signals', $this->healthSignals($assessment->reasons));

        return $facts->toString();
    }

    /**
     * The calculator's reasons, each rendered as the numbers it was derived from.
     *
     * @param list<array<string, mixed>> $reasons
     * @return list<string>
     */
    private function healthSignals(array $reasons): array
    {
        $signals = [];

        foreach ($reasons as $reason) {
            $code = is_string($reason['code'] ?? null) ? $reason['code'] : null;

            if ($code === null) {
                continue;
            }

            $detail = [];

            foreach ($reason as $field => $value) {
                if ($field === 'code' || $value === null || is_array($value)) {
                    continue;
                }

                $detail[] = $field.' '.(is_bool($value) ? Facts::yesNo($value) : (string) $value);
            }

            $signals[] = $detail === [] ? $code : $code.' ('.implode(', ', $detail).')';
        }

        return $signals;
    }

    /**
     * Explicit project memberships. A project with none is not a project nobody can see —
     * every workspace member above guest can open it — so the absence is stated rather than
     * left to be inferred from an empty list.
     *
     * @return list<string>
     */
    private function members(Project $project): array
    {
        $memberships = ProjectMember::query()
            ->where('project_id', $project->getKey())
            ->with('user:id,name,job_title')
            ->limit($this->limit('max_members', 40))
            ->get();

        $members = [];

        foreach ($memberships as $membership) {
            $user = $membership->user;

            if ($user === null) {
                continue;
            }

            $members[] = sprintf(
                '#%d %s — %s%s',
                (int) $user->getKey(),
                $user->name,
                $membership->role?->value ?? 'member',
                $user->job_title === null ? '' : ' ('.$user->job_title.')',
            );
        }

        return $members;
    }

    private function milestones(AgentContext $context, Project $project, string $timezone): ?string
    {
        if ($context->cannot(Permission::MilestoneView, $project)) {
            return null;
        }

        $milestones = Milestone::query()
            ->where('workspace_id', $context->workspaceId())
            ->where('project_id', $project->getKey())
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderBy('position')
            ->orderBy('id')
            ->limit(self::MAX_MILESTONES)
            ->get();

        if ($milestones->isEmpty()) {
            return null;
        }

        $today = $context->today()->toDateString();
        $facts = Facts::for('Milestones');
        $byStatus = [];
        $lines = [];

        foreach ($milestones as $milestone) {
            $status = $milestone->status?->value ?? MilestoneStatus::Planned->value;
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

            $due = Facts::date($milestone->due_date, $timezone);
            $overdue = $due !== null
                && $milestone->completed_at === null
                && $due < $today;

            $lines[] = sprintf(
                '%s — %s%s%s',
                $milestone->name,
                $status,
                $due === null ? '' : ', due '.$due.($overdue ? ' (overdue)' : ''),
                ', progress '.(int) $milestone->progress.'%',
            );
        }

        foreach ($byStatus as $status => $count) {
            $facts->count($status, $count);
        }

        return $facts->bullets('milestones', $lines)->toString();
    }

    /* ------------------------------------------------------------------ *
     * Aggregates
     * ------------------------------------------------------------------ */

    /**
     * @return array{total: int, open: int, overdue: int, unassigned: int}
     */
    private function taskCounts(AgentContext $context, Project $project): array
    {
        $today = $context->today()->toDateString();

        $row = Task::query()
            ->where('workspace_id', $context->workspaceId())
            ->where('project_id', $project->getKey())
            ->selectRaw('count(*) as total_tasks')
            ->selectRaw('sum(case when tasks.completed_at is null then 1 else 0 end) as open_tasks')
            ->selectRaw(
                'sum(case when tasks.completed_at is null and tasks.due_date is not null'
                .' and tasks.due_date < ? then 1 else 0 end) as overdue_tasks',
                [$today],
            )
            ->selectRaw(
                'sum(case when tasks.completed_at is null and tasks.assignee_id is null'
                .' then 1 else 0 end) as unassigned_tasks',
            )
            ->toBase()
            ->first();

        return [
            'total' => (int) ($row->total_tasks ?? 0),
            'open' => (int) ($row->open_tasks ?? 0),
            'overdue' => (int) ($row->overdue_tasks ?? 0),
            'unassigned' => (int) ($row->unassigned_tasks ?? 0),
        ];
    }

    private function limit(string $key, int $default): int
    {
        $configured = config('ai.context.'.$key);

        return is_int($configured) && $configured > 0 ? $configured : $default;
    }
}

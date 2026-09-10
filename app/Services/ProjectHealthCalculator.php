<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MilestoneStatus;
use App\Enums\ProjectHealth;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Derives `projects.health` from four measurable signals (ARCHITECTURE.md §5.2).
 *
 * The signals, and the only things that move the needle:
 *
 *   overdue tasks          how many open tasks are past their due date, absolutely and as
 *                          a share of the open work
 *   delayed milestones     milestones marked delayed, or open and past their due date
 *   workload concentration one person holding most of the remaining open work — the project
 *                          is a bus-factor away from stopping even while nothing is late
 *   deadline proximity     the project's own target date against the open work left
 *
 * Every threshold below is a named constant rather than a tuned coefficient, because the
 * only useful answer to "why is this project at risk?" is one a human can check.
 *
 * `health_set_manually` is honoured absolutely: when someone has set the health by hand,
 * {@see apply()} writes nothing and the assessment reports the stored value as `health`
 * while still returning what the calculator would have concluded as `computed`. Silently
 * overwriting a deliberate human judgement is the one behaviour this class must never have.
 */
final class ProjectHealthCalculator
{
    public const REASON_OVERDUE_TASKS = 'overdue_tasks';

    public const REASON_DELAYED_MILESTONES = 'delayed_milestones';

    public const REASON_WORKLOAD_CONCENTRATION = 'workload_concentration';

    public const REASON_DEADLINE_PROXIMITY = 'deadline_proximity';

    public const REASON_TARGET_DATE_PASSED = 'target_date_passed';

    public const REASON_PROJECT_CLOSED = 'project_closed';

    /** Open tasks past due, as an absolute count. */
    private const OVERDUE_AT_RISK = 3;

    private const OVERDUE_OFF_TRACK = 10;

    /** Open tasks past due, as a share of open tasks — catches small projects. */
    private const OVERDUE_SHARE_AT_RISK = 0.10;

    private const OVERDUE_SHARE_OFF_TRACK = 0.25;

    private const DELAYED_MILESTONES_AT_RISK = 1;

    private const DELAYED_MILESTONES_OFF_TRACK = 2;

    /** One assignee holding this share of the open work, on a project with enough of it. */
    private const CONCENTRATION_SHARE = 0.60;

    private const CONCENTRATION_MIN_OPEN = 5;

    /** A target date this close, with this much of the work still open. */
    private const DEADLINE_NEAR_DAYS = 7;

    private const DEADLINE_NEAR_OPEN_SHARE = 0.30;

    /** Severity ordering. Higher wins when several reasons apply. */
    private const RANK = [
        'on_track' => 0,
        'at_risk' => 1,
        'off_track' => 2,
    ];

    public function calculate(Project $project, ?DateTimeInterface $asOf = null): ProjectHealthAssessment
    {
        $id = (int) $project->getKey();

        return $this->assess([$project], $asOf)[$id];
    }

    /**
     * Assess many projects with a fixed number of queries: three grouped aggregates,
     * whatever the number of projects. Nothing per project, nothing per assignee.
     *
     * @param iterable<Project> $projects
     * @return array<int, ProjectHealthAssessment> keyed by project id
     */
    public function assess(iterable $projects, ?DateTimeInterface $asOf = null): array
    {
        /** @var array<int, Project> $indexed */
        $indexed = [];

        foreach ($projects as $project) {
            $indexed[(int) $project->getKey()] = $project;
        }

        if ($indexed === []) {
            return [];
        }

        $today = $asOf === null
            ? CarbonImmutable::now()->startOfDay()
            : CarbonImmutable::instance($asOf)->startOfDay();

        $ids = array_keys($indexed);

        $tasks = $this->taskSignals($ids, $today);
        $milestones = $this->milestoneSignals($ids, $today);
        $concentration = $this->concentrationSignals($ids);

        $assessments = [];

        foreach ($indexed as $id => $project) {
            $assessments[$id] = $this->assessOne(
                $project,
                $tasks[$id] ?? self::emptyTaskSignals(),
                $milestones[$id] ?? self::emptyMilestoneSignals(),
                $concentration[$id] ?? null,
                $today,
            );
        }

        return $assessments;
    }

    /**
     * Store the computed health unless the project carries a manual value.
     *
     * @return ProjectHealth the value now stored on the project
     */
    public function apply(Project $project, ?DateTimeInterface $asOf = null): ProjectHealth
    {
        $assessment = $this->calculate($project, $asOf);

        if (! $assessment->manual && $project->health !== $assessment->computed) {
            $project->health = $assessment->computed;

            // A recalculated signal is not an edit to the project. Bumping `updated_at`
            // here would reshuffle every "recently updated" list each time a task closed.
            Project::withoutTimestamps(static fn () => $project->save());
        }

        return $assessment->health;
    }

    /**
     * Store the computed health for many projects.
     *
     * The three aggregates plus at most one UPDATE per distinct health value — three, since
     * ProjectHealth has three cases. Projects pinned by hand are excluded from the writes,
     * not merely re-written with the same value.
     *
     * @param iterable<Project> $projects
     * @return array<int, ProjectHealthAssessment> keyed by project id
     */
    public function applyMany(iterable $projects, ?DateTimeInterface $asOf = null): array
    {
        $assessments = $this->assess($projects, $asOf);

        /** @var array<string, list<int>> $byHealth */
        $byHealth = [];

        foreach ($assessments as $id => $assessment) {
            if ($assessment->manual) {
                continue;
            }

            $byHealth[$assessment->computed->value][] = $id;
        }

        foreach ($byHealth as $health => $ids) {
            Project::query()
                ->whereIn('id', $ids)
                ->where('health_set_manually', false)
                ->where('health', '!=', $health)
                ->toBase()
                ->update(['health' => $health]);
        }

        return $assessments;
    }

    /* ------------------------------------------------------------------ *
     * Scoring
     * ------------------------------------------------------------------ */

    /**
     * @param array{total: int, open: int, overdue: int, oldest_overdue: ?string} $tasks
     * @param array{delayed: int, earliest_due: ?string} $milestones
     * @param array{assignee_id: int, open: int}|null $concentration
     */
    private function assessOne(
        Project $project,
        array $tasks,
        array $milestones,
        ?array $concentration,
        CarbonImmutable $today,
    ): ProjectHealthAssessment {
        $manual = (bool) $project->health_set_manually;
        $stored = $project->health instanceof ProjectHealth ? $project->health : ProjectHealth::OnTrack;

        // A finished or archived project cannot be off track: there is no work left for a
        // late task to endanger. Saying otherwise fills the portfolio view with noise.
        if ($project->is_archived || $project->completed_at !== null) {
            return new ProjectHealthAssessment(
                projectId: (int) $project->getKey(),
                health: $manual ? $stored : ProjectHealth::OnTrack,
                computed: ProjectHealth::OnTrack,
                manual: $manual,
                reasons: [[
                    'code' => self::REASON_PROJECT_CLOSED,
                    'severity' => ProjectHealth::OnTrack->value,
                    'archived' => (bool) $project->is_archived,
                    'completed_at' => $project->completed_at?->toDateString(),
                ]],
            );
        }

        $reasons = [];

        $open = $tasks['open'];

        if ($tasks['overdue'] > 0) {
            $share = $open > 0 ? round($tasks['overdue'] / $open, 4) : 0.0;

            $severity = $tasks['overdue'] >= self::OVERDUE_OFF_TRACK || $share >= self::OVERDUE_SHARE_OFF_TRACK
                ? ProjectHealth::OffTrack
                : ($tasks['overdue'] >= self::OVERDUE_AT_RISK || $share >= self::OVERDUE_SHARE_AT_RISK
                    ? ProjectHealth::AtRisk
                    : ProjectHealth::OnTrack);

            if ($severity !== ProjectHealth::OnTrack) {
                $reasons[] = [
                    'code' => self::REASON_OVERDUE_TASKS,
                    'severity' => $severity->value,
                    'count' => $tasks['overdue'],
                    'open_tasks' => $open,
                    'share' => $share,
                    'oldest_due_date' => $tasks['oldest_overdue'],
                ];
            }
        }

        if ($milestones['delayed'] >= self::DELAYED_MILESTONES_AT_RISK) {
            $severity = $milestones['delayed'] >= self::DELAYED_MILESTONES_OFF_TRACK
                ? ProjectHealth::OffTrack
                : ProjectHealth::AtRisk;

            $reasons[] = [
                'code' => self::REASON_DELAYED_MILESTONES,
                'severity' => $severity->value,
                'count' => $milestones['delayed'],
                'earliest_due_date' => $milestones['earliest_due'],
            ];
        }

        if ($concentration !== null && $open >= self::CONCENTRATION_MIN_OPEN) {
            $share = round($concentration['open'] / $open, 4);

            if ($share >= self::CONCENTRATION_SHARE) {
                $reasons[] = [
                    'code' => self::REASON_WORKLOAD_CONCENTRATION,
                    'severity' => ProjectHealth::AtRisk->value,
                    'assignee_id' => $concentration['assignee_id'],
                    'assigned_open_tasks' => $concentration['open'],
                    'open_tasks' => $open,
                    'share' => $share,
                ];
            }
        }

        $target = $project->target_date;

        if ($target !== null && $open > 0) {
            // Both sides are reduced to a bare date in one timezone before subtracting:
            // `target_date` is a date column, and comparing it against an instant in a
            // different zone is how a deadline ends up a day out.
            $targetDay = CarbonImmutable::parse(
                CarbonImmutable::instance($target)->toDateString(),
                'UTC',
            );
            $reference = CarbonImmutable::parse($today->toDateString(), 'UTC');
            $daysRemaining = (int) $reference->diffInDays($targetDay, false);

            if ($daysRemaining < 0) {
                $reasons[] = [
                    'code' => self::REASON_TARGET_DATE_PASSED,
                    'severity' => ProjectHealth::OffTrack->value,
                    'target_date' => $targetDay->toDateString(),
                    'days_overdue' => -$daysRemaining,
                    'open_tasks' => $open,
                ];
            } elseif ($daysRemaining <= self::DEADLINE_NEAR_DAYS) {
                $openShare = $tasks['total'] > 0 ? round($open / $tasks['total'], 4) : 0.0;

                if ($openShare >= self::DEADLINE_NEAR_OPEN_SHARE) {
                    $reasons[] = [
                        'code' => self::REASON_DEADLINE_PROXIMITY,
                        'severity' => ProjectHealth::AtRisk->value,
                        'target_date' => $targetDay->toDateString(),
                        'days_remaining' => $daysRemaining,
                        'open_tasks' => $open,
                        'total_tasks' => $tasks['total'],
                        'open_share' => $openShare,
                    ];
                }
            }
        }

        usort(
            $reasons,
            static fn (array $a, array $b): int => (self::RANK[$b['severity']] ?? 0) <=> (self::RANK[$a['severity']] ?? 0),
        );

        $computed = ProjectHealth::OnTrack;

        foreach ($reasons as $reason) {
            $severity = ProjectHealth::tryFrom((string) $reason['severity']) ?? ProjectHealth::OnTrack;

            if ((self::RANK[$severity->value] ?? 0) > (self::RANK[$computed->value] ?? 0)) {
                $computed = $severity;
            }
        }

        return new ProjectHealthAssessment(
            projectId: (int) $project->getKey(),
            health: $manual ? $stored : $computed,
            computed: $computed,
            manual: $manual,
            reasons: $reasons,
        );
    }

    /* ------------------------------------------------------------------ *
     * Aggregates
     * ------------------------------------------------------------------ */

    /**
     * @param list<int> $projectIds
     * @return array<int, array{total: int, open: int, overdue: int, oldest_overdue: ?string}>
     */
    private function taskSignals(array $projectIds, CarbonImmutable $today): array
    {
        $todayString = $today->toDateString();

        // `due_date < today` rather than whereDate(): the raw comparison reads off
        // index(workspace_id, due_date) and is exact on both engines, because SQLite's
        // stored "Y-m-d H:i:s" can only sort after the bare date for the same day.
        $overdue = 'tasks.completed_at is null and tasks.due_date is not null and tasks.due_date < ?';

        $rows = Task::query()
            ->whereIn('tasks.project_id', $projectIds)
            ->groupBy('tasks.project_id')
            ->selectRaw('tasks.project_id as project_id')
            ->selectRaw('count(*) as total_tasks')
            ->selectRaw('sum(case when tasks.completed_at is null then 1 else 0 end) as open_tasks')
            ->selectRaw('sum(case when '.$overdue.' then 1 else 0 end) as overdue_tasks', [$todayString])
            ->selectRaw('min(case when '.$overdue.' then tasks.due_date else null end) as oldest_overdue', [$todayString])
            ->toBase()
            ->get();

        $signals = [];

        foreach ($rows as $row) {
            $signals[(int) $row->project_id] = [
                'total' => (int) $row->total_tasks,
                'open' => (int) $row->open_tasks,
                'overdue' => (int) $row->overdue_tasks,
                'oldest_overdue' => self::dateString($row->oldest_overdue),
            ];
        }

        return $signals;
    }

    /**
     * A milestone counts as delayed when it says so, or when it is still open and its due
     * date has passed — a project manager who never touches the status field must not be
     * able to hide a late milestone.
     *
     * @param list<int> $projectIds
     * @return array<int, array{delayed: int, earliest_due: ?string}>
     */
    private function milestoneSignals(array $projectIds, CarbonImmutable $today): array
    {
        $openStatuses = array_values(array_map(
            static fn (MilestoneStatus $status): string => $status->value,
            array_filter(
                MilestoneStatus::cases(),
                static fn (MilestoneStatus $status): bool => $status->isOpen(),
            ),
        ));

        $placeholders = implode(', ', array_fill(0, count($openStatuses), '?'));

        $delayed = '(milestones.status = ? or (milestones.status in ('.$placeholders.')'
            .' and milestones.completed_at is null'
            .' and milestones.due_date is not null and milestones.due_date < ?))';

        $bindings = array_merge(
            [MilestoneStatus::Delayed->value],
            $openStatuses,
            [$today->toDateString()],
        );

        $rows = Milestone::query()
            ->whereIn('milestones.project_id', $projectIds)
            ->groupBy('milestones.project_id')
            ->selectRaw('milestones.project_id as project_id')
            ->selectRaw('sum(case when '.$delayed.' then 1 else 0 end) as delayed_count', $bindings)
            ->selectRaw('min(case when '.$delayed.' then milestones.due_date else null end) as earliest_due', $bindings)
            ->toBase()
            ->get();

        $signals = [];

        foreach ($rows as $row) {
            $signals[(int) $row->project_id] = [
                'delayed' => (int) $row->delayed_count,
                'earliest_due' => self::dateString($row->earliest_due),
            ];
        }

        return $signals;
    }

    /**
     * Open tasks per assignee, reduced in PHP to the single busiest person per project.
     *
     * One grouped query covers every project; the result set is bounded by the number of
     * distinct (project, assignee) pairs, which is the same set a workload report reads.
     *
     * @param list<int> $projectIds
     * @return array<int, array{assignee_id: int, open: int}>
     */
    private function concentrationSignals(array $projectIds): array
    {
        $rows = Task::query()
            ->whereIn('tasks.project_id', $projectIds)
            ->whereNull('tasks.completed_at')
            ->whereNotNull('tasks.assignee_id')
            ->groupBy('tasks.project_id', 'tasks.assignee_id')
            ->selectRaw('tasks.project_id as project_id')
            ->selectRaw('tasks.assignee_id as assignee_id')
            ->selectRaw('count(*) as open_tasks')
            ->toBase()
            ->get();

        $busiest = [];

        foreach ($rows as $row) {
            $projectId = (int) $row->project_id;
            $open = (int) $row->open_tasks;

            if (! isset($busiest[$projectId]) || $open > $busiest[$projectId]['open']) {
                $busiest[$projectId] = [
                    'assignee_id' => (int) $row->assignee_id,
                    'open' => $open,
                ];
            }
        }

        return $busiest;
    }

    /**
     * @return array{total: int, open: int, overdue: int, oldest_overdue: null}
     */
    private static function emptyTaskSignals(): array
    {
        return ['total' => 0, 'open' => 0, 'overdue' => 0, 'oldest_overdue' => null];
    }

    /**
     * @return array{delayed: int, earliest_due: null}
     */
    private static function emptyMilestoneSignals(): array
    {
        return ['delayed' => 0, 'earliest_due' => null];
    }

    /**
     * MySQL hands back a bare `Y-m-d` for a date column; SQLite hands back whatever the
     * date cast wrote, which is `Y-m-d H:i:s`. Reasons are facts other systems read, so
     * they carry one shape.
     */
    private static function dateString(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return mb_substr($value, 0, 10);
    }
}

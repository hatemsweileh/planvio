<?php

declare(strict_types=1);

namespace App\Livewire\App\Projects;

use App\Livewire\App\Projects\Concerns\InteractsWithProjectShell;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\BudgetService;
use App\Services\ProjectBudget;
use App\Services\ProjectHealthAssessment;
use App\Services\ProjectHealthCalculator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The project's front page: where it stands, what is next, and what is worrying.
 *
 * Everything on it is a read. The one thing that is not — the favourite star — comes from
 * the shell trait, because the star belongs to the header and the header is shared.
 *
 * The risks panel is the reason this screen exists rather than being a chart.
 * {@see ProjectHealthCalculator} produces facts — counts, dates, shares — and they are
 * rendered as facts. Anything narrative is labelled as analysis and attributed, because a
 * sentence and a measurement carry very different weight and a UI that blurs the two teaches
 * people to distrust both.
 */
#[Layout('layouts.app')]
final class Show extends Component
{
    use InteractsWithProjectShell;

    /** Milestones and attachments shown before the reader has to go to the full tab. */
    private const MILESTONE_PREVIEW = 6;

    private const ATTACHMENT_PREVIEW = 6;

    public Workspace $workspace;

    public Project $project;

    public function mount(Workspace $workspace, Project $project): void
    {
        $this->authorize('view', $project);

        $this->workspace = $workspace;
        $this->project = $project;
    }

    /* ------------------------------------------------------------------ *
     * Headline numbers
     * ------------------------------------------------------------------ */

    /**
     * The stat strip, as one grouped query rather than six counts.
     *
     * @return array{tasks: int, completed: int, open: int, overdue: int, milestones: int, milestones_done: int}
     */
    #[Computed]
    public function counts(): array
    {
        $today = Carbon::today()->toDateString();

        $tasks = $this->project->tasks()
            ->toBase()
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when tasks.completed_at is not null then 1 else 0 end) as completed')
            ->selectRaw(
                'sum(case when tasks.completed_at is null and tasks.due_date is not null'
                .' and tasks.due_date < ? then 1 else 0 end) as overdue',
                [$today],
            )
            ->first();

        $milestones = $this->project->milestones()
            ->toBase()
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when milestones.completed_at is not null then 1 else 0 end) as completed')
            ->first();

        $total = (int) ($tasks->total ?? 0);
        $completed = (int) ($tasks->completed ?? 0);

        return [
            'tasks' => $total,
            'completed' => $completed,
            'open' => max(0, $total - $completed),
            'overdue' => (int) ($tasks->overdue ?? 0),
            'milestones' => (int) ($milestones->total ?? 0),
            'milestones_done' => (int) ($milestones->completed ?? 0),
        ];
    }

    /* ------------------------------------------------------------------ *
     * Health
     * ------------------------------------------------------------------ */

    #[Computed]
    public function assessment(): ProjectHealthAssessment
    {
        return app(ProjectHealthCalculator::class)->calculate($this->project);
    }

    /**
     * The assessment's reasons, turned into sentences a person can check.
     *
     * Each entry keeps its numbers in the text. "At risk" is an opinion; "7 of 21 open tasks
     * are overdue, the oldest since 2 August" is something you can go and look at.
     *
     * @return list<array{color: string, title: string, detail: string}>
     */
    #[Computed]
    public function healthFacts(): array
    {
        $assessment = $this->assessment;
        $names = $this->assigneeNames($assessment);
        $facts = [];

        foreach ($assessment->reasons as $reason) {
            $code = (string) ($reason['code'] ?? '');
            $severity = (string) ($reason['severity'] ?? 'on_track');
            $color = match ($severity) {
                'off_track' => 'red',
                'at_risk' => 'amber',
                default => 'green',
            };

            $entry = match ($code) {
                ProjectHealthCalculator::REASON_OVERDUE_TASKS => [
                    'title' => __('Overdue tasks'),
                    'detail' => __(':count of :open open tasks are past their due date (:share).', [
                        'count' => (int) ($reason['count'] ?? 0),
                        'open' => (int) ($reason['open_tasks'] ?? 0),
                        'share' => $this->percent($reason['share'] ?? null),
                    ]).' '.$this->sinceSentence($reason['oldest_due_date'] ?? null),
                ],
                ProjectHealthCalculator::REASON_DELAYED_MILESTONES => [
                    'title' => __('Delayed milestones'),
                    'detail' => __(':count milestones are marked delayed or are open past their due date.', [
                        'count' => (int) ($reason['count'] ?? 0),
                    ]).' '.$this->earliestSentence($reason['earliest_due_date'] ?? null),
                ],
                ProjectHealthCalculator::REASON_WORKLOAD_CONCENTRATION => [
                    'title' => __('Work concentrated on one person'),
                    'detail' => __(':name holds :assigned of the :open open tasks (:share).', [
                        'name' => $names[(int) ($reason['assignee_id'] ?? 0)] ?? __('One assignee'),
                        'assigned' => (int) ($reason['assigned_open_tasks'] ?? 0),
                        'open' => (int) ($reason['open_tasks'] ?? 0),
                        'share' => $this->percent($reason['share'] ?? null),
                    ]),
                ],
                ProjectHealthCalculator::REASON_DEADLINE_PROXIMITY => [
                    'title' => __('Deadline is close'),
                    'detail' => __(':days days to the target date with :open of :total tasks still open.', [
                        'days' => (int) ($reason['days_remaining'] ?? 0),
                        'open' => (int) ($reason['open_tasks'] ?? 0),
                        'total' => (int) ($reason['total_tasks'] ?? 0),
                    ]),
                ],
                ProjectHealthCalculator::REASON_TARGET_DATE_PASSED => [
                    'title' => __('Target date has passed'),
                    'detail' => __('The target date was :days days ago and :open tasks are still open.', [
                        'days' => (int) ($reason['days_overdue'] ?? 0),
                        'open' => (int) ($reason['open_tasks'] ?? 0),
                    ]),
                ],
                ProjectHealthCalculator::REASON_PROJECT_CLOSED => [
                    'title' => __('Project is closed'),
                    'detail' => ($reason['archived'] ?? false)
                        ? __('It is archived, so nothing outstanding can put it at risk.')
                        : __('It is complete, so nothing outstanding can put it at risk.'),
                ],
                default => null,
            };

            if ($entry !== null) {
                $facts[] = ['color' => $color, 'title' => $entry['title'], 'detail' => trim($entry['detail'])];
            }
        }

        return $facts;
    }

    /* ------------------------------------------------------------------ *
     * Panels
     * ------------------------------------------------------------------ */

    /**
     * @return EloquentCollection<int, Milestone>
     */
    #[Computed]
    public function milestones(): EloquentCollection
    {
        return $this->project->milestones()
            ->with('owner:id,name,avatar_path')
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => fn (Builder $tasks): Builder => $tasks
                    ->whereNotNull('tasks.completed_at'),
            ])
            ->orderByRaw('case when milestones.due_date is null then 1 else 0 end')
            ->orderBy('milestones.due_date')
            ->orderBy('milestones.position')
            ->limit(self::MILESTONE_PREVIEW)
            ->get();
    }

    /**
     * @return EloquentCollection<int, Activity>
     */
    #[Computed]
    public function activity(): EloquentCollection
    {
        return Activity::query()
            ->where('activities.workspace_id', $this->project->workspace_id)
            ->where('activities.project_id', $this->project->getKey())
            ->with('causer:id,name,avatar_path')
            ->recent(10)
            ->get();
    }

    /**
     * The project's own people, managers first — the order the team panel reads in.
     *
     * @return EloquentCollection<int, User>
     */
    #[Computed]
    public function team(): EloquentCollection
    {
        return $this->project->members()
            ->select(['users.id', 'users.name', 'users.email', 'users.avatar_path', 'users.job_title'])
            ->orderByRaw("case project_members.role when 'manager' then 0 when 'member' then 1 else 2 end")
            ->orderBy('users.name')
            ->get();
    }

    /**
     * @return EloquentCollection<int, Attachment>
     */
    #[Computed]
    public function attachments(): EloquentCollection
    {
        return $this->project->attachments()
            ->with('uploader:id,name,avatar_path')
            ->latest('attachments.created_at')
            ->limit(self::ATTACHMENT_PREVIEW)
            ->get();
    }

    #[Computed]
    public function attachmentCount(): int
    {
        return $this->project->attachments()->count();
    }

    /**
     * Planned against actual, plus the logged and billable minutes behind it.
     *
     * Null when the acting user may not see money: `budget.view` is a `+` cell, so a
     * workspace manager sees it only in the projects they manage.
     */
    #[Computed]
    public function budget(): ?ProjectBudget
    {
        if (! Gate::allows('viewBudget', $this->project)) {
            return null;
        }

        return app(BudgetService::class)->forProject($this->project);
    }

    public function render(): View
    {
        // Everything the page reads is loaded here: Model::preventLazyLoading() is on
        // outside production, so a relation touched in Blade without this throws.
        $this->project->loadMissing([
            'status:id,name,color,category',
            'owner:id,name,avatar_path',
            'manager:id,name,avatar_path,job_title',
        ]);

        return view('livewire.app.projects.show')
            ->title($this->project->name.' · '.__('Overview'));
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    /**
     * Names for the assignee ids the assessment mentions — one query, usually zero rows.
     *
     * @return array<int, string>
     */
    private function assigneeNames(ProjectHealthAssessment $assessment): array
    {
        $ids = [];

        foreach ($assessment->reasons as $reason) {
            if (isset($reason['assignee_id'])) {
                $ids[] = (int) $reason['assignee_id'];
            }
        }

        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', array_unique($ids))
            ->pluck('name', 'id')
            ->map(static fn (mixed $name): string => (string) $name)
            ->all();
    }

    private function percent(mixed $share): string
    {
        return is_numeric($share) ? round((float) $share * 100).'%' : '—';
    }

    private function sinceSentence(mixed $date): string
    {
        return is_string($date) && $date !== ''
            ? __('The oldest has been due since :date.', [
                'date' => Carbon::parse($date)->isoFormat('D MMM YYYY'),
            ])
            : '';
    }

    private function earliestSentence(mixed $date): string
    {
        return is_string($date) && $date !== ''
            ? __('The earliest was due :date.', [
                'date' => Carbon::parse($date)->isoFormat('D MMM YYYY'),
            ])
            : '';
    }
}

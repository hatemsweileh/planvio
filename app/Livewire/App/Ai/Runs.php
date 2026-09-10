<?php

declare(strict_types=1);

namespace App\Livewire\App\Ai;

use App\Enums\AiRunStatus;
use App\Livewire\App\Ai\Support\ToolTrace;
use App\Models\AiRun;
use App\Models\AiToolRun;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Run history: every execution of the agent loop in this workspace, and what each one did.
 *
 * Gated on `ai.view_logs`, which is the audit permission — an owner or admin unconditionally,
 * a workspace manager inside the projects they manage. Somebody's own conversation is theirs
 * to read; the whole workspace's is an audit.
 *
 * ## Why the trace is here rather than only in the chat
 *
 * A conversation shows what the agent did *for you*. This shows what it did at all —
 * including the runs an automation started at 3am and the ones somebody else asked for. The
 * per-run tool trace is loaded only for the run a person opened, because a hundred runs
 * would otherwise be a hundred joins for a table where almost every row is never expanded.
 *
 * It renders three ways: as a pane in the AI workspace, narrowed to one project on that
 * project's AI tab, and on its own route pointed at a single run - which is where a
 * notification about that run links. The layout attribute applies only to the last.
 */
#[Layout('layouts.app')]
final class Runs extends Component
{
    use WithPagination;

    private const PER_PAGE = 25;

    /** A run is capped at 25 tool calls by config('ai.limits'); this is headroom, not a policy. */
    private const MAX_TRACE_ROWS = 200;

    public Workspace $workspace;

    /** Narrows the history to one project, when the screen is a project's own AI tab. */
    public ?int $projectId = null;

    public string $status = 'all';

    /** The run whose tool trace is open. One at a time: this is a list, not a dashboard. */
    public ?int $openRunId = null;

    /**
     * Set when the screen was opened at one named run. The list then shows that run alone:
     * a link from a notification means "this one", and dropping somebody onto page four of
     * a history with the row highlighted somewhere below the fold is not that.
     */
    public ?string $runUuid = null;

    /**
     * @param string|null $run the `ai_runs.uuid` from the route, when there is one
     */
    public function mount(Workspace $workspace, ?Project $project = null, ?string $run = null): void
    {
        $this->authorize('view', $workspace);
        $this->authorize('ai.view_logs', $workspace);

        $this->workspace = $workspace;
        $this->projectId = $project?->getKey() === null ? null : (int) $project->getKey();

        if ($run === null) {
            return;
        }

        // Found through the workspace scope, so a uuid from another tenant is a 404 rather
        // than a 403 that confirms the run exists.
        $named = AiRun::query()->forWorkspace($workspace)->where('uuid', $run)->first();

        abort_if(! $named instanceof AiRun, 404);

        $this->authorize('view', $named);

        $this->runUuid = (string) $named->uuid;
        $this->openRunId = (int) $named->getKey();
    }

    /**
     * Widen a single-run view back to the whole history.
     */
    public function showAllRuns(): void
    {
        $this->runUuid = null;
        $this->resetPage();

        unset($this->runs);
    }

    /* ------------------------------------------------------------------ *
     * Filtering
     * ------------------------------------------------------------------ */

    public function updatedStatus(): void
    {
        if ($this->status !== 'all' && AiRunStatus::tryFrom($this->status) === null) {
            $this->status = 'all';
        }

        $this->openRunId = null;
        $this->resetPage();
    }

    /**
     * @return array<string, string>
     */
    public function statusOptions(): array
    {
        return ['all' => __('All statuses')] + AiRunStatus::options();
    }

    public function toggleRun(int $runId): void
    {
        $this->openRunId = $this->openRunId === $runId ? null : $runId;
    }

    /* ------------------------------------------------------------------ *
     * Reading
     * ------------------------------------------------------------------ */

    /**
     * @return LengthAwarePaginator<int, AiRun>
     */
    #[Computed]
    public function runs(): LengthAwarePaginator
    {
        $status = AiRunStatus::tryFrom($this->status);

        return AiRun::query()
            ->forWorkspace($this->workspace)
            ->when($this->runUuid !== null, fn ($query) => $query->where('uuid', $this->runUuid))
            ->when($this->projectId !== null, fn ($query) => $query->where('project_id', $this->projectId))
            ->when($status !== null && $this->runUuid === null, fn ($query) => $query->withStatus($status))
            ->with([
                'user:id,name,avatar_path',
                'project:id,name,slug,color,key',
            ])
            ->withCount('toolRuns')
            ->recent()
            ->paginate(self::PER_PAGE);
    }

    /**
     * The tool calls of the one open run, as trace lines, each with the person who approved
     * it where one had to.
     *
     * Loaded only for the run somebody actually opened: a page of twenty-five runs would
     * otherwise join a table where almost every row is never looked at.
     *
     * @return list<array{trace: ToolTrace, approver: string|null, approvedAt: Carbon|null}>
     */
    #[Computed]
    public function openTrace(): array
    {
        if ($this->openRunId === null) {
            return [];
        }

        return AiToolRun::query()
            ->where('ai_run_id', $this->openRunId)
            ->with('approver:id,name,avatar_path')
            ->ordered()
            ->limit(self::MAX_TRACE_ROWS)
            ->get()
            ->map(static fn (AiToolRun $row): array => [
                'trace' => ToolTrace::fromToolRun($row),
                'approver' => $row->approver?->name,
                'approvedAt' => $row->approved_at,
            ])
            ->values()
            ->all();
    }

    public function render(): View
    {
        return view('livewire.app.ai.runs')->title(__('Runs'));
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\App\Export;

use App\Enums\Permission;
use App\Enums\Priority;
use App\Http\Controllers\ExportController;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Tag;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Export\CsvExport;
use App\Services\Export\ExportFactory;
use App\Services\Export\ExportFilters;
use App\Services\Export\ExportType;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Take it away as a spreadsheet.
 *
 * ## The filters are the ones you already had
 *
 * Every filter here is bound to the same query-string key the task list and the board use
 * (`q`, `status`, `assignee`, `priority`, `tag`, `milestone`, `due`, `overdue`,
 * `unassigned`, `done`). A filtered list URL and this screen's URL are therefore the same
 * parameters, so "export what I am looking at" is a matter of changing the path — and the
 * download link is this page's own URL with the filters attached, which is what makes it a
 * link somebody can bookmark or curl rather than a button that only works in a session.
 *
 * ## Why the download is a link and not an action
 *
 * A Livewire action that returns a file encodes the whole thing into its JSON response.
 * That is fine for a dozen rows and hopeless for a hundred thousand, so the button is an
 * anchor to {@see ExportController}, which streams. This component's
 * job is to let somebody see what they are about to get — the row count, the columns, the
 * first few rows — and then hand them that link.
 *
 * ## Permissions
 *
 * The screen asks for `reports.view`, which guests do not hold: a bulk extract is the one
 * action that can put an entire workspace into a file. Beyond that, every export narrows
 * itself again to the projects the reader may open, and the time export falls back to the
 * reader's own hours in projects where they lack `time.view_all`.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /** Rows shown on screen before the download. Enough to recognise the shape, not a table. */
    private const PREVIEW_ROWS = 8;

    public Workspace $workspace;

    #[Url(as: 'type', except: 'tasks', history: true)]
    public string $type = 'tasks';

    /** @var list<int> */
    #[Url(as: 'project', except: [], history: true)]
    public array $projectIds = [];

    #[Url(as: 'q', except: '', history: true)]
    public string $search = '';

    /** @var list<int> */
    #[Url(as: 'status', except: [], history: true)]
    public array $statusIds = [];

    /** @var list<int> */
    #[Url(as: 'assignee', except: [], history: true)]
    public array $assigneeIds = [];

    /** @var list<string> */
    #[Url(as: 'priority', except: [], history: true)]
    public array $priorities = [];

    /** @var list<int> */
    #[Url(as: 'tag', except: [], history: true)]
    public array $tagIds = [];

    /** @var list<int> */
    #[Url(as: 'milestone', except: [], history: true)]
    public array $milestoneIds = [];

    #[Url(as: 'due', except: '', history: true)]
    public string $dueRange = '';

    #[Url(as: 'overdue', except: false, history: true)]
    public bool $overdueOnly = false;

    #[Url(as: 'unassigned', except: false, history: true)]
    public bool $unassignedOnly = false;

    /**
     * An export defaults to everything, where a list defaults to open work. The two screens
     * answer different questions: "what am I working on" hides finished tasks, "give me the
     * data" that leaves them out is missing half the record.
     */
    #[Url(as: 'done', except: true, history: true)]
    public bool $includeCompleted = true;

    #[Url(as: 'archived', except: false, history: true)]
    public bool $includeArchived = false;

    #[Url(as: 'billable', except: false, history: true)]
    public bool $billableOnly = false;

    #[Url(as: 'from', except: '', history: true)]
    public string $from = '';

    #[Url(as: 'to', except: '', history: true)]
    public string $to = '';

    public function mount(Workspace $workspace): void
    {
        $this->authorize('view', $workspace);
        $this->authorize(Permission::ReportsView->value, $workspace);

        $this->workspace = $workspace;

        // The type arrives from the query string, and the view reads it as an enum. An
        // unknown value has to be corrected here rather than survive as far as a match().
        if (ExportType::tryFrom($this->type) === null) {
            $this->type = ExportType::Tasks->value;
        }

        if (! in_array($this->dueRange, ExportFilters::DUE_RANGES, true)) {
            $this->dueRange = '';
        }
    }

    public function render(): View
    {
        return view('livewire.app.export.index')->title(__('Export'));
    }

    /* ------------------------------------------------------------------ *
     * What is being exported
     * ------------------------------------------------------------------ */

    public function exportType(): ExportType
    {
        return ExportType::tryFrom($this->type) ?? ExportType::Tasks;
    }

    public function selectType(string $type): void
    {
        if (ExportType::tryFrom($type) !== null) {
            $this->type = $type;
            $this->forgetPreview();
        }
    }

    public function filters(): ExportFilters
    {
        return ExportFilters::fromArray([
            'project' => $this->projectIds,
            'q' => $this->search,
            'status' => $this->statusIds,
            'assignee' => $this->assigneeIds,
            'priority' => $this->priorities,
            'tag' => $this->tagIds,
            'milestone' => $this->milestoneIds,
            'due' => $this->dueRange,
            'overdue' => $this->overdueOnly,
            'unassigned' => $this->unassignedOnly,
            'done' => $this->includeCompleted,
            'archived' => $this->includeArchived,
            'billable' => $this->billableOnly,
            'from' => $this->from,
            'to' => $this->to,
        ]);
    }

    #[Computed]
    public function export(): CsvExport
    {
        return app(ExportFactory::class)->make(
            $this->exportType(),
            $this->workspace,
            $this->actor(),
            $this->filters(),
        );
    }

    #[Computed]
    public function total(): int
    {
        return $this->export->total();
    }

    /**
     * The first few rows, exactly as the file will hold them.
     *
     * The generator is abandoned after {@see self::PREVIEW_ROWS}, so this costs one chunk
     * however large the export is.
     *
     * @return list<list<scalar|null>>
     */
    #[Computed]
    public function preview(): array
    {
        $rows = [];

        foreach ($this->export->rows() as $row) {
            $rows[] = $row;

            if (count($rows) >= self::PREVIEW_ROWS) {
                break;
            }
        }

        return $rows;
    }

    /**
     * The URL the download button points at: this screen's filters, on the streaming route.
     */
    public function downloadUrl(): string
    {
        return route('app.export.download', [
            $this->workspace,
            $this->exportType()->value,
            ...$this->filters()->toQuery(),
        ]);
    }

    /* ------------------------------------------------------------------ *
     * Filter state
     * ------------------------------------------------------------------ */

    /**
     * Add or remove one value from a multi-select filter. Mirrors the task list's control
     * of the same name so the two bars behave identically.
     */
    public function toggleFilter(string $filter, int|string $value): void
    {
        if (! in_array($filter, ['projectIds', 'statusIds', 'assigneeIds', 'priorities', 'tagIds', 'milestoneIds'], true)) {
            return;
        }

        $current = $this->{$filter};
        $value = $filter === 'priorities' ? (string) $value : (int) $value;
        $index = array_search($value, $current, true);

        if ($index === false) {
            $current[] = $value;
        } else {
            unset($current[$index]);
        }

        $this->{$filter} = array_values($current);

        // Statuses and milestones belong to one project. Changing which projects are in
        // scope can strand a selection pointing at a column that is no longer offered.
        if ($filter === 'projectIds') {
            $this->statusIds = [];
            $this->milestoneIds = [];
        }

        $this->forgetPreview();
    }

    public function setDueRange(string $range): void
    {
        $this->dueRange = in_array($range, ExportFilters::DUE_RANGES, true) ? $range : '';
        $this->forgetPreview();
    }

    public function clearFilters(): void
    {
        $this->projectIds = [];
        $this->search = '';
        $this->statusIds = [];
        $this->assigneeIds = [];
        $this->priorities = [];
        $this->tagIds = [];
        $this->milestoneIds = [];
        $this->dueRange = '';
        $this->overdueOnly = false;
        $this->unassignedOnly = false;
        $this->includeCompleted = true;
        $this->includeArchived = false;
        $this->billableOnly = false;
        $this->from = '';
        $this->to = '';

        $this->forgetPreview();
    }

    public function updated(): void
    {
        $this->forgetPreview();
    }

    private function forgetPreview(): void
    {
        unset($this->export, $this->total, $this->preview);
    }

    /* ------------------------------------------------------------------ *
     * Option lists
     * ------------------------------------------------------------------ */

    /**
     * @return EloquentCollection<int, Project>
     */
    #[Computed]
    public function projectOptions(): EloquentCollection
    {
        return Project::query()
            ->forWorkspace($this->workspace)
            ->visibleTo($this->actor())
            ->when(! $this->includeArchived, fn ($query) => $query->active())
            ->orderBy('projects.name')
            ->get(['projects.id', 'projects.workspace_id', 'projects.name', 'projects.key', 'projects.color', 'projects.is_archived']);
    }

    /**
     * The one project whose columns and milestones can be offered as filters.
     *
     * Statuses are per project, so a status filter only has a meaning while exactly one
     * project is selected. With none or several, the control is hidden rather than shown
     * full of names that mean different things in different projects.
     */
    #[Computed]
    public function scopedProject(): ?Project
    {
        if (count($this->projectIds) !== 1) {
            return null;
        }

        return $this->projectOptions->firstWhere('id', $this->projectIds[0]);
    }

    /**
     * @return EloquentCollection<int, TaskStatus>
     */
    #[Computed]
    public function statusOptions(): EloquentCollection
    {
        $project = $this->scopedProject;

        return $project === null
            ? new EloquentCollection
            : TaskStatus::query()->forProject($project)->ordered()->get();
    }

    /**
     * @return EloquentCollection<int, Milestone>
     */
    #[Computed]
    public function milestoneOptions(): EloquentCollection
    {
        $project = $this->scopedProject;

        return $project === null
            ? new EloquentCollection
            : Milestone::query()->forProject($project)->ordered()->get(['id', 'name', 'status', 'due_date']);
    }

    /**
     * @return EloquentCollection<int, User>
     */
    #[Computed]
    public function memberOptions(): EloquentCollection
    {
        return $this->workspace->members()
            ->where('users.is_active', true)
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.avatar_path']);
    }

    /**
     * @return EloquentCollection<int, Tag>
     */
    #[Computed]
    public function tagOptions(): EloquentCollection
    {
        return Tag::query()->forWorkspace($this->workspace)->ordered()->get(['id', 'name', 'color']);
    }

    /**
     * @return list<Priority>
     */
    public function priorityOptions(): array
    {
        return array_reverse(Priority::cases());
    }

    /**
     * @return array<string, string>
     */
    public function dueRanges(): array
    {
        return [
            'overdue' => __('Overdue'),
            'today' => __('Due today'),
            'week' => __('Due in 7 days'),
            'month' => __('Due in 30 days'),
            'none' => __('No due date'),
        ];
    }

    /**
     * Which controls apply to the table being exported. A billable switch means nothing on
     * a project register, and showing it greyed out would be noise.
     */
    public function shows(string $control): bool
    {
        $type = $this->exportType();

        return match ($control) {
            'taskFilters' => $type === ExportType::Tasks,
            'dates' => $type === ExportType::Time,
            'billable' => $type === ExportType::Time,
            'people' => $type !== ExportType::Projects,
            default => true,
        };
    }

    public function activeFilterCount(): int
    {
        return $this->filters()->activeCount();
    }

    private function actor(): User
    {
        $user = auth()->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}

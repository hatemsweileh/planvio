<?php

declare(strict_types=1);

namespace App\Livewire\App\Tasks;

use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\CreateTaskData;
use App\Enums\Priority;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Create a task from anywhere: the `C` shortcut, the header's plus menu, the empty state on
 * a board that has nothing on it yet.
 *
 * Two constraints shape it.
 *
 *   - **It costs the shell nothing.** The component sits on every page in the product, so
 *     while it is closed it renders a single empty element and runs no query. Projects,
 *     columns and members are only read once somebody actually opens it.
 *   - **It never guesses about permission.** The project list offers only the projects this
 *     person may create a task in, and the create still re-checks the chosen one — a select
 *     is a suggestion from the browser, not an authorisation.
 *
 * With AI enabled it also takes a sentence instead of a form. That does not run a hidden
 * parser here: the sentence is handed to the assistant panel, where the run, the tools it
 * called and what it changed are all on the record.
 */
final class QuickCreate extends Component
{
    public bool $open = false;

    public ?int $projectId = null;

    public string $title = '';

    public ?int $assigneeId = null;

    public ?int $statusId = null;

    public string $priority = Priority::Medium->value;

    public string $dueDate = '';

    /** 'form' or 'describe'. */
    public string $mode = 'form';

    public string $sentence = '';

    public ?string $error = null;

    /* ------------------------------------------------------------------ *
     * Opening
     * ------------------------------------------------------------------ */

    /**
     * The shell dispatches `open-quick-create` for several kinds of record; this component
     * answers for tasks and stays out of the way for the rest.
     */
    #[On('open-quick-create')]
    public function openQuickCreate(?string $type = null, ?int $project = null, ?int $status = null): void
    {
        if ($type !== null && $type !== 'task') {
            return;
        }

        $workspace = $this->workspace();

        if (! $workspace instanceof Workspace) {
            return;
        }

        $this->reset(['title', 'assigneeId', 'dueDate', 'sentence', 'error']);
        $this->priority = Priority::Medium->value;
        $this->mode = 'form';

        unset($this->projects, $this->statuses, $this->members);

        // Set before the option lists are read: they are gated on `open` so that a closed
        // quick-create costs the shell no queries at all.
        $this->open = true;

        $this->projectId = $this->resolveProjectId($project);

        unset($this->statuses);

        $this->statusId = $status ?? $this->defaultStatusId();
    }

    public function updatedOpen(bool $value): void
    {
        if (! $value) {
            $this->close();
        }
    }

    public function close(): void
    {
        $this->open = false;
        $this->error = null;
    }

    public function updatedProjectId(): void
    {
        unset($this->statuses);

        $this->statusId = $this->defaultStatusId();
    }

    /* ------------------------------------------------------------------ *
     * Options
     * ------------------------------------------------------------------ */

    /**
     * @return EloquentCollection<int, Project>
     */
    #[Computed]
    public function projects(): EloquentCollection
    {
        $workspace = $this->workspace();
        $user = auth()->user();

        if (! $this->open || ! $workspace instanceof Workspace || ! $user instanceof User) {
            return new EloquentCollection;
        }

        return Project::query()
            ->forWorkspace($workspace)
            ->where('is_archived', false)
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'key', 'color', 'workspace_id'])
            ->filter(static fn (Project $project): bool => $user->can('create', [Task::class, $project]))
            ->values();
    }

    /**
     * @return EloquentCollection<int, TaskStatus>
     */
    #[Computed]
    public function statuses(): EloquentCollection
    {
        $project = $this->project();

        if (! $project instanceof Project) {
            return new EloquentCollection;
        }

        return TaskStatus::query()->forProject($project)->ordered()->get();
    }

    /**
     * @return EloquentCollection<int, User>
     */
    #[Computed]
    public function members(): EloquentCollection
    {
        $workspace = $this->workspace();

        if (! $this->open || ! $workspace instanceof Workspace) {
            return new EloquentCollection;
        }

        return $workspace->members()
            ->where('users.is_active', true)
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.avatar_path']);
    }

    public function canUseAi(): bool
    {
        $workspace = $this->workspace();

        return $workspace instanceof Workspace && Gate::allows('ai.use', $workspace);
    }

    /* ------------------------------------------------------------------ *
     * Creating
     * ------------------------------------------------------------------ */

    public function create(bool $another = false): void
    {
        $this->error = null;

        $project = $this->project();
        $user = auth()->user();
        $title = trim($this->title);

        if (! $project instanceof Project || ! $user instanceof User) {
            $this->error = __('Choose a project first.');

            return;
        }

        if ($title === '') {
            $this->error = __('Give the task a title.');

            return;
        }

        // The select said which project; the Gate says whether that was allowed.
        $this->authorize('create', [Task::class, $project]);

        $status = $this->statusId === null
            ? null
            : TaskStatus::query()->forProject($project)->whereKey($this->statusId)->first();

        $assignee = $this->assigneeId === null ? null : $this->members->firstWhere('id', $this->assigneeId);

        if ($assignee instanceof User) {
            $this->authorize('assign', new Task([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->getKey(),
            ]));
        }

        $due = trim($this->dueDate) === '' ? null : $this->parseDate($this->dueDate);

        try {
            $task = app(CreateTask::class)(new CreateTaskData(
                project: $project,
                actor: $user,
                title: mb_substr($title, 0, 255),
                status: $status,
                priority: Priority::tryFrom($this->priority) ?? Priority::Medium,
                assignee: $assignee,
                dueDate: $due,
            ));
        } catch (DomainException $exception) {
            $this->error = $exception->getMessage();

            return;
        }

        $this->dispatch('task-created', taskId: (int) $task->getKey());
        $this->dispatch('planvio-notify', type: 'success', message: __(':key created', ['key' => $task->key]));

        $this->reset(['title', 'dueDate', 'error']);

        if (! $another) {
            $this->open = false;
        }
    }

    /**
     * Hand the sentence to the assistant instead of guessing at it here.
     */
    public function describe(): void
    {
        $sentence = trim($this->sentence);

        if ($sentence === '' || ! $this->canUseAi()) {
            return;
        }

        $project = $this->project();

        $prompt = $project instanceof Project
            ? __('In project :project, create a task: :sentence', ['project' => $project->name, 'sentence' => $sentence])
            : __('Create a task: :sentence', ['sentence' => $sentence]);

        $this->open = false;
        $this->sentence = '';

        $this->dispatch('open-ai-panel', prompt: $prompt);
    }

    public function render(): View
    {
        return view('livewire.app.tasks.quick-create');
    }

    /* ------------------------------------------------------------------ *
     * Plumbing
     * ------------------------------------------------------------------ */

    private function workspace(): ?Workspace
    {
        return app(CurrentWorkspace::class)->get();
    }

    private function project(): ?Project
    {
        if ($this->projectId === null) {
            return null;
        }

        return $this->projects->firstWhere('id', $this->projectId);
    }

    /**
     * The project asked for, then the one already on screen, then the only one there is.
     */
    private function resolveProjectId(?int $requested): ?int
    {
        $projects = $this->projects;

        if ($requested !== null && $projects->contains('id', $requested)) {
            return $requested;
        }

        if ($this->projectId !== null && $projects->contains('id', $this->projectId)) {
            return $this->projectId;
        }

        $current = request()->route('project');

        if ($current instanceof Project && $projects->contains('id', $current->getKey())) {
            return (int) $current->getKey();
        }

        $first = $projects->first();

        return $first instanceof Project ? (int) $first->getKey() : null;
    }

    private function defaultStatusId(): ?int
    {
        $statuses = $this->statuses;

        $default = $statuses->firstWhere('is_default', true)
            ?? $statuses->first(static fn (TaskStatus $status): bool => ! $status->is_completed)
            ?? $statuses->first();

        return $default instanceof TaskStatus ? (int) $default->getKey() : null;
    }

    private function parseDate(string $value): ?Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}

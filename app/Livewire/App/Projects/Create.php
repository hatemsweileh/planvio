<?php

declare(strict_types=1);

namespace App\Livewire\App\Projects;

use App\Actions\Members\AddProjectMember;
use App\Actions\Projects\CreateProject;
use App\Actions\Projects\CreateProjectFromTemplate;
use App\Actions\Projects\ProjectAttributes;
use App\Actions\Projects\ProjectKeyGenerator;
use App\Enums\ProjectRole;
use App\Enums\ProjectType;
use App\Enums\WorkspaceRole;
use App\Exceptions\DomainException;
use App\Exceptions\DuplicateProjectKey;
use App\Models\AiSetting;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Starting a project: pick where it comes from, then say what it is.
 *
 * Two steps on one page rather than a wizard across two URLs. The choice in step one changes
 * what step two is pre-filled with — a template carries a name, a type, a colour and an icon
 * — so the two belong to one form and one submission. Splitting them would mean either a
 * half-created project or a draft table, and neither is worth the seam.
 *
 * The key is derived from the name as it is typed, and stops being derived the moment
 * somebody edits it. A key ends up in commit messages and chat, so it is offered as a
 * suggestion and then left alone.
 */
#[Layout('layouts.app')]
final class Create extends Component
{
    public const SOURCE_BLANK = 'blank';

    public const SOURCE_TEMPLATE = 'template';

    /**
     * The palette offered for a project's colour.
     *
     * Written out as literal hexes rather than read from the Tailwind scale: these are
     * stored on the row and travel to reports, exports and the API, so they have to be real
     * values rather than tokens that only mean something inside this stylesheet.
     *
     * @var list<string>
     */
    public const COLORS = [
        '#3F66B0', '#5379C1', '#0EA5E9', '#0D9488', '#10B981', '#65A30D',
        '#F59E0B', '#F97316', '#EF4444', '#EC4899', '#A855F7', '#6E7581',
    ];

    /** @var list<string> */
    public const ICONS = [
        '📋', '🚀', '🌐', '💻', '📣', '🎨', '🏗️', '🎪', '🧑‍💼', '💰',
        '🔬', '📊', '🛠️', '🤝', '📦', '🎯',
    ];

    public Workspace $workspace;

    public int $step = 1;

    public string $source = self::SOURCE_BLANK;

    public ?int $templateId = null;

    public string $name = '';

    public string $key = '';

    public string $description = '';

    public string $type = 'general';

    public string $color = '#3F66B0';

    public string $icon = '';

    public string $startDate = '';

    public string $targetDate = '';

    public string $budget = '';

    public string $currency = 'USD';

    public ?int $managerId = null;

    /** @var list<int> */
    public array $memberIds = [];

    public string $memberSearch = '';

    /**
     * Whether the key has been edited by hand. Once true the name stops driving it.
     */
    public bool $keyIsCustom = false;

    /**
     * Memoised for the length of one request, so three validation rules share one query.
     *
     * @var list<int>|null
     */
    private ?array $assignableIds = null;

    public function mount(Workspace $workspace): void
    {
        $this->authorize('view', $workspace);
        $this->authorize('create', [Project::class, $workspace]);

        $this->workspace = $workspace;
        $this->currency = (string) ($workspace->currency ?: 'USD');
        $this->color = (string) config('planvio.brand.primary_color', '#3F66B0');
        $this->managerId = (int) auth()->id();
    }

    /* ------------------------------------------------------------------ *
     * Step one
     * ------------------------------------------------------------------ */

    public function startBlank(): void
    {
        $this->source = self::SOURCE_BLANK;
        $this->templateId = null;
        $this->step = 2;
    }

    /**
     * Adopt a template and carry its identity into the form.
     *
     * Only the fields the person has not touched are overwritten — someone who typed a name
     * and then browsed templates should keep their name.
     */
    public function startFromTemplate(int $templateId): void
    {
        $template = $this->templates->firstWhere('id', $templateId);

        if ($template === null) {
            $this->addError('templateId', __('That template is no longer available.'));

            return;
        }

        $this->source = self::SOURCE_TEMPLATE;
        $this->templateId = (int) $template->getKey();

        if (trim($this->name) === '') {
            $this->name = (string) $template->name;
            $this->deriveKey();
        }

        if (trim($this->description) === '') {
            $this->description = (string) ($template->description ?? '');
        }

        if ($template->type instanceof ProjectType) {
            $this->type = $template->type->value;
        }

        if (is_string($template->color) && $template->color !== '') {
            $this->color = $template->color;
        }

        if (is_string($template->icon) && $template->icon !== '') {
            $this->icon = $template->icon;
        }

        $this->step = 2;
    }

    public function backToStart(): void
    {
        $this->step = 1;
        $this->resetErrorBag();
    }

    /* ------------------------------------------------------------------ *
     * Step two
     * ------------------------------------------------------------------ */

    public function updatedName(): void
    {
        if (! $this->keyIsCustom) {
            $this->deriveKey();
        }
    }

    public function updatedKey(): void
    {
        $this->keyIsCustom = trim($this->key) !== '';
        $this->key = ProjectKeyGenerator::normalise($this->key);
    }

    public function resetKey(): void
    {
        $this->keyIsCustom = false;
        $this->deriveKey();
    }

    public function toggleMember(int $userId): void
    {
        if (in_array($userId, $this->memberIds, true)) {
            $this->memberIds = array_values(array_diff($this->memberIds, [$userId]));

            return;
        }

        $this->memberIds = [...$this->memberIds, $userId];
    }

    public function save(
        CreateProject $createProject,
        CreateProjectFromTemplate $createFromTemplate,
        AddProjectMember $addProjectMember,
    ): void {
        // Authorisation is the caller's job: Actions validate domain invariants, never
        // permissions (ARCHITECTURE.md §2).
        $this->authorize('create', [Project::class, $this->workspace]);

        $data = $this->validate();

        $actor = auth()->user();

        $attributes = new ProjectAttributes(
            name: $data['name'],
            key: $data['key'],
            description: $data['description'] === '' ? null : $data['description'],
            icon: $data['icon'] === '' ? null : $data['icon'],
            color: $data['color'],
            type: ProjectType::from($data['type']),
            managerId: $this->managerId,
            startDate: $data['startDate'] === '' ? null : $data['startDate'],
            targetDate: $data['targetDate'] === '' ? null : $data['targetDate'],
            budget: $data['budget'] === '' ? null : $data['budget'],
            currency: $data['currency'],
        );

        $template = $this->source === self::SOURCE_TEMPLATE && $this->templateId !== null
            ? $this->templates->firstWhere('id', $this->templateId)
            : null;

        try {
            $project = $template === null
                ? $createProject($this->workspace, $actor, $attributes)
                : $createFromTemplate($this->workspace, $actor, $template, $attributes);
        } catch (DomainException $exception) {
            // A broken invariant is a normal outcome of a form, not a fault: the message is
            // already written for the person who tripped it. A key collision belongs on the
            // key field — it is the one they can fix there — and everything else goes to the
            // banner, because pinning "that template is inactive" to an input would send
            // somebody editing the wrong thing.
            $this->addError(
                $exception instanceof DuplicateProjectKey ? 'key' : 'form',
                $exception->userMessage(),
            );

            return;
        }

        $this->attachMembers($addProjectMember, $project, $actor);

        // No toast on the way out: the project's own page opening is the confirmation, and a
        // dispatched event would not survive the navigation anyway.
        $this->redirectRoute('app.projects.show', [$this->workspace, $project]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            // Matches ProjectKeyGenerator::normalise(): upper-case ASCII, never leading with
            // a digit, never longer than the column. Uniqueness covers soft-deleted rows
            // because the index does — a restored project must not collide.
            'key' => [
                'required',
                'string',
                'max:'.ProjectKeyGenerator::MAX_LENGTH,
                'regex:/^[A-Z][A-Z0-9]*$/',
                Rule::unique('projects', 'key')->where(
                    fn ($query) => $query->where('workspace_id', $this->workspace->getKey()),
                ),
            ],
            'description' => ['nullable', 'string', 'max:20000'],
            'type' => ['required', Rule::enum(ProjectType::class)],
            'color' => ['required', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
            'icon' => ['nullable', 'string', 'max:16'],
            'startDate' => ['nullable', 'date'],
            'targetDate' => ['nullable', 'date', 'after_or_equal:startDate'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'managerId' => ['nullable', 'integer', Rule::in($this->assignableIds())],
            'memberIds' => ['array', 'max:200'],
            'memberIds.*' => ['integer', Rule::in($this->assignableIds())],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'name' => __('name'),
            'key' => __('key'),
            'startDate' => __('start date'),
            'targetDate' => __('target date'),
            'managerId' => __('project manager'),
        ];
    }

    /* ------------------------------------------------------------------ *
     * Reads
     * ------------------------------------------------------------------ */

    /**
     * The blueprints this workspace may start from: its own, plus the ones Planvio ships.
     *
     * @return EloquentCollection<int, ProjectTemplate>
     */
    #[Computed]
    public function templates(): EloquentCollection
    {
        if (! Gate::allows('viewAny', [ProjectTemplate::class, $this->workspace])) {
            return new EloquentCollection;
        }

        return ProjectTemplate::query()
            ->availableIn($this->workspace)
            ->active()
            // `is_active` and `is_system` come along because CreateProjectFromTemplate reads
            // them: a partially hydrated model would look deactivated to it.
            ->select([
                'id', 'workspace_id', 'name', 'slug', 'description', 'icon', 'color', 'type',
                'definition', 'is_system', 'is_active',
            ])
            ->orderByRaw('case when workspace_id is null then 1 else 0 end')
            ->orderBy('name')
            ->get();
    }

    /**
     * Everybody who can be given the project: workspace members, guests included, because a
     * guest's only route to a project is being added to it explicitly.
     *
     * @return EloquentCollection<int, User>
     */
    #[Computed]
    public function candidates(): EloquentCollection
    {
        $term = trim($this->memberSearch);

        return User::query()
            ->select(['users.id', 'users.name', 'users.email', 'users.avatar_path', 'workspace_members.role as workspace_role'])
            ->join('workspace_members', 'workspace_members.user_id', '=', 'users.id')
            ->where('workspace_members.workspace_id', $this->workspace->getKey())
            ->when($term !== '', function ($query) use ($term): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $query->where(function ($matches) use ($like): void {
                    $matches->where('users.name', 'like', $like)->orWhere('users.email', 'like', $like);
                });
            })
            ->orderBy('users.name')
            ->limit(100)
            ->get();
    }

    /**
     * The people who may hold the manager slot. A guest cannot: the role carries
     * `project.update` and the capability matrix gives a guest none of it.
     *
     * @return EloquentCollection<int, User>
     */
    #[Computed]
    public function managers(): EloquentCollection
    {
        return User::query()
            ->select(['users.id', 'users.name'])
            ->join('workspace_members', 'workspace_members.user_id', '=', 'users.id')
            ->where('workspace_members.workspace_id', $this->workspace->getKey())
            ->where('workspace_members.role', '!=', WorkspaceRole::Guest->value)
            ->orderBy('users.name')
            ->get();
    }

    #[Computed]
    public function selectedTemplate(): ?ProjectTemplate
    {
        if ($this->templateId === null) {
            return null;
        }

        return $this->templates->firstWhere('id', $this->templateId);
    }

    /**
     * What a template will actually build, counted from its own definition.
     *
     * @return array{statuses: int, milestones: int, tasks: int, tags: int, views: int}
     */
    #[Computed]
    public function templateContents(): array
    {
        $template = $this->selectedTemplate;

        if ($template === null) {
            return ['statuses' => 0, 'milestones' => 0, 'tasks' => 0, 'tags' => 0, 'views' => 0];
        }

        return [
            'statuses' => count($template->section('statuses')),
            'milestones' => count($template->section('milestones')),
            'tasks' => count($template->section('tasks')),
            'tags' => count($template->section('tags')),
            'views' => count($template->section('views')),
        ];
    }

    /**
     * Whether "Create with AI" is worth offering — see {@see Index::aiAvailable()}.
     */
    #[Computed]
    public function aiAvailable(): bool
    {
        return Gate::allows('ai.use', $this->workspace)
            && AiSetting::forWorkspace($this->workspace)?->isUsable() === true;
    }

    public function render(): View
    {
        return view('livewire.app.projects.create')->title(__('New project'));
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    private function deriveKey(): void
    {
        $this->key = trim($this->name) === ''
            ? ''
            : ProjectKeyGenerator::derive($this->name);
    }

    /**
     * The workspace's member ids, as the allow-list every person-shaped field validates
     * against. One query, reused by three rules in the same pass.
     *
     * @return list<int>
     */
    private function assignableIds(): array
    {
        return $this->assignableIds ??= $this->workspace->members()
            ->pluck('users.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Add the chosen people to the project.
     *
     * The creator is skipped: {@see CreateProject} already made them a manager, and running
     * them through here would demote them to member by way of their own selection.
     */
    private function attachMembers(AddProjectMember $addProjectMember, Project $project, User $actor): void
    {
        $ids = array_values(array_unique(array_diff($this->memberIds, [(int) $actor->getKey()])));

        if ($ids === [] || ! Gate::allows('manageMembers', $project)) {
            return;
        }

        foreach (User::query()->whereIn('id', $ids)->get() as $person) {
            $addProjectMember($project, $person, ProjectRole::Member, $actor);
        }
    }
}

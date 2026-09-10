<?php

declare(strict_types=1);

namespace App\Livewire\App\Onboarding;

use App\Actions\Members\InviteMember;
use App\Actions\Projects\CreateProject;
use App\Actions\Projects\CreateProjectFromTemplate;
use App\Actions\Projects\ProjectAttributes;
use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\CreateTaskData;
use App\Actions\Workspaces\UpdateWorkspace;
use App\Actions\Workspaces\WorkspaceAttributes;
use App\Enums\Permission;
use App\Enums\WorkspaceRole;
use App\Exceptions\DomainException;
use App\Models\AiSetting;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The first ten minutes.
 *
 * Not a wizard that blocks the product: the shell is drawn around it the whole way, every
 * step is skippable, and leaving is a link rather than an escape. Somebody who already knows
 * what they are doing can close it and never see it again; somebody who does not gets a
 * short path from an empty workspace to one with a project, some tasks and their colleagues
 * in it.
 *
 * Progress is derived from the workspace itself — a project exists, an invitation is out,
 * AI has been decided — rather than from a "step" counter. That means it stays honest when
 * somebody does half of it here and the other half from the real screens, and it means
 * reopening this page later shows what is actually left.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /** Where "we are done with onboarding" is remembered on the workspace. */
    private const SETTING_KEY = 'onboarding_dismissed_at';

    public Workspace $workspace;

    #[Url(except: '')]
    public string $step = '';

    /* ---------------------------------------------------------------- *
     * Step 1 — the workspace
     * ---------------------------------------------------------------- */

    public string $workspaceName = '';

    /* ---------------------------------------------------------------- *
     * Step 2 — people
     * ---------------------------------------------------------------- */

    /** @var array<int, string> */
    public array $inviteEmails = ['', '', ''];

    public string $inviteRole = 'member';

    /* ---------------------------------------------------------------- *
     * Step 3 — the first project
     * ---------------------------------------------------------------- */

    public string $projectName = '';

    public string $projectApproach = 'blank';

    public ?int $templateId = null;

    public string $aiBrief = '';

    /* ---------------------------------------------------------------- *
     * Step 4 — the first tasks
     * ---------------------------------------------------------------- */

    public string $taskTitles = '';

    public function mount(Workspace $workspace): void
    {
        $this->authorize('view', $workspace);

        $this->workspace = $workspace;
        $this->workspaceName = (string) $workspace->name;
        $this->projectName = __('First project');

        if ($this->step === '' || ! array_key_exists($this->step, $this->steps())) {
            $this->step = $this->firstIncompleteStep();
        }
    }

    /* ------------------------------------------------------------------ *
     * Progress
     * ------------------------------------------------------------------ */

    /**
     * The steps, each with whether it is already satisfied.
     *
     * @return array<string, array{title: string, summary: string, icon: string, done: bool, available: bool}>
     */
    #[Computed]
    public function steps(): array
    {
        return [
            'workspace' => [
                'title' => __('Name your workspace'),
                'summary' => __('What this space is called, and what it is for.'),
                'icon' => 'icon.home',
                'done' => $this->workspace->name !== '' && $this->workspace->name !== __('My workspace'),
                'available' => Gate::allows('update', $this->workspace),
            ],
            'people' => [
                'title' => __('Invite the people you work with'),
                'summary' => __('Planvio is worth more with somebody else in it.'),
                'icon' => 'icon.users',
                'done' => $this->memberCount > 1 || $this->pendingInvitations > 0,
                'available' => Gate::allows('create', [Invitation::class, $this->workspace]),
            ],
            'project' => [
                'title' => __('Create your first project'),
                'summary' => __('Start blank, from a template, or describe it and let AI build it.'),
                'icon' => 'icon.folder',
                'done' => $this->projectCount > 0,
                'available' => Gate::allows('project.create', $this->workspace),
            ],
            'tasks' => [
                'title' => __('Put some work in it'),
                'summary' => __('A project with no tasks tells you nothing.'),
                'icon' => 'icon.check-circle',
                'done' => $this->taskCount > 0,
                'available' => $this->firstProject !== null
                    && Gate::allows(Permission::TaskCreate->value, $this->workspace),
            ],
            'ai' => [
                'title' => __('Decide what AI may do'),
                'summary' => __('Answer only, propose and ask, or act on its own.'),
                'icon' => 'icon.sparkles',
                'done' => AiSetting::query()->where('workspace_id', $this->workspace->getKey())->exists(),
                'available' => Gate::allows('ai.manage', $this->workspace),
            ],
        ];
    }

    #[Computed]
    public function memberCount(): int
    {
        return $this->workspace->memberships()->count();
    }

    #[Computed]
    public function pendingInvitations(): int
    {
        return Invitation::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->pending()
            ->count();
    }

    #[Computed]
    public function projectCount(): int
    {
        return Project::query()->where('workspace_id', $this->workspace->getKey())->count();
    }

    #[Computed]
    public function taskCount(): int
    {
        return Task::query()->where('workspace_id', $this->workspace->getKey())->count();
    }

    #[Computed]
    public function firstProject(): ?Project
    {
        return Project::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->orderBy('id')
            ->first();
    }

    /**
     * @return Collection<int, ProjectTemplate>
     */
    #[Computed]
    public function templates(): Collection
    {
        return ProjectTemplate::query()
            ->availableIn($this->workspace)
            ->active()
            ->orderBy('name')
            ->limit(12)
            ->get(['id', 'name', 'description', 'icon', 'type', 'workspace_id']);
    }

    public function completedCount(): int
    {
        return count(array_filter($this->steps, static fn (array $step): bool => $step['done']));
    }

    public function totalCount(): int
    {
        return count($this->steps);
    }

    public function aiUsable(): bool
    {
        return (bool) config('ai.enabled')
            && Gate::allows('ai.use', $this->workspace)
            && AiSetting::forWorkspace($this->workspace)?->isUsable() === true;
    }

    public function goTo(string $step): void
    {
        if (array_key_exists($step, $this->steps)) {
            $this->step = $step;
            $this->resetValidation();
        }
    }

    public function skip(): void
    {
        $this->step = $this->nextStepAfter($this->step);
    }

    /**
     * Stop offering this. Recorded on the workspace rather than on the person: onboarding is
     * about the workspace being set up, and the second person to join should not be walked
     * through creating a first project that already exists.
     */
    public function finish(): void
    {
        $this->authorize('view', $this->workspace);

        $settings = is_array($this->workspace->settings) ? $this->workspace->settings : [];
        $settings[self::SETTING_KEY] = now()->toIso8601String();

        $this->workspace->settings = $settings;
        $this->workspace->save();

        $this->redirect(route('app.home', $this->workspace), navigate: true);
    }

    /* ------------------------------------------------------------------ *
     * Step actions
     * ------------------------------------------------------------------ */

    public function saveWorkspace(UpdateWorkspace $updateWorkspace): void
    {
        $this->authorize('update', $this->workspace);

        $data = $this->validate([
            'workspaceName' => ['required', 'string', 'min:2', 'max:80'],
        ], attributes: ['workspaceName' => __('workspace name')]);

        try {
            $this->workspace = $updateWorkspace(
                workspace: $this->workspace,
                attributes: new WorkspaceAttributes(name: $data['workspaceName']),
                actor: $this->actor(),
            );
        } catch (DomainException $failure) {
            $this->addError('workspaceName', $failure->userMessage());

            return;
        }

        unset($this->steps);

        $this->step = $this->nextStepAfter('workspace');
    }

    public function sendInvitations(InviteMember $inviteMember): void
    {
        $this->authorize('create', [Invitation::class, $this->workspace]);

        $this->validate([
            'inviteEmails' => ['array'],
            'inviteEmails.*' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'inviteRole' => ['required', 'string'],
        ], attributes: ['inviteEmails.*' => __('email address')]);

        $role = WorkspaceRole::tryFrom($this->inviteRole) ?? WorkspaceRole::Member;

        if ($role === WorkspaceRole::Owner) {
            $role = WorkspaceRole::Member;
        }

        $addresses = array_values(array_filter(array_map(
            static fn (mixed $email): string => trim((string) $email),
            $this->inviteEmails,
        )));

        $sent = 0;

        foreach ($addresses as $index => $address) {
            try {
                $inviteMember(
                    workspace: $this->workspace,
                    inviter: $this->actor(),
                    email: $address,
                    role: $role,
                );

                $sent++;
            } catch (DomainException $failure) {
                $this->addError('inviteEmails.'.$index, $failure->userMessage());
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $this->inviteEmails = ['', '', ''];

        unset($this->steps, $this->pendingInvitations);

        if ($sent > 0) {
            $this->dispatch('planvio-notify', type: 'success', message: trans_choice(
                '{1} :count invitation sent.|[2,*] :count invitations sent.',
                $sent,
                ['count' => $sent],
            ));
        }

        $this->step = $this->nextStepAfter('people');
    }

    public function createProject(
        CreateProject $createProject,
        CreateProjectFromTemplate $createFromTemplate,
    ): void {
        $this->authorize('project.create', $this->workspace);

        $data = $this->validate([
            'projectName' => ['required', 'string', 'min:2', 'max:120'],
            'projectApproach' => ['required', 'in:blank,template,ai'],
            'templateId' => ['nullable', 'integer'],
            'aiBrief' => ['nullable', 'string', 'max:2000'],
        ], attributes: ['projectName' => __('project name')]);

        // The AI route does not create anything here. It hands the brief to the assistant,
        // where the plan is shown before a single row is written — the same path as anywhere
        // else in the product, because a project built without review is a project nobody
        // trusts.
        if ($data['projectApproach'] === 'ai') {
            if (trim((string) $data['aiBrief']) === '') {
                $this->addError('aiBrief', __('Describe the project in a sentence or two.'));

                return;
            }

            $this->dispatch('open-ai-panel', prompt: __('Create a project called “:name”. :brief', [
                'name' => $data['projectName'],
                'brief' => trim((string) $data['aiBrief']),
            ]));

            return;
        }

        $attributes = new ProjectAttributes(name: $data['projectName']);

        try {
            if ($data['projectApproach'] === 'template') {
                $template = $data['templateId'] === null
                    ? null
                    : ProjectTemplate::query()
                        ->availableIn($this->workspace)
                        ->active()
                        ->whereKey((int) $data['templateId'])
                        ->first();

                if (! $template instanceof ProjectTemplate) {
                    $this->addError('templateId', __('Choose a template to start from.'));

                    return;
                }

                $this->authorize('apply', $template);

                $project = $createFromTemplate(
                    workspace: $this->workspace,
                    owner: $this->actor(),
                    template: $template,
                    attributes: $attributes,
                );
            } else {
                $project = $createProject(
                    workspace: $this->workspace,
                    owner: $this->actor(),
                    attributes: $attributes,
                );
            }
        } catch (DomainException $failure) {
            $this->addError('projectName', $failure->userMessage());

            return;
        }

        unset($this->steps, $this->projectCount, $this->firstProject, $this->taskCount);

        $this->dispatch('planvio-notify', type: 'success', message: __('“:project” is ready.', [
            'project' => $project->name,
        ]));

        $this->step = $this->nextStepAfter('project');
    }

    public function createTasks(CreateTask $createTask): void
    {
        $project = $this->firstProject;

        if (! $project instanceof Project) {
            $this->step = 'project';

            return;
        }

        $this->authorize('create', [Task::class, $project]);

        $this->validate([
            'taskTitles' => ['required', 'string', 'max:2000'],
        ], attributes: ['taskTitles' => __('tasks')]);

        $lines = array_values(array_filter(
            array_map(static fn (string $line): string => trim($line), preg_split('/\R/', $this->taskTitles) ?: []),
            static fn (string $line): bool => $line !== '',
        ));

        if ($lines === []) {
            $this->addError('taskTitles', __('Write at least one task, one per line.'));

            return;
        }

        $created = 0;

        foreach (array_slice($lines, 0, 25) as $title) {
            try {
                $createTask(new CreateTaskData(
                    project: $project,
                    actor: $this->actor(),
                    title: mb_substr($title, 0, 255),
                ));

                $created++;
            } catch (DomainException $failure) {
                $this->addError('taskTitles', $failure->userMessage());

                break;
            }
        }

        if ($created === 0) {
            return;
        }

        $this->taskTitles = '';

        unset($this->steps, $this->taskCount);

        $this->dispatch('planvio-notify', type: 'success', message: trans_choice(
            '{1} :count task added.|[2,*] :count tasks added.',
            $created,
            ['count' => $created],
        ));

        $this->step = $this->nextStepAfter('tasks');
    }

    public function render(): View
    {
        return view('livewire.app.onboarding.index')->title(__('Welcome to Planvio'));
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    private function firstIncompleteStep(): string
    {
        foreach ($this->steps as $key => $step) {
            if (! $step['done'] && $step['available']) {
                return $key;
            }
        }

        return (string) array_key_first($this->steps);
    }

    private function nextStepAfter(string $current): string
    {
        $keys = array_keys($this->steps);
        $position = array_search($current, $keys, true);

        if ($position === false) {
            return $this->firstIncompleteStep();
        }

        for ($index = $position + 1; $index < count($keys); $index++) {
            if ($this->steps[$keys[$index]]['available']) {
                return $keys[$index];
            }
        }

        return $current;
    }

    private function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}

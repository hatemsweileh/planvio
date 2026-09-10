<?php

declare(strict_types=1);

namespace App\Ai\Agent;

use App\Ai\Policy\ResolvedPolicy;
use App\Enums\AiMode;
use App\Enums\Permission;
use App\Enums\WorkspaceRole;
use App\Exceptions\WorkspaceMismatch;
use App\Models\AiConversation;
use App\Models\AiPolicy;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\AiToolRun;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WikiPage;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * The acting identity, carried unchanged through an entire run (ARCHITECTURE.md §7.3).
 *
 * ## The whole point
 *
 * The AI has no authority of its own. There is no service account, no system user and no
 * elevated branch anywhere in this class. `can()` is the single place the AI layer asks
 * whether something is permitted, and it asks `Gate::forUser($this->user)` — the same call
 * the product UI makes for that person's own click. If the answer is no, the tool returns a
 * denial and the model reports it; there is no second path that tries again with more
 * privilege, because none is written (AI_SECURITY.md, "The single most important property").
 *
 * ## Why the Gate call is wrapped in the workspace binding
 *
 * Several policies fall back to `CurrentWorkspace` for class-level checks that carry no
 * record ("may this user create tasks at all?"). Leaving that binding to whoever happened to
 * call us would make an authorization answer depend on ambient state, so every check here
 * runs inside `CurrentWorkspace::runFor($this->workspace, …)` and restores the previous
 * binding afterwards. The binding narrows, never widens: policies still re-resolve the
 * user's membership from the record's own `workspace_id` (§3), and a bound workspace cannot
 * make a non-member into one.
 *
 * ## Permissions are not Gate abilities
 *
 * `Permission` is the capability matrix's vocabulary (`task.update`); Gate speaks in policy
 * method names (`TaskPolicy::update`). {@see self::ABILITIES} is the fixed translation
 * between them, and it is fixed on purpose — the same reason tool names resolve through a
 * registry. A permission the table does not know returns false; a subject of a type the rule
 * does not accept returns false. Both fail closed.
 */
final readonly class AgentContext
{
    /**
     * How each {@see Permission} is asked of the Gate.
     *
     * Every rule names the policy that answers and the ability to call on it. Up to four
     * forms exist per permission, tried in this order:
     *
     *   record     a concrete record of `subjectType` — the passed subject, or the ambient
     *              workspace/project/task of that type
     *   project    the focused project, for permissions whose class-level check is scoped
     *              by project ("may this user create a task in here?")
     *   workspace  the bound workspace, for checks a workspace policy answers
     *   any        class-level, no argument; the policy resolves the bound workspace itself
     *
     * A rule with no applicable form denies. Some permissions have no meaningful
     * subject-less form at all — you cannot ask whether the AI may archive *nothing* — and
     * those deliberately end at false.
     *
     * @var array<string, array{
     *     record?: array{0: class-string<Model>, 1: class-string, 2: string},
     *     project?: array{0: class-string<Model>, 1: string},
     *     workspace?: array{0: class-string<Model>, 1: string},
     *     any?: array{0: class-string<Model>, 1: string}
     * }>
     */
    private const ABILITIES = [
        Permission::WorkspaceView->value => [
            'record' => [Workspace::class, Workspace::class, 'view'],
            'any' => [Workspace::class, 'viewAny'],
        ],
        Permission::WorkspaceManage->value => [
            'record' => [Workspace::class, Workspace::class, 'update'],
        ],
        Permission::WorkspaceDelete->value => [
            'record' => [Workspace::class, Workspace::class, 'delete'],
        ],

        Permission::ProjectView->value => [
            'record' => [Project::class, Project::class, 'view'],
            'any' => [Project::class, 'viewAny'],
        ],
        Permission::ProjectCreate->value => [
            'project' => [Project::class, 'create'],
            'workspace' => [Project::class, 'create'],
            'any' => [Project::class, 'create'],
        ],
        Permission::ProjectUpdate->value => [
            'record' => [Project::class, Project::class, 'update'],
        ],
        Permission::ProjectDelete->value => [
            'record' => [Project::class, Project::class, 'delete'],
        ],
        Permission::ProjectArchive->value => [
            'record' => [Project::class, Project::class, 'archive'],
        ],
        Permission::ProjectManageMembers->value => [
            'record' => [Project::class, Project::class, 'manageMembers'],
        ],

        Permission::TaskView->value => [
            'record' => [Task::class, Task::class, 'view'],
            'project' => [Task::class, 'viewAny'],
            'any' => [Task::class, 'viewAny'],
        ],
        Permission::TaskCreate->value => [
            'project' => [Task::class, 'create'],
            'any' => [Task::class, 'create'],
        ],
        Permission::TaskUpdate->value => [
            'record' => [Task::class, Task::class, 'update'],
        ],
        Permission::TaskDelete->value => [
            'record' => [Task::class, Task::class, 'delete'],
        ],
        Permission::TaskAssign->value => [
            'record' => [Task::class, Task::class, 'assign'],
        ],
        // Commenting is authorised against the thing being commented on, which may be a
        // task, a project or a milestone — CommentPolicy::create() takes any of them.
        Permission::TaskComment->value => [
            'record' => [Comment::class, Model::class, 'create'],
            'any' => [Comment::class, 'create'],
        ],

        Permission::MilestoneView->value => [
            'record' => [Milestone::class, Milestone::class, 'view'],
            'project' => [Milestone::class, 'viewAny'],
            'any' => [Milestone::class, 'viewAny'],
        ],
        Permission::MilestoneManage->value => [
            'record' => [Milestone::class, Milestone::class, 'update'],
            'project' => [Milestone::class, 'create'],
            'any' => [Milestone::class, 'create'],
        ],

        Permission::TimeLog->value => [
            'record' => [Task::class, Task::class, 'logTime'],
            'project' => [TimeEntry::class, 'create'],
            'any' => [TimeEntry::class, 'create'],
        ],
        Permission::TimeViewAll->value => [
            'record' => [Project::class, Project::class, 'viewAllTime'],
            'any' => [TimeEntry::class, 'viewAll'],
        ],

        Permission::BudgetView->value => [
            'record' => [Project::class, Project::class, 'viewBudget'],
        ],
        Permission::BudgetManage->value => [
            'record' => [Project::class, Project::class, 'manageBudget'],
            'workspace' => [Workspace::class, 'manageBilling'],
        ],

        Permission::WikiView->value => [
            'record' => [WikiPage::class, WikiPage::class, 'view'],
            'project' => [WikiPage::class, 'viewAny'],
            'any' => [WikiPage::class, 'viewAny'],
        ],
        Permission::WikiManage->value => [
            'record' => [WikiPage::class, WikiPage::class, 'update'],
            'project' => [WikiPage::class, 'create'],
            'any' => [WikiPage::class, 'create'],
        ],

        Permission::AttachmentUpload->value => [
            'record' => [Attachment::class, Model::class, 'create'],
            'any' => [Attachment::class, 'create'],
        ],
        Permission::AttachmentDelete->value => [
            'record' => [Attachment::class, Attachment::class, 'delete'],
        ],

        Permission::ReportsView->value => [
            'record' => [Project::class, Project::class, 'viewReports'],
            'workspace' => [Workspace::class, 'viewReports'],
        ],

        Permission::SettingsManage->value => [
            'record' => [Workspace::class, Workspace::class, 'manageSettings'],
        ],
        Permission::UsersManage->value => [
            'record' => [WorkspaceMember::class, WorkspaceMember::class, 'update'],
            'workspace' => [Workspace::class, 'manageMembers'],
        ],
        Permission::WebhooksManage->value => [
            'record' => [Webhook::class, Webhook::class, 'update'],
            'workspace' => [Webhook::class, 'create'],
            'any' => [Webhook::class, 'create'],
        ],
        Permission::TemplatesManage->value => [
            'record' => [ProjectTemplate::class, ProjectTemplate::class, 'update'],
            'workspace' => [ProjectTemplate::class, 'create'],
            'any' => [ProjectTemplate::class, 'create'],
        ],

        Permission::AiUse->value => [
            'record' => [Project::class, Project::class, 'useAi'],
            'any' => [AiRun::class, 'create'],
        ],
        Permission::AiManage->value => [
            'record' => [AiSetting::class, AiSetting::class, 'update'],
            'workspace' => [AiSetting::class, 'viewAny'],
            'any' => [AiSetting::class, 'viewAny'],
        ],
        Permission::AiAutonomous->value => [
            'project' => [AiRun::class, 'runAutonomously'],
            'any' => [AiRun::class, 'runAutonomously'],
        ],
        Permission::AiManagePolicies->value => [
            'record' => [AiPolicy::class, AiPolicy::class, 'update'],
            'workspace' => [AiPolicy::class, 'viewAny'],
            'any' => [AiPolicy::class, 'viewAny'],
        ],
        Permission::AiViewLogs->value => [
            'record' => [AiRun::class, AiRun::class, 'view'],
            'project' => [AiRun::class, 'viewAny'],
            'any' => [AiRun::class, 'viewAny'],
        ],
        Permission::AiApprove->value => [
            'record' => [AiToolRun::class, AiToolRun::class, 'approve'],
        ],
    ];

    public function __construct(
        public User $user,
        public Workspace $workspace,
        public ?Project $project,
        public ?Task $task,
        public ?AiConversation $conversation,
        public AiRun $run,
        public AiMode $mode,
        public ResolvedPolicy $policy,
        public RunLimits $limits,
        public string $timezone,
    ) {}

    /* ------------------------------------------------------------------ *
     * Authorization — the only door
     * ------------------------------------------------------------------ */

    /**
     * Whether the acting user holds $permission, optionally over a specific record.
     *
     * Always `Gate::forUser($this->user)`. Never the authenticated request user, never a
     * service account, never a mode that skips the check.
     */
    public function can(Permission $permission, ?Model $subject = null): bool
    {
        $rule = self::ABILITIES[$permission->value] ?? null;

        if ($rule === null) {
            return false;
        }

        return $this->bindWorkspace(function () use ($rule, $subject): bool {
            if ($subject !== null) {
                return $this->checkAgainstSubject($rule, $subject);
            }

            return $this->checkAgainstContext($rule);
        });
    }

    public function cannot(Permission $permission, ?Model $subject = null): bool
    {
        return ! $this->can($permission, $subject);
    }

    /**
     * The policy-ability form, for checks the capability matrix has no single name for —
     * `changeStatus` on a task, `view` on a conversation, `approve` on a tool run.
     *
     * Still `Gate::forUser($this->user)` inside the workspace binding: this is a different
     * vocabulary, not a different authority, and it cannot reach anything `can()` cannot.
     *
     * @param Model|class-string<Model>|array<int, mixed> $arguments
     */
    public function allows(string $ability, Model|string|array $arguments = []): bool
    {
        return $this->bindWorkspace(
            fn (): bool => Gate::forUser($this->user)->allows($ability, $arguments),
        );
    }

    /* ------------------------------------------------------------------ *
     * Tenancy
     * ------------------------------------------------------------------ */

    /**
     * Refuse to touch a record that belongs to another workspace.
     *
     * Every mutating tool calls this before it reaches an Action. It is deliberately strict:
     * a model with no `workspace_id` of its own — a checklist item, a project membership, an
     * AI message — fails here rather than passing by default, so the caller has to assert on
     * the parent that does carry the tenant column.
     *
     * @throws WorkspaceMismatch
     */
    public function assertInWorkspace(Model $model): void
    {
        if ($this->isInWorkspace($model)) {
            return;
        }

        throw WorkspaceMismatch::between(
            $model->getMorphClass(),
            self::workspaceIdOf($model) ?? 0,
            $this->workspace->getMorphClass(),
            $this->workspaceId(),
        );
    }

    public function isInWorkspace(Model $model): bool
    {
        return self::workspaceIdOf($model) === $this->workspaceId();
    }

    /**
     * Bind this run's workspace for the duration of $callback and restore whatever was bound
     * before. Every query and Gate check in the AI layer belongs inside one of these.
     *
     * @template TReturn
     *
     * @param Closure(): TReturn $callback
     * @return TReturn
     */
    public function bindWorkspace(Closure $callback): mixed
    {
        return Container::getInstance()
            ->make(CurrentWorkspace::class)
            ->runFor($this->workspace, static fn (): mixed => $callback());
    }

    /* ------------------------------------------------------------------ *
     * Narrowing
     * ------------------------------------------------------------------ */

    /**
     * A copy focused on $project.
     *
     * The project must already be in this workspace — narrowing is not a way to move between
     * tenants. A focused task that does not belong to the new project is dropped rather than
     * carried into a context it no longer describes.
     *
     * @throws WorkspaceMismatch
     */
    public function forProject(?Project $project): static
    {
        if ($project !== null) {
            $this->assertInWorkspace($project);
        }

        if (self::sameKey($project, $this->project)) {
            return $this;
        }

        $task = $this->task;

        if ($task !== null && (int) $task->project_id !== (int) ($project?->getKey() ?? 0)) {
            $task = null;
        }

        return new self(
            user: $this->user,
            workspace: $this->workspace,
            project: $project,
            task: $task,
            conversation: $this->conversation,
            run: $this->run,
            mode: $this->mode,
            policy: $this->policy,
            limits: $this->limits,
            timezone: $this->timezone,
        );
    }

    /* ------------------------------------------------------------------ *
     * Time
     * ------------------------------------------------------------------ */

    /**
     * Now, in the workspace timezone. Relative dates the model resolves — "Friday", "end of
     * month" — are anchored here, never to UTC and never to the server's zone.
     */
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->resolvedTimezone());
    }

    public function today(): CarbonImmutable
    {
        return $this->now()->startOfDay();
    }

    /**
     * The timezone actually used, which is the stored one unless it is not a real identifier.
     *
     * `workspaces.timezone` is a free-text column. A value PHP cannot resolve would throw
     * from inside `now()` and take down a run over a settings typo, so it degrades to UTC.
     * It also means anything derived from the resolved zone is a validated identifier rather
     * than arbitrary stored text.
     */
    public function resolvedTimezone(): string
    {
        try {
            CarbonImmutable::now($this->timezone);

            return $this->timezone;
        } catch (Throwable) {
            return 'UTC';
        }
    }

    /* ------------------------------------------------------------------ *
     * Identity
     * ------------------------------------------------------------------ */

    public function userId(): int
    {
        return (int) $this->user->getKey();
    }

    public function workspaceId(): int
    {
        return (int) $this->workspace->getKey();
    }

    public function projectId(): ?int
    {
        $key = $this->project?->getKey();

        return $key === null ? null : (int) $key;
    }

    public function taskId(): ?int
    {
        $key = $this->task?->getKey();

        return $key === null ? null : (int) $key;
    }

    public function conversationId(): ?int
    {
        $key = $this->conversation?->getKey();

        return $key === null ? null : (int) $key;
    }

    public function runId(): int
    {
        return (int) $this->run->getKey();
    }

    public function runUuid(): string
    {
        return (string) $this->run->uuid;
    }

    /**
     * The acting user's role in this workspace, or null when they are not a member — which
     * is itself an answer: a run for a non-member can read nothing.
     */
    public function workspaceRole(): ?WorkspaceRole
    {
        return $this->user->roleIn($this->workspace);
    }

    /**
     * Ids only. No models, no prompt bodies, no credentials — this goes to logs
     * (AI_SECURITY.md, "What is logged, and what is never logged").
     *
     * @return array{
     *     run_id: int,
     *     run_uuid: string,
     *     user_id: int,
     *     workspace_id: int,
     *     project_id: int|null,
     *     task_id: int|null,
     *     conversation_id: int|null,
     *     mode: string,
     *     role: string|null,
     *     timezone: string,
     *     limits: array<string, int>,
     *     policy: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId(),
            'run_uuid' => $this->runUuid(),
            'user_id' => $this->userId(),
            'workspace_id' => $this->workspaceId(),
            'project_id' => $this->projectId(),
            'task_id' => $this->taskId(),
            'conversation_id' => $this->conversationId(),
            'mode' => $this->mode->value,
            'role' => $this->workspaceRole()?->value,
            'timezone' => $this->resolvedTimezone(),
            'limits' => $this->limits->toArray(),
            'policy' => $this->policy->toArray(),
        ];
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    /**
     * @param array<string, array{0: class-string<Model>, 1: class-string|string, 2?: string}> $rule
     */
    private function checkAgainstSubject(array $rule, Model $subject): bool
    {
        $record = $rule['record'] ?? null;

        if ($record !== null && $subject instanceof $record[1]) {
            return $this->ask($record[0], $record[2], $subject);
        }

        if ($subject instanceof Project && isset($rule['project'])) {
            return $this->ask($rule['project'][0], $rule['project'][1], $subject);
        }

        if ($subject instanceof Workspace && isset($rule['workspace'])) {
            return $this->ask($rule['workspace'][0], $rule['workspace'][1], $subject);
        }

        // A subject the rule does not accept is a caller mistake, and answering it against
        // some other record would be worse than refusing.
        return false;
    }

    /**
     * @param array<string, array{0: class-string<Model>, 1: class-string|string, 2?: string}> $rule
     */
    private function checkAgainstContext(array $rule): bool
    {
        $record = $rule['record'] ?? null;

        if ($record !== null) {
            $ambient = $this->ambientFor($record[1]);

            if ($ambient !== null) {
                return $this->ask($record[0], $record[2], $ambient);
            }
        }

        if ($this->project !== null && isset($rule['project'])) {
            return $this->ask($rule['project'][0], $rule['project'][1], $this->project);
        }

        if (isset($rule['workspace'])) {
            return $this->ask($rule['workspace'][0], $rule['workspace'][1], $this->workspace);
        }

        if (isset($rule['any'])) {
            return $this->ask($rule['any'][0], $rule['any'][1], null);
        }

        return false;
    }

    /**
     * The record of $subjectType this context is focused on, if any.
     */
    private function ambientFor(string $subjectType): ?Model
    {
        return match ($subjectType) {
            Workspace::class => $this->workspace,
            Project::class => $this->project,
            Task::class => $this->task,
            // A rule that accepts any model wants the most specific thing in focus.
            Model::class => $this->task ?? $this->project,
            default => null,
        };
    }

    /**
     * One Gate call.
     *
     * Passing `[$policyOn, $subject]` rather than `$subject` is what pins the policy: Laravel
     * resolves the policy from the first argument and drops it before invoking the method, so
     * `TaskPolicy::create($user, $project)` and `TaskPolicy::view($user, $task)` are both
     * reachable without the subject's own class deciding which policy answers.
     *
     * @param class-string<Model> $policyOn
     */
    private function ask(string $policyOn, string $ability, ?Model $subject): bool
    {
        $arguments = $subject === null ? [$policyOn] : [$policyOn, $subject];

        return Gate::forUser($this->user)->allows($ability, $arguments);
    }

    private static function workspaceIdOf(Model $model): ?int
    {
        if ($model instanceof Workspace) {
            $key = $model->getKey();

            return is_numeric($key) ? (int) $key : null;
        }

        $workspaceId = $model->getAttribute('workspace_id');

        if (is_int($workspaceId)) {
            return $workspaceId;
        }

        return is_string($workspaceId) && ctype_digit($workspaceId) ? (int) $workspaceId : null;
    }

    private static function sameKey(?Model $a, ?Model $b): bool
    {
        if ($a === null || $b === null) {
            return $a === null && $b === null;
        }

        return (int) $a->getKey() === (int) $b->getKey();
    }
}

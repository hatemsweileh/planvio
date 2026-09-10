<?php

declare(strict_types=1);

namespace App\Livewire\App\Ai\Concerns;

use App\Ai\AiGate;
use App\Ai\Automations\StartsAgentRuns;
use App\Ai\Policy\PolicyResolver;
use App\Ai\Policy\ResolvedPolicy;
use App\Enums\AiMessageRole;
use App\Enums\AiMode;
use App\Enums\AiRunStatus;
use App\Enums\AiScope;
use App\Enums\AiTrigger;
use App\Livewire\App\Ai\Support\ConversationTimeline;
use App\Livewire\App\Ai\Support\RunProgress;
use App\Livewire\App\Ai\Support\TimelineEntry;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiRun;
use App\Models\AiToolRun;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Support\RateLimits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;

/**
 * The chat machinery, shared by the AI workspace, the shell's drawer and the project tab.
 *
 * All three surfaces do exactly the same thing when somebody presses Enter, and doing it
 * three times would guarantee that one of them eventually skipped the gate. So the sequence
 * lives here, once, in the order AI_SECURITY.md fixes:
 *
 * ```
 * authorize ai.use  ->  AiGate  ->  resolve the policy  ->  conversation  ->  user message
 *                   ->  ai_runs row (queued)  ->  StartsAgentRuns  ->  poll
 * ```
 *
 * ## Why the gate comes before anything is written
 *
 * A refused request must leave no trace of itself. If the conversation were created first
 * and the gate consulted after, a workspace with AI switched off would accumulate empty
 * threads and half-answered questions — and the person would be shown a conversation that
 * cannot ever be continued. {@see AiGate::refusal()} is asked first and its sentence is what
 * the surface renders; nothing is inserted on that path.
 *
 * ## Why nothing runs inline
 *
 * The agent loop is queued work. Planvio targets hosting with no persistent worker
 * (docs/QUEUE.md), so the request records an `ai_runs` row, hands it to the bound
 * {@see StartsAgentRuns} and returns; the surface then polls, backing off as the run ages
 * ({@see RunProgress}). On a development box where the AI queue connection is `sync` the run
 * has already finished by the time `start()` returns — which is why the poll state is
 * re-read from the row rather than assumed, and why the status line renders identically in
 * both worlds.
 *
 * ## What the using component must provide
 *
 * `protected function actor(): User` — the signed-in person. It is not declared abstract
 * here because {@see DecidesApprovals} needs the same method and two traits cannot both
 * declare it.
 */
trait ConductsConversation
{
    /** `ai_runs.objective` is a text column; this is a chat box, not an import. */
    private const MAX_PROMPT = 4000;

    /** `ai_conversations.title` is a varchar; a thread is named after the question that opened it. */
    private const MAX_TITLE = 80;

    /** The rail is a shortlist of recent threads, not an archive browser. */
    private const MAX_CONVERSATIONS = 60;

    /** The scope selector is a menu; a workspace with 400 projects gets a searchable list, not this. */
    private const MAX_SCOPE_OPTIONS = 100;

    /**
     * The tenant every question is asked inside.
     *
     * Nullable because the drawer is mounted by the app shell on every page, including the
     * handful a signed-in person can reach before they belong to a workspace. With none
     * bound the drawer draws nothing and answers nothing rather than half-existing.
     */
    public ?Workspace $workspace = null;

    /** The thread on screen. Null means the next message opens a new one. */
    public ?int $conversationId = null;

    public string $draft = '';

    /** The run being watched. Cleared by {@see self::tick()} once it reaches a terminal status. */
    public ?int $activeRunId = null;

    /** The project this conversation is scoped to, chosen in the composer or inherited from the page. */
    public ?int $projectScope = null;

    /** Set only when the drawer was opened from a task; never chosen by hand. */
    public ?int $taskScope = null;

    public string $search = '';

    /** Why the last attempt to send was refused, in Planvio's own words. */
    public ?string $refusal = null;

    /* ------------------------------------------------------------------ *
     * Sending
     * ------------------------------------------------------------------ */

    /**
     * Start a run for whatever is in the composer.
     */
    public function send(): void
    {
        if (! $this->workspace instanceof Workspace) {
            return;
        }

        $this->authorize('ai.use', $this->workspace);

        $prompt = $this->cleanPrompt($this->draft);

        if ($prompt === '') {
            return;
        }

        $this->ask($prompt);
    }

    /**
     * Start a run for a prepared objective — the task actions, the project tab, the
     * dashboard's "review my workspace". Identical in every other respect to typing it.
     */
    public function ask(string $prompt): void
    {
        if (! $this->workspace instanceof Workspace) {
            return;
        }

        $this->authorize('ai.use', $this->workspace);

        $prompt = $this->cleanPrompt($prompt);

        if ($prompt === '') {
            return;
        }

        $actor = $this->actor();

        // The outermost question, asked before a single row is written: master switch,
        // suspension, workspace setting, kill switch, provider, permission, budget.
        $refusal = app(AiGate::class)->refusal($this->workspace, $actor);

        if ($refusal !== null) {
            $this->refusal = $refusal;

            return;
        }

        $project = $this->scopedProject();
        $task = $this->scopedTask();

        // Re-authorised on every send rather than trusted from the composer: a page left
        // open across a membership change must not be able to aim a run at a project the
        // person can no longer use the assistant in.
        if ($project instanceof Project) {
            $this->authorize('useAi', $project);
        }

        if ($task instanceof Task) {
            $this->authorize('view', $task);
        }

        $policy = app(PolicyResolver::class)->resolve($this->workspace, $project, $actor);

        if (! $policy->canStartRun()) {
            $this->refusal = $policy->reason();

            return;
        }

        /*
         | The burst gate, charged last so that every cheaper refusal above is answered
         | with its own reason rather than with a throttle notice.
         |
         | AiGate has already applied the provider budget — runs per user per hour, runs
         | per workspace per day. This is a different question: those caps are counted in
         | `ai_runs` and are about money, and by the time an hour's worth has been spent
         | in ten seconds by a double-clicked button or a retrying tab the jobs are already
         | queued. Sending arrives on Livewire's shared update endpoint, which no route
         | limiter can distinguish from any other component action, so the charge is made
         | here against the same ceiling `POST /api/v1/ai/runs` is held to.
         */
        $bucket = 'user:'.$actor->getKey();

        if (! RateLimits::attempt(RateLimits::AI_RUNS, $bucket)) {
            $this->refusal = trans_choice(
                '{1}That is a lot of requests at once. Try again in a second.'
                .'|[2,*]That is a lot of requests at once. Try again in :count seconds.',
                RateLimits::availableIn(RateLimits::AI_RUNS, $bucket),
            );

            return;
        }

        $conversation = $this->conversationFor($project, $task, $prompt, $policy->mode);

        $run = DB::transaction(function () use ($conversation, $project, $actor, $prompt, $policy): AiRun {
            $run = AiRun::query()->create([
                'workspace_id' => $this->workspace->getKey(),
                'project_id' => $project?->getKey(),
                'ai_conversation_id' => $conversation->getKey(),
                'user_id' => $actor->getKey(),
                'trigger' => AiTrigger::Chat,
                'mode' => $policy->mode,
                'objective' => $prompt,
                'status' => AiRunStatus::Queued,
            ]);

            AiMessage::query()->create([
                'ai_conversation_id' => $conversation->getKey(),
                'role' => AiMessageRole::User,
                'content' => $prompt,
                'ai_run_id' => $run->getKey(),
            ]);

            $conversation->forceFill([
                'message_count' => (int) $conversation->message_count + 1,
                'last_activity_at' => Carbon::now(),
                'mode' => $policy->mode,
            ])->save();

            return $run;
        });

        // Deferred to after commit by the dispatcher itself, so a worker can never read the
        // run before the transaction that created it landed.
        app(StartsAgentRuns::class)->start($run);

        $this->draft = '';
        $this->refusal = null;
        $this->conversationId = (int) $conversation->getKey();
        $this->activeRunId = (int) $run->getKey();

        $this->forgetConversationState();
    }

    /* ------------------------------------------------------------------ *
     * Navigating
     * ------------------------------------------------------------------ */

    public function startNewConversation(): void
    {
        $this->conversationId = null;
        $this->activeRunId = null;
        $this->draft = '';
        $this->refusal = null;
        $this->taskScope = null;

        $this->forgetConversationState();
    }

    public function openConversation(int $conversationId): void
    {
        $conversation = AiConversation::query()->whereKey($conversationId)->first();

        if (! $conversation instanceof AiConversation) {
            return;
        }

        $this->authorize('view', $conversation);

        $this->conversationId = (int) $conversation->getKey();
        $this->projectScope = $conversation->project_id === null ? null : (int) $conversation->project_id;
        $this->taskScope = $conversation->task_id === null ? null : (int) $conversation->task_id;
        $this->refusal = null;
        $this->draft = '';

        $this->forgetConversationState();
        $this->activeRunId = $this->unfinishedRunId($conversation);
    }

    /**
     * The poll target. Its only job is to stop the polling once the run is done, so a
     * finished conversation costs nothing.
     */
    public function tick(): void
    {
        $this->forgetConversationState();

        $run = $this->activeRun;

        if ($run === null || $run->isTerminal()) {
            $this->activeRunId = null;
        }
    }

    public function updatedSearch(): void
    {
        unset($this->conversationGroups);
    }

    /**
     * Aim the next message at a project, or at the whole workspace.
     *
     * A scope chosen by hand is a decision about the *next* message, not a claim about the
     * thread already on screen — so it opens a new one. A conversation whose scope changed
     * halfway through is a conversation whose transcript no longer explains its own tool
     * calls.
     */
    public function scopeTo(?int $projectId = null): void
    {
        // A screen that *is* a project — the project's own AI tab — has no scope to choose.
        // Refusing here as well as hiding the control is the difference between a menu that
        // is absent and a capability that is absent.
        if ($this->scopeIsLocked()) {
            return;
        }

        if ($projectId !== null) {
            $project = Project::query()->whereKey($projectId)->first();

            if (! $project instanceof Project) {
                return;
            }

            $this->authorize('useAi', $project);

            $projectId = (int) $project->getKey();
        }

        if ($this->projectScope === $projectId && $this->taskScope === null) {
            return;
        }

        $this->projectScope = $projectId;
        $this->taskScope = null;
        $this->refusal = null;
        $this->conversationId = null;
        $this->activeRunId = null;

        $this->forgetConversationState();
    }

    /**
     * The project the composer is aimed at, for the scope chip.
     */
    public function scopeProject(): ?Project
    {
        return $this->scopedProject();
    }

    /**
     * Three openings, written for whatever the composer is aimed at.
     *
     * An empty conversation is a designed moment, not an absence: it has to say what this
     * space is for. Suggestions that ignore the scope would say it badly — "what is at risk
     * this week" is a workspace question, and offering it on a task is how a person learns
     * the assistant does not know where it is.
     *
     * @return list<string>
     */
    public function suggestions(): array
    {
        $task = $this->scopedTask();

        if ($task instanceof Task) {
            $reference = $task->key.' — '.$task->title;

            return [
                __('Summarise :task, including its status, blockers and what is left to do.', ['task' => $reference]),
                __('Break :task into subtasks and create them.', ['task' => $reference]),
                __('Draft a progress comment for :task that I can review before posting.', ['task' => $reference]),
            ];
        }

        $project = $this->scopedProject();

        if ($project instanceof Project) {
            return [
                __('What is at risk in :project, and what is the single most useful thing to do next?', ['project' => $project->name]),
                __('Summarise what has changed in :project since Monday.', ['project' => $project->name]),
                __('Draft the tasks for the next milestone in :project, but show them to me before creating anything.', ['project' => $project->name]),
            ];
        }

        return [
            __('What is at risk this week?'),
            __('Which projects are waiting on somebody, and on whom?'),
            __('Summarise what changed across my projects since Monday.'),
        ];
    }

    /* ------------------------------------------------------------------ *
     * Reading
     * ------------------------------------------------------------------ */

    #[Computed]
    public function conversation(): ?AiConversation
    {
        if ($this->conversationId === null) {
            return null;
        }

        return AiConversation::query()
            ->with('project:id,name,slug,color,key')
            ->whereKey($this->conversationId)
            ->first();
    }

    /**
     * The transcript and the audit trail, interleaved.
     *
     * @return list<TimelineEntry>
     */
    #[Computed]
    public function timeline(): array
    {
        $conversation = $this->conversation;

        if (! $conversation instanceof AiConversation) {
            return [];
        }

        $messages = AiMessage::query()
            ->forConversation($conversation)
            ->chronological()
            ->get();

        $runIds = $messages
            ->pluck('ai_run_id')
            ->filter(static fn (mixed $id): bool => $id !== null)
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $toolRuns = $runIds->isEmpty()
            ? new EloquentCollection
            : AiToolRun::query()
                ->whereIn('ai_run_id', $runIds->all())
                ->with([
                    'user:id,name,avatar_path',
                    'approver:id,name,avatar_path',
                    'run:id,objective,mode,trigger,user_id',
                    'run.user:id,name,avatar_path',
                    'project:id,name,slug,color,key',
                ])
                ->ordered()
                ->get();

        return ConversationTimeline::build($messages, $toolRuns);
    }

    #[Computed]
    public function activeRun(): ?AiRun
    {
        if ($this->activeRunId === null) {
            return null;
        }

        return AiRun::query()->whereKey($this->activeRunId)->first();
    }

    /**
     * What the assistant is doing, in words, or null when nothing is in flight.
     */
    #[Computed]
    public function progress(): ?RunProgress
    {
        $run = $this->activeRun;

        if (! $run instanceof AiRun || $run->isTerminal()) {
            return null;
        }

        $lastCall = AiToolRun::query()
            ->forRun($run)
            ->orderByDesc('sequence')
            ->orderByDesc('id')
            ->first();

        return RunProgress::for($run, $lastCall);
    }

    /**
     * The resolved policy for the *next* message: the mode indicator, and the sentence the
     * tooltip explains it with.
     */
    #[Computed]
    public function policy(): ResolvedPolicy
    {
        if (! $this->workspace instanceof Workspace) {
            return ResolvedPolicy::blocked(ResolvedPolicy::BLOCKED_UNCONFIGURED);
        }

        return app(PolicyResolver::class)->resolve(
            $this->workspace,
            $this->scopedProject(),
            $this->actor(),
        );
    }

    public function mode(): AiMode
    {
        return $this->policy->mode;
    }

    /**
     * Why no run can start here at all, or null. Asked of the workspace rather than of the
     * person, because this is the banner the surface draws before anybody types.
     */
    #[Computed]
    public function unavailableReason(): ?string
    {
        if (! $this->workspace instanceof Workspace) {
            return __('ai.gate.not_configured');
        }

        return app(AiGate::class)->workspaceRefusal($this->workspace);
    }

    public function available(): bool
    {
        return $this->unavailableReason === null;
    }

    /**
     * The rail: this person's threads, newest first, grouped by when they were last used.
     *
     * @return list<array{key: string, label: string, conversations: EloquentCollection<int, AiConversation>}>
     */
    #[Computed]
    public function conversationGroups(): array
    {
        if (! $this->workspace instanceof Workspace) {
            return [];
        }

        $timezone = $this->timezone();
        $startOfToday = Carbon::now($timezone)->startOfDay();
        $startOfWeek = $startOfToday->copy()->subDays(6);

        $buckets = ['today' => [], 'week' => [], 'earlier' => []];

        foreach ($this->recentConversations() as $conversation) {
            $moment = $conversation->last_activity_at ?? $conversation->created_at;
            $local = $moment instanceof Carbon ? $moment->copy()->setTimezone($timezone) : null;

            $bucket = match (true) {
                $local === null => 'earlier',
                $local->greaterThanOrEqualTo($startOfToday) => 'today',
                $local->greaterThanOrEqualTo($startOfWeek) => 'week',
                default => 'earlier',
            };

            $buckets[$bucket][] = $conversation;
        }

        $labels = [
            'today' => __('Today'),
            'week' => __('This week'),
            'earlier' => __('Earlier'),
        ];

        $groups = [];

        foreach ($buckets as $key => $rows) {
            if ($rows === []) {
                continue;
            }

            $groups[] = [
                'key' => $key,
                'label' => $labels[$key],
                'conversations' => new EloquentCollection($rows),
            ];
        }

        return $groups;
    }

    /**
     * The projects the composer may scope a conversation to.
     *
     * @return EloquentCollection<int, Project>
     */
    #[Computed]
    public function scopeOptions(): EloquentCollection
    {
        if (! $this->workspace instanceof Workspace) {
            return new EloquentCollection;
        }

        return Project::query()
            ->forWorkspace($this->workspace)
            ->visibleTo($this->actor())
            ->where('is_archived', false)
            ->orderBy('name')
            ->limit(self::MAX_SCOPE_OPTIONS)
            ->get(['id', 'name', 'color', 'key']);
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    /**
     * @return EloquentCollection<int, AiConversation>
     */
    private function recentConversations(): EloquentCollection
    {
        $term = trim($this->search);

        return AiConversation::query()
            ->forWorkspace($this->workspace)
            ->forUser($this->actor())
            ->notArchived()
            ->when($term !== '', function (Builder $query) use ($term): void {
                // The term is a user's own search string, so the LIKE wildcards in it are
                // escaped rather than honoured: typing "%" must not match everything.
                $query->where('title', 'like', '%'.addcslashes($term, '%_\\').'%');
            })
            ->with('project:id,name,slug,color,key')
            ->recent()
            ->limit(self::MAX_CONVERSATIONS)
            ->get();
    }

    /**
     * The thread the next message belongs to: the one on screen, or a new one named after
     * the question that opened it.
     */
    private function conversationFor(?Project $project, ?Task $task, string $prompt, AiMode $mode): AiConversation
    {
        if ($this->conversationId !== null) {
            $existing = AiConversation::query()->whereKey($this->conversationId)->first();

            if ($existing instanceof AiConversation) {
                $this->authorize('reply', $existing);

                return $existing;
            }
        }

        $this->authorize('create', [AiConversation::class, $project]);

        return AiConversation::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'project_id' => $project?->getKey(),
            'task_id' => $task?->getKey(),
            'user_id' => $this->actor()->getKey(),
            'title' => Str::limit($prompt, self::MAX_TITLE, ''),
            'mode' => $mode,
            'scope' => match (true) {
                $task instanceof Task => AiScope::Task,
                $project instanceof Project => AiScope::Project,
                default => AiScope::Workspace,
            },
            'last_activity_at' => Carbon::now(),
            'message_count' => 0,
            'is_archived' => false,
        ]);
    }

    /**
     * The project in scope, re-read through the workspace scope so an id from another
     * tenant simply does not resolve. Authorisation is the caller's: this is also used
     * during render, and a render is not the place to throw a 403.
     */
    private function scopedProject(): ?Project
    {
        if ($this->projectScope === null) {
            return null;
        }

        $project = Project::query()->whereKey($this->projectScope)->first();

        if (! $project instanceof Project) {
            $this->projectScope = null;

            return null;
        }

        return $project;
    }

    private function scopedTask(): ?Task
    {
        if ($this->taskScope === null) {
            return null;
        }

        // The project comes with it: `Task::key` reads it for the "WEB-42" label and would
        // otherwise fall back to "#42" rather than issue a query it is not allowed to.
        $task = Task::query()->with('project:id,key,name,color,slug')->whereKey($this->taskScope)->first();

        if (! $task instanceof Task) {
            $this->taskScope = null;

            return null;
        }

        return $task;
    }

    private function unfinishedRunId(AiConversation $conversation): ?int
    {
        $run = AiRun::query()
            ->where('ai_conversation_id', $conversation->getKey())
            ->unfinished()
            ->recent()
            ->first();

        return $run instanceof AiRun ? (int) $run->getKey() : null;
    }

    private function cleanPrompt(string $prompt): string
    {
        return mb_substr(trim($prompt), 0, self::MAX_PROMPT);
    }

    /**
     * Whether the composer's scope is fixed by the screen it is on. Overridden by the
     * project tab; false everywhere else.
     */
    protected function scopeIsLocked(): bool
    {
        return false;
    }

    private function timezone(): string
    {
        $timezone = $this->workspace?->timezone;

        return is_string($timezone) && $timezone !== '' ? $timezone : (string) config('app.timezone', 'UTC');
    }

    /**
     * Drop every cached read so the next render sees the row the queue worker just wrote.
     */
    protected function forgetConversationState(): void
    {
        unset(
            $this->conversation,
            $this->timeline,
            $this->activeRun,
            $this->progress,
            $this->policy,
            $this->conversationGroups,
        );
    }
}

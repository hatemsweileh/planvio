@php
    use App\Support\Formats;

    $counts = $this->counts;
    $assessment = $this->assessment;
    $facts = $this->healthFacts;
    $milestones = $this->milestones;
    $team = $this->team;
    $budget = $this->budget;
    $attachments = $this->attachments;

    $hours = static fn (int $minutes): string => Formats::number($minutes / 60, 1);

    /**
     * Activity events, phrased. The feed is read far more often than it is written, so the
     * verbs are spelled out rather than assembled from the column value.
     */
    $phrase = static fn (string $event): string => match ($event) {
        'created' => __('created this project'),
        'created_from_template' => __('created this project from a template'),
        'updated' => __('updated the project'),
        'archived' => __('archived the project'),
        'restored' => __('restored the project'),
        'deleted' => __('deleted the project'),
        'status_changed' => __('changed a status'),
        'assigned' => __('reassigned a task'),
        'completed' => __('completed something'),
        'member_added' => __('added a member'),
        'member_removed' => __('removed a member'),
        'member_role_changed' => __('changed a member role'),
        'invited' => __('invited somebody'),
        'commented' => __('left a comment'),
        default => __('made a change (:event)', ['event' => str_replace('_', ' ', $event)]),
    };

    $subjectLabel = static fn (?string $type): string => match (class_basename((string) $type)) {
        'Project' => __('project'),
        'Task' => __('task'),
        'Milestone' => __('milestone'),
        'Comment' => __('comment'),
        'WikiPage' => __('wiki page'),
        'Attachment' => __('file'),
        'TimeEntry' => __('time entry'),
        'Invitation' => __('invitation'),
        default => __('record'),
    };
@endphp

<div>
    <x-app.project-shell :project="$project" :workspace="$workspace" current="overview"
                         :favourite="$this->isFavourite" :counts="['tasks' => $counts['tasks']]"
                         :health="$assessment->health">
        <x-slot:actions>
            @can('task.create', $project)
                <x-ui.button variant="primary" size="md" icon="icon.plus"
                             x-on:click="$dispatch('open-quick-create', { type: 'task', project: {{ $project->getKey() }} })"
                             class="hidden sm:inline-flex">
                    {{ __('New task') }}
                </x-ui.button>
            @endcan
        </x-slot:actions>

        <x-slot:stats>
            {{-- The strip. Six numbers, no chart: this is the row people scan before a
                 stand-up, and a sparkline would slow that down rather than speed it up. --}}
            <dl class="mt-4 grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-[var(--line-subtle)]
                       bg-[var(--line-subtle)] sm:grid-cols-3 lg:grid-cols-6">

                <div class="bg-[var(--surface-panel)] px-3 py-2.5">
                    <dt class="text-2xs uppercase tracking-wide text-[var(--text-subtle)]">{{ __('Progress') }}</dt>
                    <dd class="mt-1">
                        <span class="text-lg font-semibold leading-none tabular-nums text-[var(--text-strong)]">
                            {{ $project->progress }}<span class="text-sm text-[var(--text-muted)]">%</span>
                        </span>
                        <x-ui.progress :value="$project->progress" size="xs" class="mt-1.5"
                                       :label="__('Project progress')" />
                    </dd>
                </div>

                @foreach ([
                    [__('Tasks'), $counts['tasks'], __(':open open', ['open' => $counts['open']]), false],
                    [__('Completed'), $counts['completed'], __('of :total', ['total' => $counts['tasks']]), false],
                    [__('Overdue'), $counts['overdue'], $counts['overdue'] > 0 ? __('needs attention') : __('nothing late'), true],
                    [__('Team'), $team->count(), __('on this project'), false],
                ] as [$statLabel, $statValue, $statHint, $critical])
                    <div class="bg-[var(--surface-panel)] px-3 py-2.5">
                        <dt class="text-2xs uppercase tracking-wide text-[var(--text-subtle)]">{{ $statLabel }}</dt>
                        <dd class="mt-1">
                            <span class="text-lg font-semibold leading-none tabular-nums {{ $critical && $statValue > 0 ? 'text-critical-600' : 'text-[var(--text-strong)]' }}">
                                {{ $statValue }}
                            </span>
                            <span class="mt-1 block text-2xs text-[var(--text-subtle)]">{{ $statHint }}</span>
                        </dd>
                    </div>
                @endforeach

                <div class="bg-[var(--surface-panel)] px-3 py-2.5">
                    <dt class="text-2xs uppercase tracking-wide text-[var(--text-subtle)]">{{ __('Due date') }}</dt>
                    <dd class="mt-1">
                        <span class="text-lg font-semibold leading-none {{ $project->is_overdue ? 'text-critical-600' : 'text-[var(--text-strong)]' }}">
                            {{ $project->target_date?->isoFormat('D MMM') ?? '—' }}
                        </span>
                        <span class="mt-1 block text-2xs text-[var(--text-subtle)]">
                            @if ($project->target_date)
                                {{ $project->target_date->isoFormat('YYYY') }}
                            @else
                                {{ __('not set') }}
                            @endif
                        </span>
                    </dd>
                </div>
            </dl>
        </x-slot:stats>
    </x-app.project-shell>

    <div class="page py-5">
        <div class="grid gap-4 lg:grid-cols-3">

            {{-- ============================================================ --}}
            {{-- Main column                                                  --}}
            {{-- ============================================================ --}}
            <div class="space-y-4 lg:col-span-2">

                {{-- About ------------------------------------------------- --}}
                <x-ui.card :title="__('About this project')">
                    <x-slot:actions>
                        @can('update', $project)
                            <x-ui.button :href="route('app.projects.settings', [$workspace, $project])"
                                         variant="ghost" size="sm" icon="icon.cog">
                                {{ __('Edit') }}
                            </x-ui.button>
                        @endcan
                    </x-slot:actions>

                    @if (filled($project->description))
                        {{-- Written by a person; its direction is the text's, not the reader's. --}}
                        <div dir="auto" class="prose-planvio max-w-none text-sm">
                            {!! nl2br(e($project->description)) !!}
                        </div>
                    @else
                        <x-ui.empty-state compact icon="icon.document"
                                          :title="__('No description yet')"
                                          :description="__('A paragraph on what done looks like is the first thing a new joiner — and the AI — reads about this project.')">
                            @can('update', $project)
                                <x-slot:actions>
                                    <x-ui.button :href="route('app.projects.settings', [$workspace, $project])"
                                                 variant="secondary" size="sm">
                                        {{ __('Write one') }}
                                    </x-ui.button>
                                </x-slot:actions>
                            @endcan
                        </x-ui.empty-state>
                    @endif

                    <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 border-t border-[var(--line-subtle)] pt-4 sm:grid-cols-4">
                        @foreach ([
                            [__('Type'), $project->type->label()],
                            [__('Priority'), $project->priority->label()],
                            [__('Start'), $project->start_date?->isoFormat('D MMM YYYY') ?? '—'],
                            [__('Client'), $project->client_name ?: '—'],
                        ] as [$metaLabel, $metaValue])
                            <div>
                                <dt class="text-2xs uppercase tracking-wide text-[var(--text-subtle)]">{{ $metaLabel }}</dt>
                                <dd class="mt-0.5 truncate text-sm text-[var(--text-DEFAULT)]">{{ $metaValue }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </x-ui.card>

                {{-- Milestones -------------------------------------------- --}}
                <x-ui.card :title="__('Milestones')"
                           :subtitle="__(':done of :total complete', ['done' => $counts['milestones_done'], 'total' => $counts['milestones']])"
                           flush>
                    <x-slot:actions>
                        <x-ui.button :href="route('app.projects.timeline', [$workspace, $project])"
                                     variant="ghost" size="sm">
                            {{ __('Timeline') }}
                        </x-ui.button>
                    </x-slot:actions>

                    @if ($milestones->isEmpty())
                        <x-ui.empty-state icon="icon.flag"
                                          :title="__('No milestones yet')"
                                          :description="__('Milestones are the handful of dates the project is actually judged on. Three or four is usually right.')">
                            @can('milestone.manage', $project)
                                <x-slot:actions>
                                    <x-ui.button variant="secondary" size="md" icon="icon.plus"
                                                 x-on:click="$dispatch('open-quick-create', { type: 'milestone', project: {{ $project->getKey() }} })">
                                        {{ __('Add a milestone') }}
                                    </x-ui.button>
                                </x-slot:actions>
                            @endcan
                        </x-ui.empty-state>
                    @else
                        <ul class="divide-y divide-[var(--line-subtle)]">
                            @foreach ($milestones as $milestone)
                                @php
                                    $milestoneOverdue = $milestone->due_date !== null
                                        && $milestone->completed_at === null
                                        && $milestone->due_date->isPast();
                                @endphp
                                <li wire:key="milestone-{{ $milestone->getKey() }}" class="px-4 py-3">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-medium text-[var(--text-strong)]">
                                                {{ $milestone->name }}
                                            </p>
                                            <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-2xs text-[var(--text-subtle)]">
                                                <x-ui.badge :color="$milestone->status->color()" size="sm">
                                                    {{ $milestone->status->label() }}
                                                </x-ui.badge>
                                                @if ($milestone->due_date)
                                                    <span class="{{ $milestoneOverdue ? 'text-critical-600' : '' }}">
                                                        {{ __('due :date', ['date' => $milestone->due_date->isoFormat('D MMM YYYY')]) }}
                                                    </span>
                                                @endif
                                                <span>{{ __(':done/:total tasks', [
                                                    'done' => (int) $milestone->completed_tasks_count,
                                                    'total' => (int) $milestone->tasks_count,
                                                ]) }}</span>
                                            </p>
                                        </div>
                                        @if ($milestone->owner)
                                            <x-ui.avatar :user="$milestone->owner" size="sm" />
                                        @endif
                                    </div>
                                    <x-ui.progress :value="$milestone->progress" size="sm" show-label class="mt-2"
                                                   :label="__('Progress of :milestone', ['milestone' => $milestone->name])" />
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>

                {{-- Activity ---------------------------------------------- --}}
                <x-ui.card :title="__('Recent activity')" flush>
                    <x-slot:actions>
                        <x-ui.button :href="route('app.projects.activity', [$workspace, $project])"
                                     variant="ghost" size="sm">
                            {{ __('See all') }}
                        </x-ui.button>
                    </x-slot:actions>

                    @if ($this->activity->isEmpty())
                        <x-ui.empty-state compact icon="icon.clock"
                                          :title="__('Nothing has happened yet')"
                                          :description="__('Every change to this project — a status moved, a milestone hit, a file added — is recorded here.')" />
                    @else
                        <ul class="divide-y divide-[var(--line-subtle)]">
                            @foreach ($this->activity as $entry)
                                <li wire:key="activity-{{ $entry->getKey() }}" class="flex items-start gap-2.5 px-4 py-2.5">
                                    @if ($entry->isFromAi())
                                        <span class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full
                                                     bg-[var(--accent-soft)] text-[var(--accent-soft-text)]">
                                            <x-icon.sparkles class="size-3.5" />
                                        </span>
                                    @else
                                        <x-ui.avatar :user="$entry->causer" :name="$entry->causer?->name ?? __('System')"
                                                     size="sm" class="mt-0.5" />
                                    @endif

                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm leading-snug text-[var(--text-DEFAULT)]">
                                            <span class="font-medium text-[var(--text-strong)]">
                                                {{ $entry->causer?->name ?? __('Planvio') }}
                                            </span>
                                            @if ($entry->isFromAi())
                                                <x-ui.badge color="purple" size="sm">{{ __('AI') }}</x-ui.badge>
                                            @endif
                                            {{ $phrase((string) $entry->event) }}
                                            <span class="text-[var(--text-muted)]">· {{ $subjectLabel($entry->subject_type) }}</span>
                                        </p>
                                        <p class="mt-0.5 text-2xs text-[var(--text-subtle)]">
                                            <time datetime="{{ $entry->created_at?->toIso8601String() }}"
                                                  x-data="relativeTime('{{ $entry->created_at?->toIso8601String() }}')"
                                                  x-text="label">{{ Formats::using($entry->created_at, 'j M, H:i', '') }}</time>
                                        </p>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>
            </div>

            {{-- ============================================================ --}}
            {{-- Side column                                                  --}}
            {{-- ============================================================ --}}
            <div class="space-y-4">

                {{-- Risks --------------------------------------------------- --}}
                <x-ui.card>
                    <x-slot:header>
                        <div class="flex items-center gap-2">
                            <h3 class="text-sm font-semibold text-[var(--text-strong)]">{{ __('Risks') }}</h3>
                            <x-ui.badge :color="$assessment->health->color()" size="sm" dot>
                                {{ $assessment->health->label() }}
                            </x-ui.badge>
                        </div>
                        <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                            {{ __('Measured from tasks, milestones, assignees and the target date.') }}
                        </p>
                    </x-slot:header>

                    @if ($assessment->overridesComputed())
                        <div class="mb-3 flex items-start gap-2 rounded-md border border-caution-500/40
                                    bg-caution-50 px-2.5 py-2 text-xs text-caution-700
                                    dark:bg-caution-950 dark:text-caution-100">
                            <x-icon.warning class="mt-0.5 size-3.5 shrink-0" />
                            <span>
                                {{ __('Health is pinned to “:pinned” by hand. The measurements below say “:computed”.', [
                                    'pinned' => $assessment->health->label(),
                                    'computed' => $assessment->computed->label(),
                                ]) }}
                            </span>
                        </div>
                    @endif

                    @if ($facts === [])
                        <p class="text-sm text-[var(--text-muted)]">
                            {{ __('Nothing is flagged. No overdue tasks, no delayed milestones, no single person holding most of the open work, and the target date is not crowding the plan.') }}
                        </p>
                    @else
                        <ul class="space-y-2.5">
                            @foreach ($facts as $fact)
                                <li class="flex items-start gap-2">
                                    <span class="mt-1.5 size-1.5 shrink-0 rounded-full {{ $fact['color'] === 'red' ? 'bg-critical-500' : ($fact['color'] === 'amber' ? 'bg-caution-500' : 'bg-positive-500') }}"
                                          aria-hidden="true"></span>
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-[var(--text-strong)]">{{ $fact['title'] }}</p>
                                        <p class="mt-0.5 text-xs leading-relaxed text-[var(--text-muted)]">{{ $fact['detail'] }}</p>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if (filled($project->health_note))
                        {{-- A narrative, and labelled as one. Facts above are counted; this is
                             somebody's — or the agent's — reading of them. --}}
                        <div class="mt-3 rounded-md border border-[var(--line-subtle)] bg-[var(--surface-sunken)] p-2.5">
                            <p class="flex items-center gap-1.5 text-2xs font-semibold uppercase tracking-wide text-[var(--text-subtle)]">
                                <x-icon.chat class="size-3.5" />
                                {{ __('Analysis · not a measurement') }}
                            </p>
                            <p class="mt-1 text-xs leading-relaxed text-[var(--text-DEFAULT)]">{{ $project->health_note }}</p>
                        </div>
                    @endif
                </x-ui.card>

                {{-- Team ---------------------------------------------------- --}}
                <x-ui.card :title="__('Team')" :subtitle="trans_choice('{0}Nobody yet|{1}1 person|[2,*]:count people', $team->count(), ['count' => $team->count()])" flush>
                    <x-slot:actions>
                        @can('manageMembers', $project)
                            <x-ui.button :href="route('app.projects.settings', [$workspace, $project]).'?section=members'"
                                         variant="ghost" size="sm" icon="icon.plus">
                                {{ __('Manage') }}
                            </x-ui.button>
                        @endcan
                    </x-slot:actions>

                    @if ($team->isEmpty())
                        <x-ui.empty-state compact icon="icon.users"
                                          :title="__('No members yet')"
                                          :description="__('Add the people doing the work so tasks can be assigned and they get the notifications.')" />
                    @else
                        <ul class="divide-y divide-[var(--line-subtle)]">
                            @foreach ($team as $person)
                                @php
                                    // Project::members() has no ->using(), so the pivot carries a raw string
                                    // rather than a cast enum.
                                    $memberRole = \App\Enums\ProjectRole::tryFrom((string) $person->pivot->role)
                                        ?? \App\Enums\ProjectRole::Member;
                                @endphp
                                <li wire:key="team-{{ $person->getKey() }}" class="flex items-center gap-2.5 px-4 py-2">
                                    <x-ui.avatar :user="$person" size="sm" />
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm text-[var(--text-DEFAULT)]">{{ $person->name }}</p>
                                        @if ($person->job_title)
                                            <p class="truncate text-2xs text-[var(--text-subtle)]">{{ $person->job_title }}</p>
                                        @endif
                                    </div>
                                    <x-ui.badge :color="$memberRole->color()" size="sm">
                                        {{ $memberRole->label() }}
                                    </x-ui.badge>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>

                {{-- Budget and time ----------------------------------------- --}}
                @if ($budget !== null)
                    <x-ui.card :title="__('Budget and time')" :subtitle="$budget->currency">
                        <div class="space-y-3">
                            @if ($budget->hasBudget())
                                <div>
                                    <div class="flex items-baseline justify-between gap-2">
                                        <span class="text-sm text-[var(--text-muted)]">{{ __('Spent') }}</span>
                                        {{-- "spent / planned" is one figure, and the space before the
                                             slash is a neutral: left to the page direction it prints
                                             the two amounts the other way round, which reads as having
                                             spent the budget on a fraction of it. --}}
                                        <x-ui.bidi class="text-sm font-semibold tabular-nums {{ $budget->isOverBudget() ? 'text-critical-600' : 'text-[var(--text-strong)]' }}">
                                            {{ $budget->actual() }} <span class="font-normal text-[var(--text-subtle)]">/ {{ $budget->planned() }}</span>
                                        </x-ui.bidi>
                                    </div>
                                    <x-ui.progress class="mt-1.5"
                                                   :value="min(100, (int) round($budget->utilisation() ?? 0))"
                                                   :color="$budget->isOverBudget() ? 'bg-critical-500' : null"
                                                   size="sm"
                                                   :label="__('Budget used')" />
                                    <p class="mt-1 text-2xs text-[var(--text-subtle)]">
                                        @if ($budget->isOverBudget())
                                            {{ __('Over by :amount', ['amount' => ltrim((string) $budget->variance(), '-')]) }}
                                        @else
                                            {{ __(':amount remaining', ['amount' => $budget->variance()]) }}
                                        @endif
                                        · {{ trans_choice('{0}no expenses|{1}1 expense|[2,*]:count expenses', $budget->expenseCount, ['count' => $budget->expenseCount]) }}
                                    </p>
                                </div>
                            @else
                                <p class="text-sm text-[var(--text-muted)]">
                                    {{ __('No budget set. :amount has been booked against this project so far.', ['amount' => $budget->actual()]) }}
                                </p>
                            @endif

                            @if ($budget->hasUnconvertedCosts())
                                {{-- Planvio ships no exchange rates, so other currencies are
                                     reported beside the total rather than folded into it. --}}
                                <p class="rounded-md bg-[var(--surface-sunken)] px-2.5 py-2 text-2xs text-[var(--text-muted)]">
                                    {{ __('Also booked, not converted:') }}
                                    {{ collect($budget->unconvertedAmounts())->map(fn ($amount, $code) => $code.' '.$amount)->join(', ') }}
                                </p>
                            @endif

                            <dl class="grid grid-cols-2 gap-3 border-t border-[var(--line-subtle)] pt-3">
                                <div>
                                    <dt class="text-2xs uppercase tracking-wide text-[var(--text-subtle)]">{{ __('Logged') }}</dt>
                                    <dd class="mt-0.5 text-sm font-semibold tabular-nums text-[var(--text-strong)]">
                                        {{ __(':hours h', ['hours' => $hours($budget->loggedMinutes)]) }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-2xs uppercase tracking-wide text-[var(--text-subtle)]">{{ __('Billable') }}</dt>
                                    <dd class="mt-0.5 text-sm font-semibold tabular-nums text-[var(--text-strong)]">
                                        {{ __(':hours h', ['hours' => $hours($budget->billableMinutes)]) }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </x-ui.card>
                @endif

                {{-- Files --------------------------------------------------- --}}
                <x-ui.card :title="__('Files')"
                           :subtitle="trans_choice('{0}None yet|{1}1 file|[2,*]:count files', $this->attachmentCount, ['count' => $this->attachmentCount])"
                           flush>
                    <x-slot:actions>
                        <x-ui.button :href="route('app.projects.files', [$workspace, $project])"
                                     variant="ghost" size="sm">
                            {{ __('Open') }}
                        </x-ui.button>
                    </x-slot:actions>

                    @if ($attachments->isEmpty())
                        <x-ui.empty-state compact icon="icon.paperclip"
                                          :title="__('No files here yet')"
                                          :description="__('Briefs, contracts and designs attached to the project itself show up here.')" />
                    @else
                        <ul class="divide-y divide-[var(--line-subtle)]">
                            @foreach ($attachments as $file)
                                <li wire:key="file-{{ $file->getKey() }}">
                                    <a href="{{ route('attachments.download', $file) }}"
                                       class="flex items-center gap-2.5 px-4 py-2 transition-colors hover:bg-[var(--surface-hover)]">
                                        <span class="grid size-7 shrink-0 place-items-center rounded bg-[var(--surface-sunken)]
                                                     text-[var(--text-subtle)]">
                                            <x-icon.document class="size-3.5" />
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-sm text-[var(--text-DEFAULT)]">{{ $file->original_name }}</span>
                                            <span class="block truncate text-2xs text-[var(--text-subtle)]">
                                                {{ $file->human_size }} · {{ $file->uploader?->name ?? __('Unknown') }}
                                            </span>
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>
            </div>
        </div>
    </div>
</div>

@php
    use App\Support\Formats;

    /**
     * Tone classes are written out in full and selected by key. Tailwind scans source text
     * and never evaluates it, so `text-{{ $tone }}-600` would compile to nothing at all.
     */
    $dueText = [
        'late' => 'text-critical-600 dark:text-critical-100',
        'today' => 'text-caution-700 dark:text-caution-100',
        'soon' => 'text-[var(--text-DEFAULT)]',
        'later' => 'text-[var(--text-muted)]',
        'none' => 'text-[var(--text-subtle)]',
    ];
    $dueDot = [
        'late' => 'bg-critical-500',
        'today' => 'bg-caution-500',
        'soon' => 'bg-[var(--accent)]',
        'later' => 'bg-[var(--line-strong)]',
        'none' => 'bg-[var(--line-strong)]',
    ];
    $mine = $this->mine;
@endphp

<div class="mx-auto w-full max-w-[92rem] px-4 py-5 sm:px-6 lg:py-6">

    {{-- ---------------------------------------------------------------- --}}
    {{-- Greeting                                                         --}}
    {{-- ---------------------------------------------------------------- --}}
    <header class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
        <div class="min-w-0">
            <p class="text-2xs font-semibold uppercase tracking-wider text-[var(--text-subtle)]">
                {{ Formats::using($this->today, 'l, j F Y') }}
            </p>
            <h2 class="mt-1 truncate text-lg font-semibold tracking-tight text-[var(--text-strong)]">
                {{ $this->greeting() }}
            </h2>
            <p class="mt-1 text-sm leading-relaxed text-[var(--text-muted)]">
                {{ $this->stateOfPlay() }}
            </p>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            <x-ui.button :href="route('app.my-tasks', $workspace)" icon="icon.check-circle" size="md">
                {{ __('My tasks') }}
            </x-ui.button>
            @can('project.create', [\App\Models\Project::class, $workspace])
                <x-ui.button variant="primary" size="md" icon="icon.plus"
                             :href="route('app.projects.create', $workspace)">
                    {{ __('New project') }}
                </x-ui.button>
            @endcan
        </div>
    </header>

    <div class="mt-5 grid grid-cols-1 gap-4 xl:grid-cols-12">

        {{-- ============================================================ --}}
        {{-- Main column                                                  --}}
        {{-- ============================================================ --}}
        <div class="space-y-4 xl:col-span-8">

            {{-- ------------------------------------------------------- --}}
            {{-- Needs attention                                         --}}
            {{-- ------------------------------------------------------- --}}
            {{--
                The header is written out rather than passed to the card's title slot: three
                counters and a heading do not fit on one line at 375px, and the card's header
                truncates rather than wraps. Written here it wraps, and the heading survives.
            --}}
            <x-ui.card flush>
                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2
                            border-b border-[var(--line-subtle)] px-4 py-3">
                    <h3 class="text-sm font-semibold text-[var(--text-strong)]">{{ __('Needs attention') }}</h3>

                    <div class="flex flex-wrap items-center gap-1.5">
                        @if ($mine['overdue'] > 0)
                            <a href="{{ route('app.my-tasks', [$workspace, 'tab' => 'overdue']) }}"
                               class="rounded focus-visible:outline-2">
                                <x-ui.badge color="red" size="sm" dot>
                                    {{ trans_choice('{1}:count overdue|[2,*]:count overdue', $mine['overdue'], ['count' => $mine['overdue']]) }}
                                </x-ui.badge>
                            </a>
                        @endif
                        @if ($mine['today'] > 0)
                            <a href="{{ route('app.my-tasks', [$workspace, 'tab' => 'today']) }}"
                               class="rounded focus-visible:outline-2">
                                <x-ui.badge color="amber" size="sm" dot>
                                    {{ __(':count due today', ['count' => $mine['today']]) }}
                                </x-ui.badge>
                            </a>
                        @endif
                        @if ($this->unreadMentionCount() > 0)
                            <a href="{{ route('app.inbox', [$workspace, 'filter' => 'mentions']) }}"
                               class="rounded focus-visible:outline-2">
                                <x-ui.badge color="purple" size="sm" dot>
                                    {{ trans_choice('{1}:count mention|[2,*]:count mentions', $this->unreadMentionCount(), ['count' => $this->unreadMentionCount()]) }}
                                </x-ui.badge>
                            </a>
                        @endif
                    </div>
                </div>

                @if (! $this->needsAttention())
                    {{--
                        Nothing is late, nothing is due, nobody is waiting on a reply. That is
                        a result, not an absence, so it is said once and warmly rather than
                        drawn as three empty panels.
                    --}}
                    <x-ui.empty-state icon="icon.check-circle"
                                      :title="__('Nothing needs you today')"
                                      :description="$mine['open'] > 0
                                          ? trans_choice(
                                              '{1}You have :count open task and none of it is late or due today.|[2,*]You have :count open tasks and none of them are late or due today.',
                                              $mine['open'],
                                              ['count' => $mine['open']],
                                            )
                                          : __('Nothing is assigned to you, and your inbox is clear.')">
                        <x-slot:actions>
                            @if ($this->horizon !== [])
                                <x-ui.button size="md" icon="icon.calendar"
                                             :href="route('app.my-tasks', [$workspace, 'tab' => 'upcoming'])">
                                    {{ __('See what is coming') }}
                                </x-ui.button>
                            @else
                                <x-ui.button size="md" icon="icon.folder"
                                             :href="route('app.projects.index', $workspace)">
                                    {{ __('Browse projects') }}
                                </x-ui.button>
                            @endif
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <ul class="divide-y divide-[var(--line-subtle)]">
                        @foreach ($this->overdueTasks as $task)
                            <li wire:key="overdue-{{ $task->getKey() }}">
                                @include('livewire.app.home._task-row', [
                                    'task' => $task,
                                    'tone' => 'late',
                                    'due' => $this->dueLabel($task->due_date),
                                    'dueText' => $dueText,
                                    'dueDot' => $dueDot,
                                ])
                            </li>
                        @endforeach

                        @foreach ($this->dueTodayTasks as $task)
                            <li wire:key="today-{{ $task->getKey() }}">
                                @include('livewire.app.home._task-row', [
                                    'task' => $task,
                                    'tone' => 'today',
                                    'due' => $this->dueLabel($task->due_date),
                                    'dueText' => $dueText,
                                    'dueDot' => $dueDot,
                                ])
                            </li>
                        @endforeach

                        @foreach ($this->mentions as $mention)
                            @php
                                $payload = $mention->data;
                                $url = is_string($payload['url'] ?? null) && $payload['url'] !== ''
                                    ? $payload['url']
                                    : route('app.inbox', [$workspace, 'filter' => 'mentions']);
                            @endphp
                            <li wire:key="mention-{{ $mention->getKey() }}">
                                <a href="{{ $url }}"
                                   class="group flex items-start gap-3 px-4 py-2.5 transition-colors hover:bg-[var(--surface-hover)]">
                                    <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded
                                                 bg-accent-100 text-accent-600 dark:bg-accent-500/20 dark:text-accent-100">
                                        <x-icon.chat class="size-3" />
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span dir="auto" class="block truncate text-sm font-medium text-[var(--text-strong)]
                                                     group-hover:text-[var(--accent)]">
                                            {{ $payload['title'] ?? __('You were mentioned') }}
                                        </span>
                                        @if (filled($payload['body'] ?? null))
                                            <span class="mt-0.5 line-clamp-1 block text-xs text-[var(--text-muted)]">
                                                {{ $payload['body'] }}
                                            </span>
                                        @endif
                                    </span>
                                    <span class="shrink-0 text-2xs text-[var(--text-subtle)]"
                                          x-data="relativeTime('{{ $mention->created_at?->toIso8601String() }}')"
                                          x-text="label"></span>
                                </a>
                            </li>
                        @endforeach
                    </ul>

                    <x-slot:footer>
                        <div class="flex items-center justify-between gap-3 text-xs">
                            <span class="text-[var(--text-muted)]">
                                {{ trans_choice(
                                    '{1}:count task assigned to you|[2,*]:count tasks assigned to you',
                                    $mine['open'],
                                    ['count' => $mine['open']],
                                ) }}
                            </span>
                            <a href="{{ route('app.my-tasks', $workspace) }}"
                               class="font-medium text-[var(--accent)] hover:underline">
                                {{ __('Open my tasks') }}
                            </a>
                        </div>
                    </x-slot:footer>
                @endif
            </x-ui.card>

            {{-- ------------------------------------------------------- --}}
            {{-- The next fortnight                                      --}}
            {{-- ------------------------------------------------------- --}}
            <x-ui.card :title="__('Next 14 days')" flush>
                <x-slot:actions>
                    <a href="{{ route('app.calendar', $workspace) }}"
                       class="shrink-0 text-xs font-medium text-[var(--accent)] hover:underline">
                        {{ __('Calendar') }}
                    </a>
                </x-slot:actions>

                @if ($this->horizon === [])
                    <x-ui.empty-state icon="icon.calendar" compact
                                      :title="__('No deadlines in the next two weeks')"
                                      :description="__('Milestones and dated work assigned to you appear here as soon as they are scheduled.')" />
                @else
                    {{--
                        A strip, not a calendar: only days that actually carry something are
                        drawn, so fourteen mostly-empty cells never crowd out the three that
                        matter. It scrolls sideways on a narrow screen.
                    --}}
                    <div class="scrollbar-thin flex gap-2 overflow-x-auto px-4 py-3.5">
                        @foreach ($this->horizon as $day)
                            @php
                                $isToday = $day['date']->equalTo($this->today);
                                $shownMilestones = array_slice($day['milestones'], 0, 2);
                                $shownTasks = array_slice($day['tasks'], 0, 3);
                                $count = count($day['tasks']) + count($day['milestones']);
                                $hidden = $count - count($shownMilestones) - count($shownTasks);
                            @endphp
                            <div wire:key="day-{{ $day['date']->toDateString() }}"
                                 class="flex w-40 shrink-0 flex-col gap-2 rounded-md border p-2.5
                                        {{ $isToday
                                            ? 'border-[var(--accent)] bg-[var(--accent-soft)]'
                                            : 'border-[var(--line-subtle)] bg-[var(--surface-sunken)]' }}">
                                <div class="flex items-baseline justify-between gap-2">
                                    <span class="text-2xs font-semibold uppercase tracking-wider
                                                 {{ $isToday ? 'text-[var(--accent-soft-text)]' : 'text-[var(--text-subtle)]' }}">
                                        {{ $isToday ? __('Today') : $day['date']->translatedFormat('D j M') }}
                                    </span>
                                    <span class="text-2xs tabular-nums text-[var(--text-subtle)]">{{ $count }}</span>
                                </div>

                                <div class="space-y-1.5">
                                    @foreach ($shownMilestones as $milestone)
                                        <a href="{{ route('app.projects.timeline', [$workspace, $milestone->project]) }}"
                                           class="flex items-start gap-1.5 text-xs text-[var(--text-DEFAULT)] hover:text-[var(--accent)]"
                                           wire:key="ms-{{ $milestone->getKey() }}">
                                            <x-icon.flag class="mt-0.5 size-3 shrink-0 text-[var(--text-subtle)]" />
                                            <span dir="auto" class="line-clamp-2">{{ $milestone->name }}</span>
                                        </a>
                                    @endforeach

                                    @foreach ($shownTasks as $task)
                                        <a href="{{ route('app.tasks.show', [$workspace, $task]) }}"
                                           class="flex items-start gap-1.5 text-xs text-[var(--text-DEFAULT)] hover:text-[var(--accent)]"
                                           wire:key="ht-{{ $task->getKey() }}">
                                            <span class="mt-1 size-1.5 shrink-0 rounded-full"
                                                  style="background-color: {{ $task->project?->color ?? '#9aa0ac' }}"
                                                  aria-hidden="true"></span>
                                            <span dir="auto" class="line-clamp-2">{{ $task->title }}</span>
                                        </a>
                                    @endforeach

                                    @if ($hidden > 0)
                                        <p class="text-2xs text-[var(--text-subtle)]">
                                            {{ __('+:count more', ['count' => $hidden]) }}
                                        </p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>

            {{-- ------------------------------------------------------- --}}
            {{-- Active projects                                         --}}
            {{-- ------------------------------------------------------- --}}
            <x-ui.card :title="__('Active projects')" flush>
                <x-slot:actions>
                    <a href="{{ route('app.projects.index', $workspace) }}"
                       class="shrink-0 text-xs font-medium text-[var(--accent)] hover:underline">
                        {{ __('All projects') }}
                    </a>
                </x-slot:actions>

                @if ($this->projects->isEmpty())
                    <x-ui.empty-state icon="icon.folder"
                                      :title="__('No active projects yet')"
                                      :description="__('A project is where tasks, milestones, files and the wiki live. Create the first one and the dashboard fills itself in.')">
                        <x-slot:actions>
                            @can('project.create', [\App\Models\Project::class, $workspace])
                                <x-ui.button variant="primary" size="md" icon="icon.plus"
                                             :href="route('app.projects.create', $workspace)">
                                    {{ __('Create a project') }}
                                </x-ui.button>
                            @else
                                <x-ui.button size="md" icon="icon.users" :href="route('app.teams', $workspace)">
                                    {{ __('See who is here') }}
                                </x-ui.button>
                            @endcan
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <div class="grid grid-cols-1 gap-3 p-3.5 sm:grid-cols-2">
                        @foreach ($this->projects as $project)
                            <a href="{{ route('app.projects.show', [$workspace, $project]) }}"
                               wire:key="project-{{ $project->getKey() }}"
                               class="group flex flex-col gap-2.5 rounded-md border border-[var(--line-subtle)]
                                      bg-[var(--surface-panel)] p-3 transition-[border-color,box-shadow]
                                      hover:border-[var(--line-DEFAULT)] hover:shadow-raised">

                                <span class="flex items-start gap-2">
                                    <span class="mt-1 size-2.5 shrink-0 rounded-[3px]"
                                          style="background-color: {{ $project->color }}" aria-hidden="true"></span>
                                    <span class="min-w-0 flex-1">
                                        <span dir="auto" class="block truncate text-sm font-medium text-[var(--text-strong)]
                                                     group-hover:text-[var(--accent)]">{{ $project->name }}</span>
                                        <span class="mt-0.5 block font-mono text-2xs text-[var(--text-subtle)]">
                                            {{ $project->display_key }}
                                        </span>
                                    </span>
                                    <x-ui.badge :color="$project->health->color()" size="sm" dot>
                                        {{ $project->health->label() }}
                                    </x-ui.badge>
                                </span>

                                <x-ui.progress :value="$project->progress" size="xs" show-label
                                               :label="__('Progress on :project', ['project' => $project->name])" />

                                <span class="flex items-center justify-between gap-2 text-2xs text-[var(--text-muted)]">
                                    <span class="flex items-center gap-2">
                                        <span class="tabular-nums">
                                            {{ trans_choice('{0}No open tasks|{1}:count open|[2,*]:count open', $project->open_tasks_count, ['count' => $project->open_tasks_count]) }}
                                        </span>
                                        @if ($project->overdue_tasks_count > 0)
                                            <span class="font-medium tabular-nums text-critical-600 dark:text-critical-100">
                                                {{ __(':count late', ['count' => $project->overdue_tasks_count]) }}
                                            </span>
                                        @endif
                                    </span>

                                    <span class="flex items-center gap-2">
                                        @if ($project->target_date)
                                            <span class="flex items-center gap-1 whitespace-nowrap
                                                         {{ $project->is_overdue ? 'text-critical-600 dark:text-critical-100' : '' }}">
                                                <x-icon.calendar class="size-3" />
                                                {{ $project->target_date->translatedFormat('j M') }}
                                            </span>
                                        @endif
                                        <x-ui.avatar-stack :users="$project->members" :max="3" size="xs" />
                                    </span>
                                </span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>

            {{-- ------------------------------------------------------- --}}
            {{-- Recent activity — its own component so it can poll       --}}
            {{-- ------------------------------------------------------- --}}
            <livewire:app.home.activity-feed :workspace="$workspace" />
        </div>

        {{-- ============================================================ --}}
        {{-- Rail                                                         --}}
        {{-- ============================================================ --}}
        <div class="space-y-4 xl:col-span-4">

            {{-- ------------------------------------------------------- --}}
            {{-- Recorded figures                                        --}}
            {{-- ------------------------------------------------------- --}}
            <x-ui.card :title="__('Your week')" :subtitle="__('Counted from the tasks assigned to you')" flush>
                <dl class="grid grid-cols-2 gap-px bg-[var(--line-subtle)]">
                    @foreach ([
                        ['label' => __('Open'), 'value' => $mine['open'], 'tone' => 'text-[var(--text-strong)]'],
                        ['label' => __('Overdue'), 'value' => $mine['overdue'], 'tone' => 'text-critical-600 dark:text-critical-100'],
                        ['label' => __('Due in 7 days'), 'value' => $mine['week'], 'tone' => 'text-[var(--text-strong)]'],
                        ['label' => __('Done this week'), 'value' => $mine['completed_week'], 'tone' => 'text-positive-600 dark:text-positive-100'],
                    ] as $tile)
                        <div class="bg-[var(--surface-panel)] px-4 py-3">
                            <dt class="text-2xs font-medium uppercase tracking-wider text-[var(--text-subtle)]">
                                {{ $tile['label'] }}
                            </dt>
                            <dd class="mt-1 text-xl font-semibold tabular-nums tracking-tight {{ $tile['tone'] }}">
                                {{ $tile['value'] }}
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </x-ui.card>

            {{-- ------------------------------------------------------- --}}
            {{-- AI analysis — a different surface on purpose             --}}
            {{-- ------------------------------------------------------- --}}
            @can('ai.use', $workspace)
                <section aria-labelledby="ai-insights-heading"
                         class="rounded-lg border border-dashed border-accent-500/30 bg-accent-50/70 p-3.5
                                dark:border-accent-500/30 dark:bg-accent-500/5">

                    <div class="flex items-start justify-between gap-2">
                        <div class="flex min-w-0 items-center gap-2">
                            <span class="grid size-6 shrink-0 place-items-center rounded-md bg-accent-100
                                         text-accent-600 dark:bg-accent-500/20 dark:text-accent-100">
                                <x-icon.sparkles class="size-3.5" />
                            </span>
                            <h3 id="ai-insights-heading" class="truncate text-sm font-semibold text-[var(--text-strong)]">
                                {{ __('AI insights') }}
                            </h3>
                        </div>
                        <x-ui.badge color="purple" size="sm">{{ __('AI analysis') }}</x-ui.badge>
                    </div>

                    {{--
                        The label is not decoration. Everything below is a sentence a language
                        model wrote; the figures it talks about live in the card above, where
                        they were counted. Saying so plainly is what keeps the two apart.
                    --}}
                    <p class="mt-2 text-2xs leading-relaxed text-[var(--text-muted)]">
                        {{ __('Written by the assistant, not measured. Check anything you act on against the project itself.') }}
                    </p>

                    @if (! $this->aiAvailable())
                        <p class="mt-3 rounded-md border border-[var(--line-subtle)] bg-[var(--surface-panel)]
                                  px-3 py-2.5 text-xs leading-relaxed text-[var(--text-muted)]">
                            {{ __('The assistant is not switched on for this workspace yet.') }}
                        </p>
                        @can('ai.manage', $workspace)
                            <div class="mt-2.5">
                                <x-ui.button size="sm" icon="icon.cog" :href="route('app.settings', $workspace)">
                                    {{ __('Configure AI') }}
                                </x-ui.button>
                            </div>
                        @endcan
                    @elseif ($this->insights->isEmpty())
                        <p class="mt-3 rounded-md border border-[var(--line-subtle)] bg-[var(--surface-panel)]
                                  px-3 py-2.5 text-xs leading-relaxed text-[var(--text-muted)]">
                            {{ __('No analysis yet. Ask the assistant to review the week and its summary will be kept here.') }}
                        </p>
                        <div class="mt-2.5">
                            <x-ui.button size="sm" variant="soft" icon="icon.sparkles"
                                         x-on:click="$dispatch('open-ai-panel', { intent: 'review' })">
                                {{ __('Review my workspace') }}
                            </x-ui.button>
                        </div>
                    @else
                        <ul class="mt-3 space-y-2">
                            @foreach ($this->insights as $run)
                                <li wire:key="insight-{{ $run->getKey() }}"
                                    class="rounded-md border border-[var(--line-subtle)] bg-[var(--surface-panel)] p-3">
                                    {{-- dir="auto": this is a sentence a model wrote, in whatever
                                         language it answered in, which is not a property of the
                                         reader. Without it an English summary inside an Arabic
                                         page has its full stop resolved to the page direction and
                                         printed at the wrong end of the last line. --}}
                                    <p dir="auto"
                                       class="border-s-2 border-accent-500/50 ps-2.5 text-xs leading-relaxed
                                              text-[var(--text-DEFAULT)] dark:border-accent-500/50">
                                        {{ $run->summary }}
                                    </p>
                                    <p class="mt-2 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-2xs text-[var(--text-subtle)]">
                                        @if ($run->project)
                                            <a href="{{ route('app.projects.show', [$workspace, $run->project]) }}"
                                               class="inline-flex items-center gap-1 hover:text-[var(--text-DEFAULT)]">
                                                <span class="size-1.5 rounded-full"
                                                      style="background-color: {{ $run->project->color }}"
                                                      aria-hidden="true"></span>
                                                {{ $run->project->name }}
                                            </a>
                                            <span aria-hidden="true">&middot;</span>
                                        @endif
                                        @if (filled($run->model))
                                            <span class="font-mono">{{ $run->model }}</span>
                                            <span aria-hidden="true">&middot;</span>
                                        @endif
                                        <span x-data="relativeTime('{{ ($run->finished_at ?? $run->created_at)?->toIso8601String() }}')"
                                              x-text="label"></span>
                                    </p>
                                </li>
                            @endforeach
                        </ul>

                        <div class="mt-3 flex items-center justify-between gap-2">
                            <x-ui.button size="sm" variant="soft" icon="icon.sparkles"
                                         x-on:click="$dispatch('open-ai-panel', { intent: 'review' })">
                                {{ __('Ask for a new one') }}
                            </x-ui.button>
                            <a href="{{ route('app.ai', $workspace) }}"
                               class="text-xs font-medium text-[var(--accent)] hover:underline">
                                {{ __('All runs') }}
                            </a>
                        </div>
                    @endif
                </section>
            @endcan

            {{-- ------------------------------------------------------- --}}
            {{-- Team workload                                           --}}
            {{-- ------------------------------------------------------- --}}
            @if ($this->canSeeWorkload())
                <x-ui.card :title="__('Team workload')" :subtitle="__('Open tasks per person, late work shown in red')" flush>
                    <x-slot:actions>
                        <a href="{{ route('app.reports', $workspace) }}"
                           class="shrink-0 text-xs font-medium text-[var(--accent)] hover:underline">
                            {{ __('Reports') }}
                        </a>
                    </x-slot:actions>

                    @if ($this->workload === [])
                        <x-ui.empty-state icon="icon.users" compact
                                          :title="__('Nothing is assigned yet')"
                                          :description="__('Once work has owners, this shows who is carrying what — and who has room.')" />
                    @else
                        @php $peak = $this->workloadPeak(); @endphp
                        <ul class="divide-y divide-[var(--line-subtle)]">
                            @foreach ($this->workload as $row)
                                @php
                                    $openWidth = $peak > 0 ? max(0, min(100, round($row->open / $peak * 100, 2))) : 0;
                                    $lateShare = $row->open > 0 ? round($row->overdue / $row->open * 100, 2) : 0;
                                @endphp
                                <li wire:key="workload-{{ $row->userId ?? 'unassigned' }}" class="px-4 py-2.5">
                                    <div class="flex items-center gap-2.5">
                                        @if ($row->isUnassigned())
                                            <span class="grid size-6 shrink-0 place-items-center rounded-full
                                                         border border-dashed border-[var(--line-strong)]
                                                         text-[var(--text-subtle)]" aria-hidden="true">
                                                <x-icon.users class="size-3" />
                                            </span>
                                        @else
                                            <x-ui.avatar :name="$row->userName ?? __('Former member')" size="sm" />
                                        @endif

                                        <span dir="auto" class="min-w-0 flex-1 truncate text-xs font-medium text-[var(--text-DEFAULT)]">
                                            {{ $row->isUnassigned() ? __('Unassigned') : ($row->userName ?? __('Former member')) }}
                                        </span>

                                        <span class="shrink-0 text-2xs tabular-nums text-[var(--text-muted)]">
                                            {{ __(':count open', ['count' => $row->open]) }}
                                            @if ($row->overdue > 0)
                                                <span class="text-critical-600 dark:text-critical-100">
                                                    &middot; {{ __(':count late', ['count' => $row->overdue]) }}
                                                </span>
                                            @endif
                                        </span>
                                    </div>

                                    <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-[var(--surface-active)]"
                                         role="img"
                                         aria-label="{{ __(':name: :open open, :overdue overdue', [
                                             'name' => $row->isUnassigned() ? __('Unassigned') : ($row->userName ?? __('Former member')),
                                             'open' => $row->open,
                                             'overdue' => $row->overdue,
                                         ]) }}">
                                        <div class="flex h-full" style="width: {{ $openWidth }}%">
                                            <div class="h-full bg-critical-500" style="width: {{ $lateShare }}%"></div>
                                            <div class="h-full flex-1 bg-[var(--accent)]"></div>
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>
            @endif
        </div>
    </div>
</div>

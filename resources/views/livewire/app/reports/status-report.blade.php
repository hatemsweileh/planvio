@php
    use App\Enums\MilestoneStatus;
    use App\Services\WorkloadRow;
    use App\Support\Bidi;
    use App\Support\Formats;

    $range = $this->range;
    $progress = $this->progress;
    $counts = $this->counts;
    $health = $this->health;
    $budget = $this->budget;
    $time = $this->time;
    $team = $this->team;
    $milestones = $this->milestones;
    $omitted = $this->omittedSections();

    $donut = [
        ['label' => __('Completed'), 'value' => $progress->completed, 'color' => 'green'],
        ['label' => __('Open'), 'value' => max(0, $progress->open() - $counts['overdue']), 'color' => 'brand'],
        ['label' => __('Overdue'), 'value' => $counts['overdue'], 'color' => 'red'],
    ];

    $teamRows = array_values(array_filter($team, static fn (WorkloadRow $row): bool => $row->hasWork()));
    $offRoster = $this->offRosterNames;
@endphp

@push('head')
    <style>
        /*
            Paper is white whatever the reader's theme is.

            Two different things have to be undone, and only one of them is CSS.

            The tokens are the CSS half: panel surfaces flatten to white — a report does not
            need its cards shaded, and grey fills cost toner without carrying meaning — and
            the muted text steps a shade darker, because what reads as "quiet" on a backlit
            screen reads as "faint" on paper. They are written in terms of the same palette
            variables app.css defines, so there is no second definition of Planvio's greys.

            Status colour *does* carry meaning — the health verdict, the overdue dates — so
            print-color-adjust stops the browser's ink-saving default from flattening the
            badges and the donut into nothing.
        */
        @media print {
            :root,
            :root.dark {
                --surface-canvas: white;
                --surface-panel: white;
                --surface-raised: white;
                --surface-sunken: white;
                --surface-hover: white;
                --surface-active: var(--color-ink-200);

                --line-subtle: var(--color-ink-300);
                --line-DEFAULT: var(--color-ink-400);
                --line-strong: var(--color-ink-500);

                --text-strong: var(--color-ink-950);
                --text-DEFAULT: var(--color-ink-900);
                --text-muted: var(--color-ink-700);
                --text-subtle: var(--color-ink-600);

                --accent: var(--color-brand-700);
                --accent-soft: white;
                --accent-soft-text: var(--color-brand-800);
            }

            .print-sheet {
                color: var(--color-ink-950);
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }

            /* A row split across a page break is a row nobody can read. */
            .print-sheet tr,
            .print-sheet li {
                break-inside: avoid;
            }

            /* Section headings belong with what they introduce. */
            .print-sheet h2 {
                break-after: avoid;
            }

            .print-sheet section {
                break-inside: auto;
            }
        }
    </style>

    <script>
        (function () {
            /*
                The JavaScript half of the same problem.

                A badge's colour does not come from a token: `x-ui.badge` uses Tailwind's
                `dark:` variants, which are keyed on the `dark` class on <html> and cannot
                be undone from a print stylesheet — no rule can override an arbitrary set of
                utility classes. Under dark mode the health badge would print as pale pink
                text on white, which is the one figure on the page a reader looks for first.

                So the class is lifted for the duration of the print and put straight back.
                Both events are synchronous around the print dialog, so the page is never
                seen mid-swap, and a browser that fires neither simply prints what it always
                printed.
            */
            var root = document.documentElement;
            var restore = false;

            window.addEventListener('beforeprint', function () {
                restore = root.classList.contains('dark');
                root.classList.remove('dark');
            });

            window.addEventListener('afterprint', function () {
                if (restore) {
                    root.classList.add('dark');
                    restore = false;
                }
            });
        })();
    </script>
@endpush

<div class="mx-auto w-full max-w-4xl px-4 py-6 sm:px-8 print-sheet">

    {{-- ---------------------------------------------------------------- --}}
    {{-- Screen-only controls                                             --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="mb-5 flex flex-wrap items-center gap-2 print:hidden">
        <x-ui.button variant="ghost" size="md" :href="route('app.projects.show', [$workspace, $project])"
                     icon="icon.chevron-right" class="[&>svg]:rotate-180 [&>svg]:flip-rtl">
            {{ __('Back to project') }}
        </x-ui.button>

        <div class="ms-auto flex items-center gap-1.5">
            <label class="sr-only" for="status-range">{{ __('Reporting period') }}</label>
            <x-ui.select id="status-range" size="md" wire:model.live="preset"
                         :options="$this->presets()" class="w-40" />
            <x-ui.button variant="secondary" size="md" icon="icon.document"
                         x-on:click="window.print()">{{ __('Print') }}</x-ui.button>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- 1 · Summary                                                      --}}
    {{-- ---------------------------------------------------------------- --}}
    <header class="border-b border-[var(--line-DEFAULT)] pb-4 print-avoid-break">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-2xs font-semibold uppercase tracking-widest text-[var(--text-subtle)]">
                    {{ __('Status report') }} · {{ $workspace->name }}
                </p>
                <h1 class="mt-1 text-xl font-semibold tracking-tight text-[var(--text-strong)]">
                    {{ $project->icon ? $project->icon.' ' : '' }}{{ $project->name }}
                    <span class="font-mono text-sm font-normal text-[var(--text-subtle)]">{{ $project->display_key }}</span>
                </h1>
                <p class="mt-1 text-xs text-[var(--text-muted)]">
                    {{ __('Reporting period :from to :to', [
                        'from' => Bidi::ltr($range->fromDate()),
                        'to' => Bidi::ltr($range->toDate()),
                    ]) }}
                    · {{ __('Generated :date', ['date' => $this->today()->translatedFormat('j F Y')]) }}
                    · {{ $this->timezone() }}
                </p>
            </div>

            <div class="flex shrink-0 flex-col items-end gap-1.5">
                <x-ui.badge :color="$health->health->color()" size="lg" dot>{{ $health->health->label() }}</x-ui.badge>
                @if ($project->status)
                    <x-ui.badge :color="$project->status->color ?? 'gray'" size="sm">{{ $project->status->name }}</x-ui.badge>
                @endif
            </div>
        </div>

        <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-2 text-xs sm:grid-cols-4">
            @foreach ([
                [__('Owner'), $project->owner?->name],
                [__('Manager'), $project->manager?->name ?? __('Not set')],
                [__('Start'), Formats::date($project->start_date)],
                [__('Target'), Formats::date($project->target_date)],
                [__('Client'), $project->client_name ?: '—'],
                [__('Department'), $project->department ?: '—'],
                [__('Priority'), $project->priority?->label() ?? '—'],
                [__('Type'), $project->type?->label() ?? '—'],
            ] as [$label, $value])
                <div>
                    <dt class="text-2xs uppercase tracking-wide text-[var(--text-subtle)]">{{ $label }}</dt>
                    <dd dir="auto" class="mt-0.5 truncate text-[var(--text-DEFAULT)]">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>

        @if ($project->health_note)
            <p class="mt-3 rounded-md border border-[var(--line-subtle)] bg-[var(--surface-sunken)] px-3 py-2
                      text-xs text-[var(--text-DEFAULT)]">
                {{ $project->health_note }}
            </p>
        @endif

        @if ($omitted !== [])
            <p class="mt-3 text-2xs text-[var(--text-subtle)]">
                {{ __('Sections left out because they are outside your permissions: :sections.', [
                    'sections' => implode(', ', $omitted),
                ]) }}
            </p>
        @endif
    </header>

    {{-- ---------------------------------------------------------------- --}}
    {{-- 2 · Progress                                                     --}}
    {{-- ---------------------------------------------------------------- --}}
    <section class="mt-6 print-avoid-break" aria-labelledby="report-progress">
        <h2 id="report-progress" class="text-sm font-semibold tracking-tight text-[var(--text-strong)]">
            {{ __('Progress') }}
        </h2>

        <div class="mt-3 grid gap-4 sm:grid-cols-[minmax(0,18rem)_minmax(0,1fr)]">
            <x-chart.donut :data="$donut" :size="140" :thickness="18"
                           :centre-value="$progress->percentage.'%'"
                           :centre-label="__('complete')"
                           :label-heading="__('State')" :value-heading="__('Tasks')"
                           :caption="__('Task completion for :project', ['project' => $project->name])"
                           :empty-label="__('No task has been created in this project yet.')" />

            <div class="grid grid-cols-2 gap-2.5">
                <x-chart.stat :label="__('Tasks')" :value="Formats::number($progress->total)"
                              :hint="__(':done of :total complete', ['done' => $progress->completed, 'total' => $progress->total])" />
                <x-chart.stat :label="__('Closed this period')" :value="Formats::number($counts['completedInRange'])" tone="positive" />
                <x-chart.stat :label="__('Overdue')" :value="Formats::number($counts['overdue'])"
                              :tone="$counts['overdue'] > 0 ? 'critical' : 'neutral'" />
                <x-chart.stat :label="__('Due in 14 days')" :value="Formats::number($counts['dueSoon'])"
                              :tone="$counts['dueSoon'] > 0 ? 'caution' : 'neutral'" />
            </div>
        </div>
    </section>

    {{-- ---------------------------------------------------------------- --}}
    {{-- 3 · Completed · 4 · Upcoming · 5 · Overdue                       --}}
    {{-- ---------------------------------------------------------------- --}}
    <section class="mt-6" aria-labelledby="report-completed">
        <h2 id="report-completed" class="text-sm font-semibold tracking-tight text-[var(--text-strong)]">
            {{ __('Completed in this period') }}
            <span class="ms-1 text-xs font-normal text-[var(--text-muted)]">({{ $this->completed->count() }})</span>
        </h2>
        <div class="mt-2 overflow-hidden rounded-lg border border-[var(--line-subtle)] bg-[var(--surface-panel)]">
            @include('livewire.app.reports._tasks', [
                'tasks' => $this->completed,
                'dateField' => 'completed_at',
                'tone' => 'neutral',
                'emptyText' => __('Nothing was closed between :from and :to.', [
                    'from' => Bidi::ltr($range->fromDate()),
                    'to' => Bidi::ltr($range->toDate()),
                ]),
            ])
        </div>
    </section>

    <section class="mt-5" aria-labelledby="report-upcoming">
        <h2 id="report-upcoming" class="text-sm font-semibold tracking-tight text-[var(--text-strong)]">
            {{ __('Coming up in the next 14 days') }}
            <span class="ms-1 text-xs font-normal text-[var(--text-muted)]">({{ $this->upcoming->count() }})</span>
        </h2>
        <div class="mt-2 overflow-hidden rounded-lg border border-[var(--line-subtle)] bg-[var(--surface-panel)]">
            @include('livewire.app.reports._tasks', [
                'tasks' => $this->upcoming,
                'dateField' => 'due_date',
                'tone' => 'neutral',
                'emptyText' => __('Nothing is scheduled in the next two weeks.'),
            ])
        </div>
    </section>

    <section class="mt-5" aria-labelledby="report-overdue">
        <h2 id="report-overdue" class="text-sm font-semibold tracking-tight text-[var(--text-strong)]">
            {{ __('Overdue') }}
            <span class="ms-1 text-xs font-normal text-[var(--text-muted)]">({{ $counts['overdue'] }})</span>
        </h2>
        <div class="mt-2 overflow-hidden rounded-lg border border-[var(--line-subtle)] bg-[var(--surface-panel)]">
            @include('livewire.app.reports._tasks', [
                'tasks' => $this->overdue,
                'dateField' => 'due_date',
                'tone' => 'critical',
                'emptyText' => __('Nothing is past its due date.'),
            ])
        </div>
        @if ($counts['overdue'] > $this->overdue->count())
            <p class="mt-1.5 text-2xs text-[var(--text-subtle)]">
                {{ __('Showing the :shown oldest of :total overdue tasks.', [
                    'shown' => $this->overdue->count(),
                    'total' => $counts['overdue'],
                ]) }}
            </p>
        @endif
    </section>

    {{-- ---------------------------------------------------------------- --}}
    {{-- 6 · Milestones                                                   --}}
    {{-- ---------------------------------------------------------------- --}}
    <section class="mt-6 print-avoid-break" aria-labelledby="report-milestones">
        <h2 id="report-milestones" class="text-sm font-semibold tracking-tight text-[var(--text-strong)]">
            {{ __('Milestones') }}
        </h2>
        <div class="mt-2 overflow-hidden rounded-lg border border-[var(--line-subtle)] bg-[var(--surface-panel)]">
            @if ($milestones->isEmpty())
                <p class="px-4 py-3 text-xs text-[var(--text-subtle)]">
                    {{ __('This project has no milestones.') }}
                </p>
            @else
                <table class="w-full text-xs">
                    <thead>
                        <tr class="border-b border-[var(--line-subtle)] text-start text-2xs uppercase tracking-wider text-[var(--text-muted)]">
                            <th scope="col" class="px-4 py-2 font-semibold">{{ __('Milestone') }}</th>
                            <th scope="col" class="px-3 py-2 font-semibold">{{ __('Status') }}</th>
                            <th scope="col" class="px-3 py-2 font-semibold">{{ __('Due') }}</th>
                            <th scope="col" class="w-32 px-4 py-2 font-semibold">{{ __('Progress') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--line-subtle)]">
                        @foreach ($milestones as $milestone)
                            <tr class="print-avoid-break">
                                <th dir="auto" scope="row" class="max-w-64 truncate px-4 py-1.5 text-start font-medium text-[var(--text-strong)]">
                                    {{ $milestone->name }}
                                </th>
                                <td class="px-3 py-1.5">
                                    <x-ui.badge :color="$milestone->status?->color() ?? 'gray'" size="sm" dot>
                                        {{ $milestone->status?->label() }}
                                    </x-ui.badge>
                                </td>
                                <td class="whitespace-nowrap px-3 py-1.5 tabular-nums {{ $milestone->status?->isOpen() && $milestone->due_date?->format('Y-m-d') < $this->today()->toDateString() ? 'font-semibold text-critical-600 dark:text-critical-500' : 'text-[var(--text-muted)]' }}">
                                    {{ Formats::date($milestone->due_date) }}
                                </td>
                                <td class="px-4 py-1.5">
                                    <x-ui.progress :value="$milestone->progress" size="sm" show-label
                                                   :label="__('Progress of :milestone', ['milestone' => $milestone->name])" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </section>

    {{-- ---------------------------------------------------------------- --}}
    {{-- 7 · Risks                                                        --}}
    {{-- ---------------------------------------------------------------- --}}
    <section class="mt-6 print-avoid-break" aria-labelledby="report-risks">
        <h2 id="report-risks" class="text-sm font-semibold tracking-tight text-[var(--text-strong)]">
            {{ __('Risks and signals') }}
        </h2>
        <p class="mt-0.5 text-2xs text-[var(--text-muted)]">
            {{ __('Calculated by Planvio from the counts and dates above. This is analysis, not a recorded figure.') }}
        </p>

        <div class="mt-2 rounded-lg border border-[var(--line-subtle)] bg-[var(--surface-panel)] px-4 py-3">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs text-[var(--text-muted)]">{{ __('Calculated verdict') }}</span>
                <x-ui.badge :color="$health->computed->color()" size="sm" dot>{{ $health->computed->label() }}</x-ui.badge>
                @if ($health->manual)
                    <span class="text-xs text-[var(--text-muted)]">
                        · {{ __('recorded health was set by hand to :health', ['health' => $health->health->label()]) }}
                    </span>
                @endif
            </div>

            @if ($health->reasons === [])
                <p class="mt-2 text-xs text-[var(--text-subtle)]">
                    {{ __('No signal is currently firing: no overdue backlog, no delayed milestone, no deadline in reach that the open work cannot meet.') }}
                </p>
            @else
                <ul class="mt-2 space-y-1.5">
                    @foreach ($health->reasons as $reason)
                        <li class="flex items-start gap-2 text-xs">
                            <span class="mt-1.5 size-1.5 shrink-0 rounded-full
                                         {{ ($reason['severity'] ?? '') === 'off_track' ? 'bg-critical-500' : 'bg-caution-500' }}"
                                  aria-hidden="true"></span>
                            <span class="text-[var(--text-DEFAULT)]">
                                <span class="font-medium">{{ $this->healthReasonLabel((string) ($reason['code'] ?? '')) }}</span>
                                @if (isset($reason['count'], $reason['open_tasks']))
                                    — {{ __(':count of :open open tasks', ['count' => $reason['count'], 'open' => $reason['open_tasks']]) }}
                                @elseif (isset($reason['count']))
                                    — {{ $reason['count'] }}
                                @endif
                                @if (! empty($reason['oldest_due_date']))
                                    <span class="text-[var(--text-muted)]">· {{ __('oldest since :date', ['date' => $reason['oldest_due_date']]) }}</span>
                                @endif
                                @if (! empty($reason['target_date']))
                                    <span class="text-[var(--text-muted)]">· {{ __('target :date', ['date' => $reason['target_date']]) }}</span>
                                @endif
                                @if (isset($reason['days_overdue']))
                                    <span class="text-[var(--text-muted)]">· {{ trans_choice('{1}:count day past|[2,*]:count days past', (int) $reason['days_overdue'], ['count' => (int) $reason['days_overdue']]) }}</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>

    {{-- ---------------------------------------------------------------- --}}
    {{-- 8 · Team                                                         --}}
    {{-- ---------------------------------------------------------------- --}}
    <section class="mt-6 print-avoid-break" aria-labelledby="report-team">
        <h2 id="report-team" class="text-sm font-semibold tracking-tight text-[var(--text-strong)]">{{ __('Team') }}</h2>
        <div class="mt-2 overflow-hidden rounded-lg border border-[var(--line-subtle)] bg-[var(--surface-panel)]">
            @if ($teamRows === [])
                <p class="px-4 py-3 text-xs text-[var(--text-subtle)]">
                    {{ __('Nobody on this project is holding any work.') }}
                </p>
            @else
                <table class="w-full text-xs">
                    <thead>
                        <tr class="border-b border-[var(--line-subtle)] text-start text-2xs uppercase tracking-wider text-[var(--text-muted)]">
                            <th scope="col" class="px-4 py-2 font-semibold">{{ __('Person') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-semibold">{{ __('Open') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-semibold">{{ __('Overdue') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-semibold">{{ __('Completed') }}</th>
                            <th scope="col" class="px-4 py-2 text-end font-semibold">{{ __('Estimated') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--line-subtle)]">
                        @foreach ($teamRows as $row)
                            <tr class="print-avoid-break">
                                <th scope="row" class="px-4 py-1.5 text-start font-medium text-[var(--text-strong)]">
                                    {{ $row->isUnassigned()
                                        ? __('Unassigned')
                                        : ($row->userName ?? $offRoster[$row->userId] ?? __('Former member')) }}
                                </th>
                                <td class="px-3 py-1.5 text-end tabular-nums">{{ $row->open }}</td>
                                <td class="px-3 py-1.5 text-end tabular-nums {{ $row->overdue > 0 ? 'font-semibold text-critical-600 dark:text-critical-500' : 'text-[var(--text-muted)]' }}">
                                    {{ $row->overdue }}
                                </td>
                                <td class="px-3 py-1.5 text-end tabular-nums text-[var(--text-muted)]">{{ $row->completed }}</td>
                                <td class="px-4 py-1.5 text-end tabular-nums text-[var(--text-muted)]">
                                    {{ $row->estimateMinutes > 0 ? $this->hours($row->estimateMinutes) : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </section>

    {{-- ---------------------------------------------------------------- --}}
    {{-- 9 · Budget                                                       --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($budget)
        <section class="mt-6 print-avoid-break" aria-labelledby="report-budget">
            <h2 id="report-budget" class="text-sm font-semibold tracking-tight text-[var(--text-strong)]">{{ __('Budget') }}</h2>
            <div class="mt-2 grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                <x-chart.stat :label="__('Planned')" :value="($budget->planned() ?? '—').' '.$budget->currency" />
                <x-chart.stat :label="__('Spent')" :value="$budget->actual().' '.$budget->currency"
                              :hint="trans_choice('{0}No expense recorded|{1}:count expense|[2,*]:count expenses', $budget->expenseCount, ['count' => $budget->expenseCount])" />
                <x-chart.stat :label="__('Variance')" :value="($budget->variance() ?? '—').' '.$budget->currency"
                              :tone="$budget->isOverBudget() ? 'critical' : 'neutral'" />
                <x-chart.stat :label="__('Used')"
                              :value="$budget->utilisation() === null ? '—' : round($budget->utilisation()).'%'"
                              :tone="$budget->isOverBudget() ? 'critical' : 'neutral'" />
            </div>

            @if ($budget->hasUnconvertedCosts())
                <p class="mt-2 text-2xs text-[var(--text-muted)]">
                    {{ __('Also booked, in other currencies and not converted: :amounts.', [
                        'amounts' => collect($budget->unconvertedAmounts())->map(fn ($amount, $code) => $amount.' '.$code)->implode(', '),
                    ]) }}
                </p>
            @endif

            <p class="mt-2 text-2xs text-[var(--text-subtle)]">
                {{ __('Spend is the sum of expenses booked to this project. Labour is not costed: Planvio holds no rate to cost it with, and an invented one would read as a figure.') }}
            </p>
        </section>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- 10 · Time                                                        --}}
    {{-- ---------------------------------------------------------------- --}}
    <section class="mt-6 print-avoid-break" aria-labelledby="report-time">
        <h2 id="report-time" class="text-sm font-semibold tracking-tight text-[var(--text-strong)]">{{ __('Time') }}</h2>
        @if ($time['own'])
            <p class="mt-0.5 text-2xs text-[var(--text-muted)]">{{ __('Your own hours only.') }}</p>
        @endif

        <div class="mt-2 grid grid-cols-2 gap-2.5 sm:grid-cols-3">
            <x-chart.stat :label="__('Logged')" :value="$this->hours($time['total'])" />
            <x-chart.stat :label="__('Billable')" :value="$this->hours($time['billable'])" tone="positive" />
            <x-chart.stat :label="__('Non-billable')" :value="$this->hours(max(0, $time['total'] - $time['billable']))" />
        </div>

        @if ($time['byUser'] !== [])
            <div class="mt-3">
                <x-chart.bar orientation="horizontal"
                             :data="collect($time['byUser'])->take(12)->map(fn ($row) => [
                                'label' => $row->label ?? __('Unknown'),
                                'value' => $row->minutes,
                                'display' => $this->hours($row->minutes),
                                'color' => 'brand',
                             ])->values()->all()"
                             :label-heading="__('Person')" :value-heading="__('Hours')"
                             :caption="__('Hours logged on :project by person', ['project' => $project->name])" />
            </div>
        @endif
    </section>

    {{-- ---------------------------------------------------------------- --}}
    {{-- The AI paragraph — fenced off, labelled, and after the figures   --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($this->aiAvailable())
        <section class="mt-8 print-avoid-break" aria-labelledby="report-ai">
            <div class="rounded-lg border-2 border-dashed border-accent-500/50 bg-accent-50/50 p-4
                        dark:bg-accent-500/10">
                <div class="flex flex-wrap items-center gap-2">
                    <x-icon.sparkles class="size-4 text-accent-600 dark:text-accent-100" />
                    <h2 id="report-ai" class="text-sm font-semibold text-[var(--text-strong)]">
                        {{ __('Executive summary') }}
                    </h2>
                    <x-ui.badge color="purple" size="sm">{{ __('Written by AI') }}</x-ui.badge>
                </div>

                @if ($summary = $this->aiSummary)
                    <p class="mt-2 whitespace-pre-line text-sm leading-relaxed text-[var(--text-DEFAULT)]">
                        {{ $summary->summary }}
                    </p>
                    <p class="mt-2 text-2xs text-[var(--text-muted)]">
                        {{ __('Generated :date', ['date' => Bidi::ltr(Formats::dateTime($summary->finished_at))]) }}
                        @if ($summary->model)
                            · {{ $summary->model }}
                        @endif
                        · {{ __('Not a recorded figure. Every number above comes from the project data; this paragraph is a model\'s reading of it.') }}
                    </p>
                @else
                    <p class="mt-2 text-xs text-[var(--text-muted)]">
                        {{ __('No AI summary has been written for this project yet. Ask Planvio AI for one and it will appear here, dated and attributed.') }}
                    </p>
                    <div class="mt-3 print:hidden">
                        <x-ui.button variant="secondary" size="sm" icon="icon.sparkles"
                                     :href="route('app.projects.ai', [$workspace, $project])">
                            {{ __('Ask Planvio AI') }}
                        </x-ui.button>
                    </div>
                @endif
            </div>
        </section>
    @endif

    <footer class="mt-8 border-t border-[var(--line-subtle)] pt-3 text-2xs text-[var(--text-subtle)]">
        {{ __(':app · :workspace · :project · generated :date', [
            'app' => app(\App\Support\Branding::class)->name(),
            'workspace' => $workspace->name,
            'project' => $project->name,
            'date' => $this->today()->toDateString(),
        ]) }}
    </footer>
</div>

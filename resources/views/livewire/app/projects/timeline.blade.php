@php
    $chart = $this->timeline;
    $paginator = $this->rowPaginator();
    $rowHeight = $chart['rowHeight'];
    $bodyHeight = max($rowHeight, count($chart['rows']) * $rowHeight);
    $labelWidth = 224;

    /*
     * Alt + ← and Alt + → nudge a focused bar by a day, and what a nudge means is the way
     * the bar visibly moves, not the sign of the arithmetic behind it. Later work is to the
     * left of earlier work in an RTL page, so the two keys trade places there; leaving them
     * alone would have the bar walk away from the arrow being pressed.
     */
    $arrowLeftDays = $chart['rtl'] ? 1 : -1;
    $arrowRightDays = $chart['rtl'] ? -1 : 1;
@endphp

<div class="flex h-full min-h-0 flex-col">

    {{-- ---------------------------------------------------------------- --}}
    {{-- Header                                                           --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="flex flex-wrap items-center gap-2 border-b border-[var(--line-subtle)]
                bg-[var(--surface-panel)] px-3 py-2 sm:px-4">
        <h1 dir="auto" class="min-w-0 flex-1 truncate text-sm font-semibold tracking-tight text-[var(--text-strong)]">
            <a href="{{ route('app.projects.show', [$workspace, $project]) }}"
               class="hover:text-[var(--accent)]">{{ $project->name }}</a>
            <span class="text-[var(--text-subtle)]">/</span>
            <span class="font-normal text-[var(--text-muted)]">{{ __('Timeline') }}</span>
        </h1>

        <div wire:loading.delay class="text-[var(--text-subtle)]"
             wire:target="setZoom,groupBy,includeCompleted,gotoPage,nextPage,previousPage,shiftTask,reschedule">
            <x-ui.spinner class="size-4" />
        </div>

        <x-ui.tabs variant="pill" class="shrink-0" :aria-label="__('Zoom')">
            @foreach ([['day', __('Day')], ['week', __('Week')], ['month', __('Month')], ['quarter', __('Quarter')]] as [$value, $label])
                <x-ui.tab variant="pill" :active="$zoom === $value" wire:click="setZoom('{{ $value }}')"
                          wire:key="zoom-{{ $value }}">{{ $label }}</x-ui.tab>
            @endforeach
        </x-ui.tabs>

        <label class="sr-only" for="timeline-group">{{ __('Group rows by') }}</label>
        <x-ui.select id="timeline-group" size="sm" class="w-36" wire:model.live="groupBy"
                     :options="['none' => __('No grouping'), 'milestone' => __('By milestone'), 'assignee' => __('By assignee')]" />

        <x-ui.checkbox wire:model.live="includeCompleted" :label="__('Show completed')" class="shrink-0" />
    </div>

    {{-- Legend and notices --}}
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-[var(--line-subtle)]
                bg-[var(--surface-sunken)] px-3 py-1.5 text-2xs text-[var(--text-muted)] sm:px-4">
        <span class="inline-flex items-center gap-1.5">
            <span class="h-2 w-4 rounded-sm" style="background-color: var(--color-accent-500)"></span>
            {{ __('Milestone') }}
        </span>
        <span class="inline-flex items-center gap-1.5">
            <span class="h-2 w-4 rounded-sm" style="background-color: var(--color-brand-500)"></span>
            {{ __('Task') }}
        </span>
        <span class="inline-flex items-center gap-1.5">
            <span class="h-2 w-4 rounded-sm" style="background-color: var(--color-positive-500)"></span>
            {{ __('Completed') }}
        </span>
        <span class="inline-flex items-center gap-1.5">
            {{-- A dependency arrow points onward along the timeline, so the sample of one
                 mirrors with the timeline it is explaining. --}}
            <svg width="26" height="8" viewBox="0 0 26 8" aria-hidden="true" class="flip-rtl overflow-visible">
                <path d="M0 4 H20" stroke="var(--line-strong)" stroke-width="1.5" fill="none" />
                <path d="M20 1.5 L24 4 L20 6.5 Z" fill="var(--line-strong)" />
            </svg>
            {{ __('Depends on') }}
        </span>
        <span class="inline-flex items-center gap-1.5">
            <span class="h-3 w-px" style="background-color: var(--color-critical-500)"></span>
            {{ __('Today') }}
        </span>
        <span class="ms-auto tabular-nums">
            {{ trans_choice('{0}No dated task|{1}:count task|[2,*]:count tasks', $chart['totalTasks'], ['count' => $chart['totalTasks']]) }}
            @if ($chart['undated'] > 0)
                <span class="text-[var(--text-subtle)]">
                    · {{ trans_choice('{1}:count without dates|[2,*]:count without dates', $chart['undated'], ['count' => $chart['undated']]) }}
                </span>
            @endif
        </span>
    </div>

    @if ($chart['clamped'])
        <p class="border-b border-[var(--line-subtle)] bg-caution-50 px-4 py-1.5 text-2xs
                  text-caution-700 dark:bg-caution-950 dark:text-caution-100">
            {{ __('This project runs beyond what :zoom zoom can draw. Showing :from to :to — zoom out to see the rest.', [
                'zoom' => $zoom,
                'from' => $chart['start'],
                'to' => $chart['end'],
            ]) }}
        </p>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Chart                                                            --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($chart['rows'] === [])
        <div class="min-h-0 flex-1 overflow-y-auto">
            <x-ui.empty-state icon="icon.timeline"
                              :title="__('Nothing to plot yet')"
                              :description="__('Tasks with a start or a due date form the bars on this timeline, milestones sit above them, and declared dependencies draw the arrows between them.')">
                <x-slot:actions>
                    @can('task.create', $project)
                        <x-ui.button variant="primary" size="md" icon="icon.plus"
                                     x-on:click="$dispatch('open-quick-create', { type: 'task', project: {{ $project->getKey() }} })">
                            {{ __('New task') }}
                        </x-ui.button>
                    @endcan
                    @if (! $includeCompleted)
                        <x-ui.button variant="secondary" size="md" wire:click="$set('includeCompleted', true)">
                            {{ __('Include completed work') }}
                        </x-ui.button>
                    @endif
                    <x-ui.button variant="ghost" size="md" :href="route('app.projects.tasks', [$workspace, $project])">
                        {{ __('Open the task list') }}
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.empty-state>
        </div>
    @else
        <div class="scrollbar-thin min-h-0 flex-1 overflow-auto"
             wire:loading.class="opacity-60"
             wire:target="setZoom,groupBy,includeCompleted,gotoPage,nextPage,previousPage">

            <div class="relative w-max min-w-full">

                {{-- Axis header. Sticky to the top of the scroller; its row-label cell is
                     stuck to the inline start of it — the left edge in English, the right
                     edge in Arabic — so both survive scrolling in either direction. --}}
                <div class="sticky top-0 z-30 flex border-b border-[var(--line-DEFAULT)]
                            bg-[var(--surface-panel)]">
                    <div class="sticky start-0 z-40 shrink-0 border-e border-[var(--line-DEFAULT)]
                                bg-[var(--surface-panel)] px-3 py-1.5"
                         style="width: {{ $labelWidth }}px">
                        <p class="text-2xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                            {{ __('Rows') }}
                        </p>
                        <p class="text-2xs text-[var(--text-subtle)]">{{ $this->timezone() }}</p>
                    </div>

                    {{--
                        The plot reads the way the page reads. Every offset the component
                        produces — these bands and ticks, the bars below, the today marker —
                        is measured from the *inline start* of the chart and spent on
                        `inset-inline-start`, so the browser picks the edge: the first day of
                        the range sits on the left in English and on the right in Arabic,
                        and time runs onward from it in both. That also makes the logical
                        utilities in here (border-s, start-0, rounded-s-none) mean what they
                        say — the start of a band is the start of its dates.

                        The one thing that cannot work this way is the dependency overlay,
                        because an SVG x is measured from the left of the viewBox whatever
                        the page does. Those paths are reflected in the component instead.
                    --}}
                    <div class="relative shrink-0" style="width: {{ $chart['width'] }}px">
                        <div class="relative h-6 border-b border-[var(--line-subtle)]">
                            @foreach ($chart['tiers']['top'] as $band)
                                <div class="absolute inset-y-0 flex items-center overflow-hidden border-s
                                            border-[var(--line-subtle)] px-1.5 text-2xs font-semibold
                                            text-[var(--text-DEFAULT)]"
                                     style="inset-inline-start: {{ $band['left'] }}px; width: {{ $band['width'] }}px">
                                    <span class="truncate">{{ $band['narrow'] ? '' : $band['label'] }}</span>
                                </div>
                            @endforeach
                        </div>
                        <div class="relative h-6">
                            @foreach ($chart['tiers']['bottom'] as $tick)
                                <div class="absolute inset-y-0 flex items-center justify-center overflow-hidden
                                            border-s border-[var(--line-subtle)] text-2xs tabular-nums
                                            {{ $tick['today'] ? 'font-semibold text-[var(--accent)]' : ($tick['weekend'] ? 'text-[var(--text-subtle)]' : 'text-[var(--text-muted)]') }}"
                                     style="inset-inline-start: {{ $tick['left'] }}px; width: {{ $tick['width'] }}px">
                                    <span class="truncate px-0.5">{{ $tick['label'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Body --}}
                <div class="flex">
                    {{-- Row headers --}}
                    <div class="sticky start-0 z-20 shrink-0 border-e border-[var(--line-DEFAULT)]
                                bg-[var(--surface-panel)]"
                         style="width: {{ $labelWidth }}px">
                        @foreach ($chart['rows'] as $row)
                            <div class="flex items-center gap-1.5 border-b border-[var(--line-subtle)] px-3
                                        {{ $row['kind'] === 'group' ? 'bg-[var(--surface-sunken)]' : '' }}"
                                 style="height: {{ $rowHeight }}px"
                                 wire:key="label-{{ $row['kind'] }}-{{ $row['id'] }}-{{ $loop->index }}">

                                @if ($row['kind'] === 'group')
                                    <span dir="auto" class="truncate text-2xs font-semibold uppercase tracking-wider
                                                 text-[var(--text-muted)]">{{ $row['label'] }}</span>
                                @elseif ($row['kind'] === 'milestone')
                                    <x-icon.flag class="size-3.5 shrink-0" style="color: {{ $row['color'] }}" />
                                    <span dir="auto" class="truncate text-xs font-medium text-[var(--text-strong)]">{{ $row['label'] }}</span>
                                @else
                                    <span class="shrink-0 font-mono text-[10px] text-[var(--text-subtle)]"><x-ui.bidi>{{ $row['reference'] }}</x-ui.bidi></span>
                                    {{-- dir="auto": the ellipsis has to fall at the end of the
                                         title, which is not the end of the row when the two differ. --}}
                                    <a href="{{ $row['href'] }}"
                                       dir="auto"
                                       class="min-w-0 flex-1 truncate text-xs text-[var(--text-DEFAULT)] hover:text-[var(--accent)]
                                              {{ ($row['completed'] ?? false) ? 'line-through decoration-[var(--line-strong)]' : '' }}">{{ $row['label'] }}</a>
                                    @if ($row['assignee'] ?? null)
                                        <x-ui.avatar :user="$row['assignee']" size="xs" class="shrink-0" />
                                    @endif
                                @endif
                            </div>
                        @endforeach
                    </div>

                    {{-- Bars. Same frame as the axis above them, and the same inline-start
                         offsets, so a bar always lines up with the day it names. --}}
                    <div class="relative shrink-0" style="width: {{ $chart['width'] }}px; height: {{ $bodyHeight }}px">

                        {{-- Vertical guides, one layer under everything else. --}}
                        <div class="pointer-events-none absolute inset-0" aria-hidden="true">
                            @foreach ($chart['tiers']['bottom'] as $tick)
                                <div class="absolute inset-y-0 border-s border-[var(--line-subtle)]
                                            {{ $tick['weekend'] ? 'bg-[var(--surface-sunken)]' : '' }}"
                                     style="inset-inline-start: {{ $tick['left'] }}px; width: {{ $tick['width'] }}px"></div>
                            @endforeach
                        </div>

                        {{-- Rows --}}
                        @foreach ($chart['rows'] as $row)
                            <div class="absolute inset-x-0 border-b border-[var(--line-subtle)]
                                        {{ $row['kind'] === 'group' ? 'bg-[var(--surface-sunken)]' : '' }}"
                                 style="top: {{ $loop->index * $rowHeight }}px; height: {{ $rowHeight }}px"
                                 wire:key="bar-{{ $row['kind'] }}-{{ $row['id'] }}-{{ $loop->index }}">

                                @if ($row['bar'])
                                    @php
                                        $bar = $row['bar'];
                                        $progress = max(0, min(100, $row['progress']));
                                        $title = $bar['from'] === $bar['to']
                                            ? $row['label'].' · '.$bar['from']
                                            : $row['label'].' · '.$bar['from'].' → '.$bar['to'];
                                    @endphp

                                    @if ($row['kind'] === 'task')
                                        <button type="button"
                                                wire:click="openTask({{ $row['id'] }})"
                                                x-on:keydown.alt.arrow-left.prevent="$wire.shiftTask({{ $row['id'] }}, {{ $arrowLeftDays }})"
                                                x-on:keydown.alt.arrow-right.prevent="$wire.shiftTask({{ $row['id'] }}, {{ $arrowRightDays }})"
                                                title="{{ $title }}"
                                                class="group absolute top-1/2 flex h-4 -translate-y-1/2 items-center
                                                       overflow-hidden rounded-sm text-start transition-[filter] hover:brightness-95
                                                       focus-visible:outline-2 focus-visible:outline-offset-1
                                                       focus-visible:outline-[var(--accent)]
                                                       {{ $bar['clippedStart'] ? 'rounded-s-none' : '' }}
                                                       {{ $bar['clippedEnd'] ? 'rounded-e-none' : '' }}"
                                                style="inset-inline-start: {{ $bar['left'] }}px; width: {{ $bar['width'] }}px;
                                                       background-color: color-mix(in oklab, {{ $row['color'] }} 26%, transparent);
                                                       box-shadow: inset 0 0 0 1px color-mix(in oklab, {{ $row['color'] }} 55%, transparent);">
                                            <span class="absolute inset-y-0 start-0"
                                                  style="width: {{ $progress }}%; background-color: {{ $row['color'] }}"
                                                  aria-hidden="true"></span>
                                            @if ($bar['width'] > 70)
                                                <span dir="auto" class="relative z-10 truncate px-1.5 text-[10px] font-medium text-[var(--text-strong)]">
                                                    {{ $row['label'] }}
                                                </span>
                                            @endif
                                            <span class="sr-only">{{ $title }}</span>
                                        </button>
                                    @else
                                        <div title="{{ $title }}"
                                             class="absolute top-1/2 h-3 -translate-y-1/2 rounded-sm"
                                             style="inset-inline-start: {{ $bar['left'] }}px; width: {{ $bar['width'] }}px;
                                                    background-color: color-mix(in oklab, {{ $row['color'] }} 30%, transparent);
                                                    box-shadow: inset 0 0 0 1px color-mix(in oklab, {{ $row['color'] }} 60%, transparent);">
                                            <span class="absolute inset-y-0 start-0 rounded-sm"
                                                  style="width: {{ $progress }}%; background-color: {{ $row['color'] }}"></span>
                                            <span class="absolute -end-1 top-1/2 size-2 -translate-y-1/2 rotate-45"
                                                  style="background-color: {{ $row['color'] }}"></span>
                                        </div>
                                    @endif
                                @endif
                            </div>
                        @endforeach

                        {{-- Dependency overlay. Above the bars so an arrow is never hidden by
                             one, and inert to the pointer so it never eats a click.

                             Pinned to its own left-to-right coordinate frame, because that
                             is what an SVG x means. The paths arrive already reflected for
                             an RTL page, and the arrowheads follow: orient="auto" takes its
                             angle from the path it is on. --}}
                        @if ($chart['dependencies'] !== [])
                            <svg dir="ltr" class="pointer-events-none absolute inset-0 z-10"
                                 width="{{ $chart['width'] }}" height="{{ $bodyHeight }}"
                                 viewBox="0 0 {{ $chart['width'] }} {{ $bodyHeight }}"
                                 aria-hidden="true" focusable="false">
                                <defs>
                                    <marker id="planvio-arrow" viewBox="0 0 6 6" refX="5" refY="3"
                                            markerWidth="5" markerHeight="5" orient="auto">
                                        <path d="M0 0 L6 3 L0 6 Z" fill="var(--line-strong)" />
                                    </marker>
                                </defs>
                                @foreach ($chart['dependencies'] as $edge)
                                    <path d="{{ $edge['path'] }}"
                                          fill="none"
                                          stroke="var(--line-strong)"
                                          stroke-width="1.25"
                                          @if ($edge['dashed']) stroke-dasharray="3 3" @endif
                                          marker-end="url(#planvio-arrow)" />
                                @endforeach
                            </svg>
                        @endif

                        {{-- Today --}}
                        @if ($chart['todayLeft'] !== null)
                            <div class="pointer-events-none absolute inset-y-0 z-20 w-px"
                                 style="inset-inline-start: {{ $chart['todayLeft'] }}px; background-color: var(--color-critical-500)"
                                 aria-hidden="true"></div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- The same rows as numbers. A Gantt is a picture of dates, and a picture is not
             something a screen reader, a text browser or a printer without colour can read;
             the bars carry `title` attributes, and this carries the whole schedule. --}}
        <table class="sr-only">
            <caption>{{ __('Schedule for :project, :from to :to', [
                'project' => $project->name,
                'from' => $chart['start'],
                'to' => $chart['end'],
            ]) }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ __('Row') }}</th>
                    <th scope="col">{{ __('Kind') }}</th>
                    <th scope="col">{{ __('Start') }}</th>
                    <th scope="col">{{ __('End') }}</th>
                    <th scope="col">{{ __('Progress') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($chart['rows'] as $row)
                    @continue($row['kind'] === 'group')
                    <tr>
                        <th scope="row">@if ($row['reference'] ?? null)<x-ui.bidi>{{ $row['reference'] }}</x-ui.bidi> @endif{{ $row['label'] }}</th>
                        <td>{{ $row['kind'] === 'milestone' ? __('Milestone') : __('Task') }}</td>
                        <td>{{ $row['bar']['from'] ?? __('Not scheduled') }}</td>
                        <td>{{ $row['bar']['to'] ?? __('Not scheduled') }}</td>
                        <td>{{ $row['progress'] }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($paginator)
            <div class="flex items-center justify-between gap-3 border-t border-[var(--line-subtle)]
                        bg-[var(--surface-panel)] px-3 py-2 sm:px-4">
                <p class="text-2xs tabular-nums text-[var(--text-muted)]">
                    {{ __('Rows :from–:to of :total', [
                        'from' => $paginator->total() === 0 ? 0 : $paginator->firstItem(),
                        'to' => $paginator->lastItem() ?? 0,
                        'total' => $paginator->total(),
                    ]) }}
                </p>
                @if ($paginator->hasPages())
                    <div class="flex items-center gap-1">
                        <x-ui.button variant="secondary" size="sm" wire:click="previousPage"
                                     :disabled="$paginator->onFirstPage()">{{ __('Previous') }}</x-ui.button>
                        <span class="px-1 text-2xs tabular-nums text-[var(--text-muted)]">
                            {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}
                        </span>
                        <x-ui.button variant="secondary" size="sm" wire:click="nextPage"
                                     :disabled="! $paginator->hasMorePages()">{{ __('Next') }}</x-ui.button>
                    </div>
                @endif
            </div>
        @endif
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Reschedule panel                                                 --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.drawer wire:model="panelOpen" size="sm" :title="__('Reschedule')">
        @if ($task = $this->panelTask)
            <header class="flex items-start justify-between gap-3 border-b border-[var(--line-subtle)] px-4 py-3">
                <div class="min-w-0">
                    <p class="font-mono text-2xs text-[var(--text-subtle)]"><x-ui.bidi>{{ $task->key }}</x-ui.bidi></p>
                    <h2 class="mt-0.5 text-sm font-semibold leading-snug text-[var(--text-strong)]">{{ $task->title }}</h2>
                </div>
                <x-ui.button variant="ghost" size="sm" icon-only wire:click="closeTask" :aria-label="__('Close')">
                    <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75">
                        <path d="m5 5 10 10M15 5 5 15" stroke-linecap="round"/>
                    </svg>
                </x-ui.button>
            </header>

            <div class="scrollbar-thin min-h-0 flex-1 overflow-y-auto px-4 py-3">
                <div class="flex flex-wrap items-center gap-1.5">
                    @if ($task->status)
                        <x-ui.badge :color="$task->status->color ?? 'gray'" dot>{{ $task->status->name }}</x-ui.badge>
                    @endif
                    @if ($task->priority)
                        <x-ui.badge :color="$task->priority->color()">{{ $task->priority->label() }}</x-ui.badge>
                    @endif
                    @if ($task->milestone)
                        <x-ui.badge color="purple" icon="icon.flag">{{ $task->milestone->name }}</x-ui.badge>
                    @endif
                </div>

                <x-ui.progress :value="$task->progress" show-label class="mt-3" :label="__('Task progress')" />

                @can('update', $task)
                    <div class="mt-4 space-y-2.5 rounded-lg border border-[var(--line-subtle)]
                                bg-[var(--surface-sunken)] p-3">
                        <x-ui.field :label="__('Start date')" for="timeline-start">
                            <x-ui.input id="timeline-start" type="date" size="sm" wire:model="rescheduleStart" />
                        </x-ui.field>
                        <x-ui.field :label="__('Due date')" for="timeline-due">
                            <x-ui.input id="timeline-due" type="date" size="sm" wire:model="rescheduleDue" />
                        </x-ui.field>

                        <div class="flex items-center justify-between gap-2 pt-0.5">
                            <div class="flex items-center gap-1">
                                <x-ui.button variant="ghost" size="xs" wire:click="shiftTask({{ $task->getKey() }}, -7)">
                                    {{ __('−1 week') }}
                                </x-ui.button>
                                <x-ui.button variant="ghost" size="xs" wire:click="shiftTask({{ $task->getKey() }}, -1)">
                                    {{ __('−1 day') }}
                                </x-ui.button>
                                <x-ui.button variant="ghost" size="xs" wire:click="shiftTask({{ $task->getKey() }}, 1)">
                                    {{ __('+1 day') }}
                                </x-ui.button>
                                <x-ui.button variant="ghost" size="xs" wire:click="shiftTask({{ $task->getKey() }}, 7)">
                                    {{ __('+1 week') }}
                                </x-ui.button>
                            </div>
                            <x-ui.button variant="primary" size="sm" wire:click="reschedule">{{ __('Save dates') }}</x-ui.button>
                        </div>

                        <p class="text-2xs text-[var(--text-subtle)]">
                            {{ __('The relative buttons move both ends together, so the bar keeps its length. Alt + ← and Alt + → do the same thing on a focused bar.') }}
                        </p>
                    </div>
                @endcan
            </div>

            <footer class="flex items-center justify-between gap-2 border-t border-[var(--line-subtle)]
                           bg-[var(--surface-sunken)] px-4 py-3">
                <x-ui.button variant="ghost" size="md" wire:click="closeTask">{{ __('Close') }}</x-ui.button>
                <x-ui.button variant="secondary" size="md" :href="route('app.tasks.show', [$workspace, $task])"
                             trailing-icon="icon.chevron-right" class="[&>svg]:flip-rtl">
                    {{ __('Open task') }}
                </x-ui.button>
            </footer>
        @endif
    </x-ui.drawer>
</div>

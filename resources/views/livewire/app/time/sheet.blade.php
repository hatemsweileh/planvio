@php
    $week = $this->week;
    $totals = $this->totals;
    $byDay = $this->entriesByDay;
    $peak = max(1, ...array_map(static fn (array $day): int => $day['minutes'], $week['days']));
@endphp

<div class="page py-5">

    <header class="flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-base font-semibold tracking-tight text-[var(--text-strong)]">{{ __('My time') }}</h1>
            <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                {{ $week['title'] }}
                <span class="text-[var(--text-subtle)]">· {{ $this->timezone() }}</span>
            </p>
        </div>

        <div class="flex items-center gap-1.5">
            <div wire:loading.delay class="pe-1 text-[var(--text-subtle)]"
                 wire:target="goPreviousWeek,goNextWeek,goToThisWeek,saveEntry,deleteEntry,startTimer,stopTimer">
                <x-ui.spinner class="size-4" />
            </div>

            <x-ui.button variant="secondary" size="md" icon-only wire:click="goPreviousWeek"
                         :aria-label="__('Previous week')">
                <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path d="M12 5 7 10l5 5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </x-ui.button>
            <x-ui.button variant="secondary" size="md" wire:click="goToThisWeek">{{ __('This week') }}</x-ui.button>
            <x-ui.button variant="secondary" size="md" icon-only wire:click="goNextWeek"
                         :aria-label="__('Next week')">
                <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path d="m8 5 5 5-5 5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </x-ui.button>

            <x-ui.button variant="primary" size="md" icon="icon.plus" wire:click="openEntryForm">
                {{ __('Log time') }}
            </x-ui.button>
        </div>
    </header>

    <div class="mt-4">
        @include('livewire.app.time._timer-bar', ['showPicker' => true, 'showProject' => true])
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- The week                                                         --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="mt-4 grid grid-cols-2 gap-2.5 sm:grid-cols-4">
        <x-chart.stat :label="__('This week')" :value="$this->durationLabel($totals['minutes'])" icon="icon.clock" />
        <x-chart.stat :label="__('Billable')" :value="$this->durationLabel($totals['billable'])" tone="positive" />
        <x-chart.stat :label="__('Non-billable')"
                      :value="$this->durationLabel(max(0, $totals['minutes'] - $totals['billable']))" />
        <x-chart.stat :label="__('Days logged')" :value="$totals['days'].' / 7'"
                      :hint="trans_choice('{0}No entry|{1}:count entry|[2,*]:count entries', $totals['entries'], ['count' => $totals['entries']])" />
    </div>

    <x-ui.card class="mt-4" flush>
        {{-- Seven columns from sm up; a stack below it, where seven 45px columns would be
             a chart of nothing. --}}
        <div class="hidden grid-cols-7 divide-x divide-[var(--line-subtle)] sm:grid">
            @foreach ($week['days'] as $day)
                <button type="button"
                        wire:click="openEntryForm('{{ $day['date'] }}')"
                        class="group flex flex-col items-center gap-1.5 px-1 py-2.5 text-center transition-colors
                               hover:bg-[var(--surface-hover)]
                               {{ $day['isToday'] ? 'bg-[var(--accent-soft)]' : '' }}"
                        wire:key="sheet-day-{{ $day['date'] }}"
                        aria-label="{{ __('Log time on :date', ['date' => $day['label']]) }}">
                    <span class="text-2xs font-semibold uppercase tracking-wider
                                 {{ $day['isToday'] ? 'text-[var(--accent)]' : 'text-[var(--text-muted)]' }}">
                        {{ $day['weekday'] }}
                    </span>
                    <span class="text-2xs text-[var(--text-subtle)]">{{ $day['label'] }}</span>

                    <span class="flex h-16 w-full items-end justify-center px-2" aria-hidden="true">
                        <span class="w-6 rounded-t-sm transition-[height] duration-300
                                     {{ $day['minutes'] > 0 ? 'bg-[var(--accent)]' : 'bg-[var(--surface-active)]' }}"
                              style="height: {{ $day['minutes'] > 0 ? max(6, (int) round($day['minutes'] / $peak * 64)) : 3 }}px"></span>
                    </span>

                    <span class="text-xs font-semibold tabular-nums
                                 {{ $day['minutes'] > 0 ? 'text-[var(--text-strong)]' : 'text-[var(--text-subtle)]' }}">
                        {{ $day['minutes'] > 0 ? $this->durationLabel($day['minutes']) : '—' }}
                    </span>
                </button>
            @endforeach
        </div>

        <table class="sr-only">
            <caption>{{ __('Hours logged each day of :week', ['week' => $week['title']]) }}</caption>
            <thead>
                <tr><th scope="col">{{ __('Day') }}</th><th scope="col">{{ __('Logged') }}</th><th scope="col">{{ __('Billable') }}</th></tr>
            </thead>
            <tbody>
                @foreach ($week['days'] as $day)
                    <tr>
                        <th scope="row">{{ $day['weekday'] }} {{ $day['label'] }}</th>
                        <td>{{ $this->durationLabel($day['minutes']) }}</td>
                        <td>{{ $this->durationLabel($day['billable']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.card>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Entries                                                          --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($totals['entries'] === 0)
        <x-ui.card class="mt-4" flush>
            <x-ui.empty-state icon="icon.clock"
                              :title="__('Nothing logged this week')"
                              :description="__('Start a timer when you begin, or record the hours afterwards. Either way they land here and on every report the project has.')">
                <x-slot:actions>
                    <x-ui.button variant="primary" size="md" icon="icon.plus" wire:click="openEntryForm">
                        {{ __('Log time') }}
                    </x-ui.button>
                    <x-ui.button variant="secondary" size="md" wire:click="goPreviousWeek">
                        {{ __('Look at last week') }}
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <div class="mt-4 space-y-3">
            @foreach ($week['days'] as $day)
                @continue(($byDay[$day['date']] ?? []) === [])

                <section wire:key="sheet-entries-{{ $day['date'] }}">
                    <div class="flex items-baseline justify-between gap-2 px-1">
                        <h2 class="text-xs font-semibold text-[var(--text-strong)]">
                            {{ $day['weekday'] }}
                            <span class="font-normal text-[var(--text-muted)]">{{ $day['label'] }}</span>
                            @if ($day['isToday'])
                                <span class="ms-1 text-2xs font-normal text-[var(--accent)]">{{ __('today') }}</span>
                            @endif
                        </h2>
                        <span class="text-xs font-semibold tabular-nums text-[var(--text-muted)]">
                            {{ $this->durationLabel($day['minutes']) }}
                        </span>
                    </div>

                    <ul class="mt-1.5 divide-y divide-[var(--line-subtle)] overflow-hidden rounded-lg border
                               border-[var(--line-subtle)] bg-[var(--surface-panel)] shadow-panel">
                        @foreach ($byDay[$day['date']] as $entry)
                            <li class="flex items-center gap-3 px-3 py-2" wire:key="entry-{{ $entry->getKey() }}">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm text-[var(--text-strong)]">
                                        {{ $entry->task?->title ?? $entry->project?->name }}
                                    </p>
                                    <p class="truncate text-2xs text-[var(--text-muted)]">
                                        @if ($entry->task)
                                            <span class="font-mono">{{ $entry->task->key }}</span>
                                            <span class="text-[var(--text-subtle)]">·</span>
                                        @endif
                                        {{ $entry->project?->name }}
                                        @if ($entry->description)
                                            <span class="text-[var(--text-subtle)]">· {{ $entry->description }}</span>
                                        @endif
                                    </p>
                                </div>

                                @unless ($entry->is_billable)
                                    <x-ui.badge color="gray" size="sm">{{ __('Non-billable') }}</x-ui.badge>
                                @endunless

                                @if ($entry->is_running)
                                    <x-ui.badge color="green" size="sm" dot>{{ __('Running') }}</x-ui.badge>
                                @else
                                    <span class="shrink-0 text-sm font-semibold tabular-nums text-[var(--text-strong)]">
                                        {{ $this->durationLabel((int) $entry->minutes) }}
                                    </span>
                                @endif

                                <div class="flex shrink-0 items-center gap-0.5">
                                    <x-ui.button variant="ghost" size="sm" icon-only
                                                 wire:click="editEntry({{ $entry->getKey() }})"
                                                 :aria-label="__('Edit this entry')">
                                        <svg class="size-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75">
                                            <path d="M13.5 3.5 16.5 6.5 7 16H4v-3l9.5-9.5Z" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </x-ui.button>
                                    <x-ui.button variant="ghost" size="sm" icon-only
                                                 wire:click="deleteEntry({{ $entry->getKey() }})"
                                                 wire:confirm="{{ __('Delete this entry? The minutes leave every total that counted them.') }}"
                                                 :aria-label="__('Delete this entry')">
                                        <x-icon.trash class="size-3.5" />
                                    </x-ui.button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    @endif

    @include('livewire.app.time._entry-form', ['showProject' => true])
</div>

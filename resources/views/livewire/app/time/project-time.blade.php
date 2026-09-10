@php
    use App\Services\TimeReportRow;
    use App\Support\Formats;

    $range = $this->range;
    $breakdown = $this->breakdown;
    $entries = $this->entries;

    $userBars = array_map(fn (TimeReportRow $row): array => [
        'label' => $row->label ?? __('Unknown'),
        'value' => $row->minutes,
        'display' => $this->durationLabel($row->minutes),
        'color' => 'brand',
    ], array_slice($breakdown['byUser'], 0, 12));

    $taskBars = array_map(fn (TimeReportRow $row): array => [
        'label' => $row->label ?? __('Project level'),
        'value' => $row->minutes,
        'display' => $this->durationLabel($row->minutes),
        'meta' => $row->reference,
        'color' => 'purple',
    ], array_slice($breakdown['byTask'], 0, 12));

    $dayLabels = array_map(static fn (TimeReportRow $row): string => (string) $row->label, $breakdown['byDay']);
    $dayValues = array_map(static fn (TimeReportRow $row): float => $row->hours(), $breakdown['byDay']);
    $dayDisplay = array_map(fn (TimeReportRow $row): string => $this->durationLabel($row->minutes), $breakdown['byDay']);
@endphp

<div class="page py-5">

    <header class="flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <h1 dir="auto" class="truncate text-base font-semibold tracking-tight text-[var(--text-strong)]">
                <a href="{{ route('app.projects.show', [$workspace, $project]) }}"
                   class="hover:text-[var(--accent)]">{{ $project->name }}</a>
                <span class="text-[var(--text-subtle)]">/</span>
                <span class="font-normal text-[var(--text-muted)]">{{ __('Time') }}</span>
            </h1>
            <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                {{ __(':from to :to', ['from' => $range->fromDate(), 'to' => $range->toDate()]) }}
                @if ($breakdown['own'])
                    <span class="text-[var(--text-subtle)]">· {{ __('your hours only') }}</span>
                @endif
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-1.5">
            <div wire:loading.delay class="pe-1 text-[var(--text-subtle)]"
                 wire:target="preset,customFrom,customTo,saveEntry,deleteEntry,startTimer,stopTimer,gotoPage,nextPage,previousPage">
                <x-ui.spinner class="size-4" />
            </div>

            <label class="sr-only" for="project-time-range">{{ __('Date range') }}</label>
            <x-ui.select id="project-time-range" size="md" wire:model.live="preset"
                         :options="$this->presets()" class="w-36" />

            <x-ui.button variant="primary" size="md" icon="icon.plus" wire:click="openEntryForm">
                {{ __('Log time') }}
            </x-ui.button>
        </div>
    </header>

    @if ($preset === 'custom')
        <div class="mt-3 flex flex-wrap items-end gap-2 rounded-lg border border-[var(--line-subtle)]
                    bg-[var(--surface-panel)] px-3 py-2.5 shadow-panel">
            <x-ui.field :label="__('From')" for="project-time-from" class="w-40">
                <x-ui.input id="project-time-from" type="date" size="sm" wire:model.live.debounce.500ms="customFrom" />
            </x-ui.field>
            <x-ui.field :label="__('To')" for="project-time-to" class="w-40">
                <x-ui.input id="project-time-to" type="date" size="sm" wire:model.live.debounce.500ms="customTo" />
            </x-ui.field>
        </div>
    @endif

    <div class="mt-4">
        @include('livewire.app.time._timer-bar', ['showPicker' => true, 'showProject' => false])
    </div>

    <div class="mt-4 grid grid-cols-2 gap-2.5 sm:grid-cols-4">
        <x-chart.stat :label="__('Logged')" :value="$this->durationLabel($breakdown['total'])" icon="icon.clock" />
        <x-chart.stat :label="__('Billable')" :value="$this->durationLabel($breakdown['billable'])" tone="positive" />
        <x-chart.stat :label="__('Non-billable')"
                      :value="$this->durationLabel(max(0, $breakdown['total'] - $breakdown['billable']))" />
        <x-chart.stat :label="__('Entries')" :value="Formats::number($entries->total())"
                      :hint="$breakdown['own'] ? __('Yours') : __('Everyone on this project')" />
    </div>

    @if ($breakdown['total'] === 0 && $entries->total() === 0)
        <x-ui.card class="mt-4" flush>
            <x-ui.empty-state icon="icon.clock"
                              :title="__('No time logged in this range')"
                              :description="__('Run a timer while you work, or record the hours afterwards. Both land in the same place and feed the same reports.')">
                <x-slot:actions>
                    <x-ui.button variant="primary" size="md" icon="icon.plus" wire:click="openEntryForm">
                        {{ __('Log time') }}
                    </x-ui.button>
                    <x-ui.button variant="secondary" size="md" wire:click="$set('preset', 'all')">
                        {{ __('Show all time') }}
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.empty-state>
        </x-ui.card>
    @else
        @if ($dayLabels !== [])
            <x-ui.card class="mt-4" :title="__('Hours per day')">
                <x-chart.line :labels="$dayLabels"
                              :series="[['label' => __('Logged'), 'color' => 'brand', 'values' => $dayValues, 'display' => $dayDisplay]]"
                              :label-heading="__('Date')"
                              :caption="__('Hours logged on :project per day', ['project' => $project->name])" />
            </x-ui.card>
        @endif

        <div class="mt-4 grid gap-4 {{ $userBars === [] ? '' : 'lg:grid-cols-2' }}">
            @if ($userBars !== [])
                <x-ui.card :title="__('By person')">
                    <x-chart.bar orientation="horizontal" :data="$userBars"
                                 :label-heading="__('Person')" :value-heading="__('Hours')"
                                 :caption="__('Hours logged by person')" />
                </x-ui.card>
            @endif

            <x-ui.card :title="__('By task')"
                       :subtitle="__('Work booked to the project without a task appears as “project level”.')">
                <x-chart.bar orientation="horizontal" :data="$taskBars"
                             :label-heading="__('Task')" :value-heading="__('Hours')"
                             :caption="__('Hours logged by task')" />
            </x-ui.card>
        </div>

        <x-ui.card class="mt-4" :title="__('Entries')" flush>
            <div class="hidden overflow-x-auto md:block">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="border-b border-[var(--line-subtle)] text-start text-2xs uppercase tracking-wider text-[var(--text-muted)]">
                            <th scope="col" class="px-4 py-2 font-semibold">{{ __('Date') }}</th>
                            @unless ($breakdown['own'])
                                <th scope="col" class="px-3 py-2 font-semibold">{{ __('Person') }}</th>
                            @endunless
                            <th scope="col" class="px-3 py-2 font-semibold">{{ __('Task') }}</th>
                            <th scope="col" class="px-3 py-2 font-semibold">{{ __('Note') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-semibold">{{ __('Duration') }}</th>
                            <th scope="col" class="px-4 py-2 text-end font-semibold"><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--line-subtle)]">
                        @foreach ($entries as $entry)
                            <tr class="transition-colors hover:bg-[var(--surface-hover)]" wire:key="pt-{{ $entry->getKey() }}">
                                <th scope="row" class="whitespace-nowrap px-4 py-1.5 text-start font-medium tabular-nums text-[var(--text-strong)]">
                                    {{ Formats::date($entry->spent_on, '') }}
                                </th>
                                @unless ($breakdown['own'])
                                    <td class="px-3 py-1.5">
                                        <span class="flex items-center gap-1.5">
                                            <x-ui.avatar :user="$entry->user" size="xs" />
                                            <span class="truncate text-[var(--text-DEFAULT)]">{{ $entry->user?->name }}</span>
                                        </span>
                                    </td>
                                @endunless
                                <td class="max-w-56 px-3 py-1.5">
                                    @if ($entry->task)
                                        <a href="{{ route('app.tasks.show', [$workspace, $entry->task]) }}"
                                           class="truncate text-[var(--text-DEFAULT)] hover:text-[var(--accent)]">
                                            {{ $entry->task->title }}
                                        </a>
                                    @else
                                        <span class="text-[var(--text-subtle)]">{{ __('Project level') }}</span>
                                    @endif
                                </td>
                                <td class="max-w-64 truncate px-3 py-1.5 text-[var(--text-muted)]">
                                    {{ $entry->description ?? '—' }}
                                </td>
                                <td class="px-3 py-1.5 text-end font-semibold tabular-nums text-[var(--text-strong)]">
                                    @if ($entry->is_running)
                                        <x-ui.badge color="green" size="sm" dot>{{ __('Running') }}</x-ui.badge>
                                    @else
                                        {{ $this->durationLabel((int) $entry->minutes) }}
                                    @endif
                                    @unless ($entry->is_billable)
                                        <span class="ms-1 text-2xs font-normal text-[var(--text-subtle)]">{{ __('nb') }}</span>
                                    @endunless
                                </td>
                                <td class="px-4 py-1.5 text-end">
                                    @if ($this->canEdit($entry))
                                        <span class="inline-flex items-center gap-0.5">
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
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <ul class="divide-y divide-[var(--line-subtle)] md:hidden">
                @foreach ($entries as $entry)
                    <li class="px-4 py-2.5" wire:key="pt-card-{{ $entry->getKey() }}">
                        <div class="flex items-baseline justify-between gap-2">
                            <p class="min-w-0 flex-1 truncate text-sm text-[var(--text-strong)]">
                                {{ $entry->task?->title ?? __('Project level') }}
                            </p>
                            <span class="shrink-0 text-sm font-semibold tabular-nums text-[var(--text-strong)]">
                                {{ $entry->is_running ? __('Running') : $this->durationLabel((int) $entry->minutes) }}
                            </span>
                        </div>
                        <p class="mt-0.5 truncate text-2xs text-[var(--text-muted)]">
                            {{ Formats::date($entry->spent_on, '') }}
                            @unless ($breakdown['own'])
                                · {{ $entry->user?->name }}
                            @endunless
                            @if ($entry->description)
                                · {{ $entry->description }}
                            @endif
                        </p>
                        @if ($this->canEdit($entry))
                            <div class="mt-1.5 flex items-center gap-1">
                                <x-ui.button variant="ghost" size="xs" wire:click="editEntry({{ $entry->getKey() }})">
                                    {{ __('Edit') }}
                                </x-ui.button>
                                <x-ui.button variant="danger-ghost" size="xs"
                                             wire:click="deleteEntry({{ $entry->getKey() }})"
                                             wire:confirm="{{ __('Delete this entry?') }}">
                                    {{ __('Delete') }}
                                </x-ui.button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($entries->hasPages())
                <x-slot:footer>
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-2xs tabular-nums text-[var(--text-muted)]">
                            {{ __('Entries :from–:to of :total', [
                                'from' => $entries->firstItem() ?? 0,
                                'to' => $entries->lastItem() ?? 0,
                                'total' => $entries->total(),
                            ]) }}
                        </p>
                        <div class="flex items-center gap-1">
                            <x-ui.button variant="secondary" size="sm" wire:click="previousPage"
                                         :disabled="$entries->onFirstPage()">{{ __('Previous') }}</x-ui.button>
                            <x-ui.button variant="secondary" size="sm" wire:click="nextPage"
                                         :disabled="! $entries->hasMorePages()">{{ __('Next') }}</x-ui.button>
                        </div>
                    </div>
                </x-slot:footer>
            @endif
        </x-ui.card>
    @endif

    @include('livewire.app.time._entry-form', ['showProject' => false])
</div>

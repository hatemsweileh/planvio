@php
    use App\Services\WorkloadRow;
    use App\Services\WorkloadService;
    use App\Support\Formats;

    $rows = $this->workload;
    $withWork = array_values(array_filter($rows, static fn (WorkloadRow $row): bool => $row->hasWork()));
    $idle = array_values(array_filter($rows, static fn (WorkloadRow $row): bool => ! $row->hasWork()));

    $busiest = WorkloadService::busiest($rows);

    $totals = [
        'open' => array_sum(array_map(static fn (WorkloadRow $row): int => $row->open, $rows)),
        'overdue' => array_sum(array_map(static fn (WorkloadRow $row): int => $row->overdue, $rows)),
        'estimate' => array_sum(array_map(static fn (WorkloadRow $row): int => $row->estimateMinutes, $rows)),
    ];

    $unassigned = null;
    foreach ($rows as $row) {
        if ($row->isUnassigned()) {
            $unassigned = $row;
        }
    }

    // Open work split into "on time" and "already late", stacked, so the bar length is the
    // load and the red tail is the trouble. Capped at the twenty busiest: past that the
    // labels stop being legible and the table below is the better instrument anyway.
    $groups = array_map(static fn (WorkloadRow $row): array => [
        'label' => $row->userName ?? __('Unassigned'),
        'display' => (string) $row->open,
        'segments' => [
            ['label' => __('Open'), 'value' => max(0, $row->open - $row->overdue), 'display' => (string) max(0, $row->open - $row->overdue), 'color' => 'brand'],
            ['label' => __('Overdue'), 'value' => $row->overdue, 'display' => (string) $row->overdue, 'color' => 'red'],
        ],
    ], array_slice($withWork, 0, 20));
@endphp

<div class="space-y-4">
    <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
        <x-chart.stat :label="__('Open tasks')" :value="Formats::number($totals['open'])" />
        <x-chart.stat :label="__('Overdue')" :value="Formats::number($totals['overdue'])"
                      :tone="$totals['overdue'] > 0 ? 'critical' : 'neutral'" />
        <x-chart.stat :label="__('Estimated')" :value="$this->hours($totals['estimate'])"
                      :hint="__('Where an estimate was recorded')" />
        <x-chart.stat :label="__('Unassigned')" :value="Formats::number($unassigned?->open ?? 0)"
                      :tone="($unassigned?->open ?? 0) > 0 ? 'caution' : 'neutral'"
                      :hint="__('Open work nobody owns')" />
    </div>

    @if ($withWork === [])
        <x-ui.card flush>
            <x-ui.empty-state icon="icon.users"
                              :title="__('Nobody is carrying anything')"
                              :description="__('Workload counts open tasks by assignee. Assign some work, and this fills in.')">
                <x-slot:actions>
                    <x-ui.button variant="secondary" size="md" :href="route('app.projects.index', $workspace)">
                        {{ __('Open a project') }}
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <x-ui.card :title="__('Open work by person')"
                   :subtitle="$busiest !== null
                        ? __(':name is carrying the most, with :count open.', ['name' => $busiest->userName ?? __('Someone'), 'count' => $busiest->open])
                        : __('Open tasks, split into on time and already late.')">
            <x-chart.stacked-bar orientation="horizontal"
                                 :groups="$groups"
                                 :label-heading="__('Person')"
                                 :caption="__('Open and overdue tasks by assignee')" />
        </x-ui.card>

        <x-ui.card :title="__('Everyone')" flush>
            <div class="hidden overflow-x-auto md:block">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="border-b border-[var(--line-subtle)] text-start text-2xs uppercase tracking-wider text-[var(--text-muted)]">
                            <th scope="col" class="px-4 py-2 font-semibold">{{ __('Person') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-semibold">{{ __('Open') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-semibold">{{ __('Overdue') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-semibold">{{ __('Completed') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-semibold">{{ __('Total') }}</th>
                            <th scope="col" class="px-4 py-2 text-end font-semibold">{{ __('Estimated') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--line-subtle)]">
                        @foreach ($rows as $row)
                            <tr class="transition-colors hover:bg-[var(--surface-hover)]"
                                wire:key="workload-{{ $row->userId ?? 'unassigned' }}">
                                <th scope="row" class="px-4 py-2 text-start font-medium">
                                    <span class="flex items-center gap-2">
                                        @if ($row->isUnassigned())
                                            <span class="grid size-5 place-items-center rounded-full border border-dashed
                                                         border-[var(--line-strong)] text-[9px] text-[var(--text-subtle)]"
                                                  aria-hidden="true">?</span>
                                            <span class="text-[var(--text-muted)]">{{ __('Unassigned') }}</span>
                                        @else
                                            <x-ui.avatar :name="$row->userName ?? __('Former member')" size="xs" />
                                            <span class="truncate text-[var(--text-strong)]">
                                                {{ $row->userName ?? __('Former member') }}
                                            </span>
                                        @endif
                                    </span>
                                </th>
                                <td class="px-3 py-2 text-end font-medium tabular-nums text-[var(--text-strong)]">{{ $row->open }}</td>
                                <td class="px-3 py-2 text-end tabular-nums {{ $row->overdue > 0 ? 'font-semibold text-critical-600 dark:text-critical-500' : 'text-[var(--text-subtle)]' }}">
                                    {{ $row->overdue }}
                                </td>
                                <td class="px-3 py-2 text-end tabular-nums text-[var(--text-muted)]">{{ $row->completed }}</td>
                                <td class="px-3 py-2 text-end tabular-nums text-[var(--text-muted)]">{{ $row->total }}</td>
                                <td class="px-4 py-2 text-end tabular-nums text-[var(--text-muted)]">
                                    {{ $row->estimateMinutes > 0 ? $this->hours($row->estimateMinutes) : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <ul class="divide-y divide-[var(--line-subtle)] md:hidden">
                @foreach ($rows as $row)
                    <li class="flex items-center gap-3 px-4 py-2.5" wire:key="workload-card-{{ $row->userId ?? 'unassigned' }}">
                        @unless ($row->isUnassigned())
                            <x-ui.avatar :name="$row->userName ?? __('Former member')" size="sm" />
                        @endunless
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm text-[var(--text-strong)]">
                                {{ $row->isUnassigned() ? __('Unassigned') : ($row->userName ?? __('Former member')) }}
                            </p>
                            <p class="text-2xs text-[var(--text-muted)]">
                                {{ trans_choice('{0}Nothing open|{1}:count open|[2,*]:count open', $row->open, ['count' => $row->open]) }}
                                @if ($row->overdue > 0)
                                    <span class="text-critical-600 dark:text-critical-500">· {{ __(':count overdue', ['count' => $row->overdue]) }}</span>
                                @endif
                            </p>
                        </div>
                        <span class="shrink-0 text-sm font-semibold tabular-nums text-[var(--text-strong)]">{{ $row->open }}</span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>

        @if ($idle !== [])
            <p class="text-2xs text-[var(--text-subtle)]">
                {{ trans_choice(
                    '{1}:count person is holding no work in this scope.|[2,*]:count people are holding no work in this scope.',
                    count($idle),
                    ['count' => count($idle)],
                ) }}
            </p>
        @endif
    @endif
</div>

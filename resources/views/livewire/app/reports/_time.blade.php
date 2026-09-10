@php
    use App\Services\TimeReportRow;
    use App\Support\Bidi;

    $time = $this->time;
    $byDay = $time['byDay'];
    $range = $this->range;

    // The service returns only the days that carry an entry. A line chart drawn from those
    // alone would compress a quiet fortnight into a single step and lie about the shape of
    // the work, so the axis is every day in the range and the gaps are real zeroes.
    $logged = [];
    foreach ($byDay as $row) {
        $logged[(string) $row->label] = $row;
    }

    $labels = [];
    $totalSeries = [];
    $billableSeries = [];
    $totalDisplay = [];
    $billableDisplay = [];

    $cursor = $range->from;
    $days = min($range->lengthInDays(), 180);

    for ($i = 0; $i < $days; $i++) {
        $key = $cursor->toDateString();
        $row = $logged[$key] ?? null;

        // A chart axis tick, so the short prose form in the reader's language rather than
        // the workspace's date format: a full date per point is unreadable at 180 of them.
        $labels[] = $cursor->translatedFormat('j M');
        $totalSeries[] = $row?->hours() ?? 0;
        $billableSeries[] = $row?->billableHours() ?? 0;
        $totalDisplay[] = $this->hours($row?->minutes ?? 0);
        $billableDisplay[] = $this->hours($row?->billableMinutes ?? 0);

        $cursor = $cursor->addDay();
    }

    $nonBillable = max(0, $time['total'] - $time['billable']);
    $billableShare = $time['total'] > 0 ? (int) round($time['billable'] / $time['total'] * 100) : 0;

    $projectBars = array_map(fn (TimeReportRow $row): array => [
        'label' => $row->label ?? __('Unattributed'),
        'value' => $row->minutes,
        'display' => $this->hours($row->minutes),
        'meta' => trans_choice('{1}:count entry|[2,*]:count entries', $row->entries, ['count' => $row->entries]),
        'color' => 'brand',
    ], array_slice($time['byProject'], 0, 15));

    $userBars = array_map(fn (TimeReportRow $row): array => [
        'label' => $row->label ?? __('Unknown'),
        'value' => $row->minutes,
        'display' => $this->hours($row->minutes),
        'meta' => $row->reference,
        'color' => 'purple',
    ], array_slice($time['byUser'], 0, 15));
@endphp

<div class="space-y-4">
    @if ($time['own'])
        <p class="rounded-md border border-[var(--line-subtle)] bg-[var(--surface-sunken)] px-3 py-2 text-xs
                  text-[var(--text-muted)]">
            {{ __('You can see your own hours here. Reporting on everyone\'s time needs the "view all time" permission.') }}
        </p>
    @endif

    <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
        <x-chart.stat :label="__('Logged')" :value="$this->hours($time['total'])" icon="icon.clock"
                      :hint="__(':from to :to', ['from' => Bidi::ltr($range->fromDate()), 'to' => Bidi::ltr($range->toDate())])" />
        <x-chart.stat :label="__('Billable')" :value="$this->hours($time['billable'])" tone="positive"
                      :hint="$billableShare.'% '.__('of logged time')" />
        <x-chart.stat :label="__('Non-billable')" :value="$this->hours($nonBillable)" />
        <x-chart.stat :label="__('Daily average')"
                      :value="$this->hours($range->lengthInDays() > 0 ? intdiv($time['total'], $range->lengthInDays()) : 0)"
                      :hint="trans_choice('{1}over :count day|[2,*]over :count days', $range->lengthInDays(), ['count' => $range->lengthInDays()])" />
    </div>

    @if ($time['total'] === 0)
        <x-ui.card flush>
            <x-ui.empty-state icon="icon.clock"
                              :title="__('No time logged in this range')"
                              :description="__('Hours appear here as soon as somebody runs a timer or records an entry against a project.')">
                <x-slot:actions>
                    <x-ui.button variant="primary" size="md" icon="icon.clock" :href="route('app.time', $workspace)">
                        {{ __('Open my time sheet') }}
                    </x-ui.button>
                    <x-ui.button variant="secondary" size="md" wire:click="$set('preset', 'last_90')">
                        {{ __('Try the last 90 days') }}
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <x-ui.card :title="__('Hours per day')"
                   :subtitle="__('Total against billable. Days with nothing logged are drawn as zero, not skipped.')">
            <x-chart.line :labels="$labels"
                          :series="[
                            ['label' => __('Logged'), 'color' => 'brand', 'values' => $totalSeries, 'display' => $totalDisplay],
                            ['label' => __('Billable'), 'color' => 'green', 'values' => $billableSeries, 'display' => $billableDisplay],
                          ]"
                          :label-heading="__('Date')"
                          :caption="__('Hours logged per day')" />
        </x-ui.card>

        <div class="grid gap-4 {{ $userBars === [] ? '' : 'lg:grid-cols-2' }}">
            <x-ui.card :title="__('By project')">
                <x-chart.bar orientation="horizontal" :data="$projectBars"
                             :label-heading="__('Project')" :value-heading="__('Hours')"
                             :caption="__('Hours logged by project')" />
            </x-ui.card>

            @if ($userBars !== [])
                <x-ui.card :title="__('By person')">
                    <x-chart.bar orientation="horizontal" :data="$userBars"
                                 :label-heading="__('Person')" :value-heading="__('Hours')"
                                 :caption="__('Hours logged by person')" />
                </x-ui.card>
            @endif
        </div>

        <x-ui.card :title="__('Daily detail')" flush>
            <div class="scrollbar-thin max-h-96 overflow-y-auto">
                <table class="w-full text-xs">
                    <thead class="sticky top-0 bg-[var(--surface-panel)]">
                        <tr class="border-b border-[var(--line-subtle)] text-start text-2xs uppercase tracking-wider text-[var(--text-muted)]">
                            <th scope="col" class="px-4 py-2 font-semibold">{{ __('Date') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-semibold">{{ __('Logged') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-semibold">{{ __('Billable') }}</th>
                            <th scope="col" class="px-4 py-2 text-end font-semibold">{{ __('Entries') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--line-subtle)]">
                        @foreach ($byDay as $row)
                            <tr class="transition-colors hover:bg-[var(--surface-hover)]" wire:key="time-day-{{ $row->label }}">
                                <th scope="row" class="px-4 py-1.5 text-start font-medium tabular-nums text-[var(--text-strong)]">
                                    {{ $row->label }}
                                </th>
                                <td class="px-3 py-1.5 text-end tabular-nums">{{ $this->hours($row->minutes) }}</td>
                                <td class="px-3 py-1.5 text-end tabular-nums text-[var(--text-muted)]">
                                    {{ $this->hours($row->billableMinutes) }}
                                </td>
                                <td class="px-4 py-1.5 text-end tabular-nums text-[var(--text-muted)]">{{ $row->entries }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif
</div>

@php
    use App\Enums\ProjectHealth;

    $rows = $this->progress;
    $assessments = $this->health;

    $distribution = [
        ProjectHealth::OnTrack->value => 0,
        ProjectHealth::AtRisk->value => 0,
        ProjectHealth::OffTrack->value => 0,
    ];

    $flagged = [];

    foreach ($rows as $row) {
        $assessment = $assessments[$row['id']] ?? null;

        if ($assessment === null) {
            continue;
        }

        $distribution[$assessment->health->value]++;

        if ($assessment->health !== ProjectHealth::OnTrack || $assessment->overridesComputed()) {
            $flagged[] = ['row' => $row, 'assessment' => $assessment];
        }
    }

    // Worst first: a risk report that opens with the healthy projects buries its own point.
    usort($flagged, static function (array $a, array $b): int {
        $rank = [ProjectHealth::OffTrack->value => 0, ProjectHealth::AtRisk->value => 1, ProjectHealth::OnTrack->value => 2];

        return [$rank[$a['assessment']->health->value], -$a['row']['overdue']]
            <=> [$rank[$b['assessment']->health->value], -$b['row']['overdue']];
    });

    $donut = [
        ['label' => ProjectHealth::OnTrack->label(), 'value' => $distribution[ProjectHealth::OnTrack->value], 'color' => 'green'],
        ['label' => ProjectHealth::AtRisk->label(), 'value' => $distribution[ProjectHealth::AtRisk->value], 'color' => 'amber'],
        ['label' => ProjectHealth::OffTrack->label(), 'value' => $distribution[ProjectHealth::OffTrack->value], 'color' => 'red'],
    ];

    $total = array_sum($distribution);
@endphp

<div class="space-y-4">
    <div class="grid gap-4 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
        <x-ui.card :title="__('Health across the portfolio')"
                   :subtitle="__('Derived from overdue work, delayed milestones, workload concentration and deadline proximity.')">
            <x-chart.donut :data="$donut"
                           :centre-value="$total"
                           :centre-label="trans_choice('{1}project|[2,*]projects', $total)"
                           :label-heading="__('Health')"
                           :value-heading="__('Projects')"
                           :caption="__('Project health distribution')" />
        </x-ui.card>

        <x-ui.card :title="__('What needs attention')"
                   :subtitle="__('Every signal carries the figure it rests on, so a verdict can be checked rather than believed.')"
                   flush>
            @if ($flagged === [])
                <x-ui.empty-state icon="icon.check-circle" compact
                                  :title="__('Everything is on track')"
                                  :description="__('No project in this scope is showing overdue work, a delayed milestone or a deadline it cannot reach.')" />
            @else
                <ul class="divide-y divide-[var(--line-subtle)]">
                    @foreach ($flagged as $entry)
                        @php
                            $row = $entry['row'];
                            $assessment = $entry['assessment'];
                        @endphp
                        <li class="px-4 py-3" wire:key="health-{{ $row['id'] }}">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route('app.projects.show', [$workspace, $row['slug']]) }}"
                                   dir="auto" class="min-w-0 flex-1 truncate text-sm font-medium text-[var(--text-strong)] hover:text-[var(--accent)]">
                                    {{ $row['name'] }}
                                </a>
                                <x-ui.badge :color="$assessment->health->color()" size="sm" dot>
                                    {{ $assessment->health->label() }}
                                </x-ui.badge>
                                @if ($assessment->manual)
                                    <x-ui.badge color="gray" size="sm">{{ __('Set by hand') }}</x-ui.badge>
                                @endif
                            </div>

                            @if ($assessment->overridesComputed())
                                <p class="mt-1 text-2xs text-caution-700 dark:text-caution-100">
                                    {{ __('Pinned to :stored, but the signals read :computed.', [
                                        'stored' => $assessment->health->label(),
                                        'computed' => $assessment->computed->label(),
                                    ]) }}
                                </p>
                            @endif

                            <ul class="mt-1.5 space-y-1">
                                @foreach ($assessment->reasons as $reason)
                                    <li class="flex items-start gap-1.5 text-xs text-[var(--text-muted)]">
                                        <span class="mt-1.5 size-1.5 shrink-0 rounded-full
                                                     {{ ($reason['severity'] ?? '') === 'off_track' ? 'bg-critical-500' : 'bg-caution-500' }}"
                                              aria-hidden="true"></span>
                                        <span>
                                            <span class="font-medium text-[var(--text-DEFAULT)]">
                                                {{ $this->healthReasonLabel((string) ($reason['code'] ?? '')) }}
                                            </span>
                                            @if (isset($reason['count'], $reason['open_tasks']))
                                                — {{ __(':count of :open open', ['count' => $reason['count'], 'open' => $reason['open_tasks']]) }}
                                            @elseif (isset($reason['count']))
                                                — {{ $reason['count'] }}
                                            @endif
                                            @if (! empty($reason['oldest_due_date']))
                                                <span class="text-[var(--text-subtle)]">
                                                    · {{ __('oldest since :date', ['date' => $reason['oldest_due_date']]) }}
                                                </span>
                                            @endif
                                            @if (! empty($reason['target_date']))
                                                <span class="text-[var(--text-subtle)]">
                                                    · {{ __('target :date', ['date' => $reason['target_date']]) }}
                                                </span>
                                            @endif
                                            @if (isset($reason['share']))
                                                <span class="text-[var(--text-subtle)]">
                                                    · {{ (int) round(((float) $reason['share']) * 100) }}%
                                                </span>
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>

    <x-ui.card :title="__('Dates and delivery')" flush>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr class="border-b border-[var(--line-subtle)] text-start text-2xs uppercase tracking-wider text-[var(--text-muted)]">
                        <th scope="col" class="px-4 py-2 font-semibold">{{ __('Project') }}</th>
                        <th scope="col" class="px-3 py-2 font-semibold">{{ __('Health') }}</th>
                        <th scope="col" class="px-3 py-2 font-semibold">{{ __('Calculated') }}</th>
                        <th scope="col" class="px-3 py-2 text-end font-semibold">{{ __('Overdue') }}</th>
                        <th scope="col" class="px-4 py-2 font-semibold">{{ __('Target') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--line-subtle)]">
                    @foreach ($rows as $row)
                        @php $assessment = $assessments[$row['id']] ?? null; @endphp
                        <tr class="transition-colors hover:bg-[var(--surface-hover)]" wire:key="health-row-{{ $row['id'] }}">
                            <th scope="row" class="max-w-56 truncate px-4 py-2 text-start font-medium text-[var(--text-strong)]">
                                {{ $row['name'] }}
                            </th>
                            <td class="px-3 py-2">
                                @if ($assessment)
                                    <x-ui.badge :color="$assessment->health->color()" size="sm" dot>
                                        {{ $assessment->health->label() }}
                                    </x-ui.badge>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-[var(--text-muted)]">{{ $assessment?->computed->label() }}</td>
                            <td class="px-3 py-2 text-end tabular-nums {{ $row['overdue'] > 0 ? 'font-semibold text-critical-600 dark:text-critical-500' : 'text-[var(--text-subtle)]' }}">
                                {{ $row['overdue'] }}
                            </td>
                            <td class="px-4 py-2 tabular-nums text-[var(--text-muted)]">{{ $row['targetDate'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>

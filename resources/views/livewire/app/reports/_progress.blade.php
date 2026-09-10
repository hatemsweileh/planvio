@php
    use App\Support\Formats;

    $rows = $this->progress;

    $totals = [
        'tasks' => array_sum(array_column($rows, 'total')),
        'completed' => array_sum(array_column($rows, 'completed')),
        'open' => array_sum(array_column($rows, 'open')),
        'overdue' => array_sum(array_column($rows, 'overdue')),
        'dueSoon' => array_sum(array_column($rows, 'dueSoon')),
    ];

    $percent = $totals['tasks'] > 0 ? (int) round($totals['completed'] / $totals['tasks'] * 100) : 0;

    // The stacked bars answer "what is left, and how much of it is late" at a glance —
    // three segments in a fixed order, so the bottom band means the same thing on every row.
    $groups = array_map(static fn (array $row): array => [
        'label' => $row['name'],
        'display' => (string) $row['total'],
        'segments' => [
            ['label' => __('Completed'), 'value' => $row['completed'], 'display' => (string) $row['completed'], 'color' => 'green'],
            ['label' => __('Open'), 'value' => max(0, $row['open'] - $row['overdue']), 'display' => (string) max(0, $row['open'] - $row['overdue']), 'color' => 'brand'],
            ['label' => __('Overdue'), 'value' => $row['overdue'], 'display' => (string) $row['overdue'], 'color' => 'red'],
        ],
    ], $rows);
@endphp

<div class="space-y-4">
    <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-5">
        <x-chart.stat :label="__('Tasks')" :value="Formats::number($totals['tasks'])"
                      :hint="trans_choice('{1}:count project|[2,*]:count projects', count($rows), ['count' => count($rows)])" />
        <x-chart.stat :label="__('Completed')" :value="Formats::number($totals['completed'])"
                      tone="positive" :hint="$percent.'% '.__('of all tasks')" />
        <x-chart.stat :label="__('Open')" :value="Formats::number($totals['open'])" />
        <x-chart.stat :label="__('Overdue')" :value="Formats::number($totals['overdue'])"
                      :tone="$totals['overdue'] > 0 ? 'critical' : 'neutral'"
                      :hint="__('Past due and not closed')" />
        <x-chart.stat :label="__('Due in 7 days')" :value="Formats::number($totals['dueSoon'])"
                      :tone="$totals['dueSoon'] > 0 ? 'caution' : 'neutral'" />
    </div>

    <x-ui.card :title="__('Delivery by project')"
               :subtitle="__('Every task in each project, split by state.')">
        <x-chart.stacked-bar orientation="horizontal"
                             :groups="$groups"
                             :label-heading="__('Project')"
                             :caption="__('Task counts by project and state')"
                             :empty-label="__('No task has been created in these projects yet.')" />
    </x-ui.card>

    <x-ui.card :title="__('Projects')" flush>
        {{-- Table from md up; the same rows as cards below it, because a nine-column table
             on a phone is a table nobody reads. --}}
        <div class="hidden overflow-x-auto md:block">
            <table class="w-full text-xs">
                <thead>
                    <tr class="border-b border-[var(--line-subtle)] text-start text-2xs uppercase tracking-wider text-[var(--text-muted)]">
                        <th scope="col" class="px-4 py-2 font-semibold">{{ __('Project') }}</th>
                        <th scope="col" class="w-40 px-3 py-2 font-semibold">{{ __('Progress') }}</th>
                        <th scope="col" class="px-3 py-2 text-end font-semibold">{{ __('Tasks') }}</th>
                        <th scope="col" class="px-3 py-2 text-end font-semibold">{{ __('Done') }}</th>
                        <th scope="col" class="px-3 py-2 text-end font-semibold">{{ __('Open') }}</th>
                        <th scope="col" class="px-3 py-2 text-end font-semibold">{{ __('Overdue') }}</th>
                        <th scope="col" class="px-3 py-2 font-semibold">{{ __('Health') }}</th>
                        <th scope="col" class="px-4 py-2 font-semibold">{{ __('Target') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--line-subtle)]">
                    @foreach ($rows as $row)
                        <tr class="transition-colors hover:bg-[var(--surface-hover)]" wire:key="progress-{{ $row['id'] }}">
                            <th scope="row" class="max-w-56 px-4 py-2 text-start font-medium">
                                <a href="{{ route('app.projects.show', [$workspace, $row['slug']]) }}"
                                   class="flex items-center gap-1.5 text-[var(--text-strong)] hover:text-[var(--accent)]">
                                    <span class="size-1.5 shrink-0 rounded-full" style="background-color: {{ $row['color'] }}"></span>
                                    <span dir="auto" class="truncate">{{ $row['name'] }}</span>
                                </a>
                            </th>
                            <td class="px-3 py-2">
                                <x-ui.progress :value="$row['percentage']" size="sm" show-label
                                               :label="__('Progress of :project', ['project' => $row['name']])" />
                            </td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ $row['total'] }}</td>
                            <td class="px-3 py-2 text-end tabular-nums text-[var(--text-muted)]">{{ $row['completed'] }}</td>
                            <td class="px-3 py-2 text-end tabular-nums text-[var(--text-muted)]">{{ $row['open'] }}</td>
                            <td class="px-3 py-2 text-end tabular-nums {{ $row['overdue'] > 0 ? 'font-semibold text-critical-600 dark:text-critical-500' : 'text-[var(--text-subtle)]' }}">
                                {{ $row['overdue'] }}
                            </td>
                            <td class="px-3 py-2">
                                @if ($row['health'])
                                    <x-ui.badge :color="$row['health']->color()" size="sm" dot>{{ $row['health']->label() }}</x-ui.badge>
                                @endif
                            </td>
                            <td class="px-4 py-2 tabular-nums text-[var(--text-muted)]">{{ $row['targetDate'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <ul class="divide-y divide-[var(--line-subtle)] md:hidden">
            @foreach ($rows as $row)
                <li class="px-4 py-3" wire:key="progress-card-{{ $row['id'] }}">
                    <div class="flex items-start justify-between gap-2">
                        <a href="{{ route('app.projects.show', [$workspace, $row['slug']]) }}"
                           dir="auto" class="min-w-0 flex-1 truncate text-sm font-medium text-[var(--text-strong)]">
                            {{ $row['name'] }}
                        </a>
                        @if ($row['health'])
                            <x-ui.badge :color="$row['health']->color()" size="sm" dot>{{ $row['health']->label() }}</x-ui.badge>
                        @endif
                    </div>
                    <x-ui.progress :value="$row['percentage']" size="sm" show-label class="mt-2"
                                   :label="__('Progress of :project', ['project' => $row['name']])" />
                    <dl class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-2xs text-[var(--text-muted)]">
                        <div class="flex gap-1"><dt>{{ __('Tasks') }}</dt><dd class="font-medium tabular-nums text-[var(--text-DEFAULT)]">{{ $row['total'] }}</dd></div>
                        <div class="flex gap-1"><dt>{{ __('Open') }}</dt><dd class="font-medium tabular-nums text-[var(--text-DEFAULT)]">{{ $row['open'] }}</dd></div>
                        <div class="flex gap-1"><dt>{{ __('Overdue') }}</dt><dd class="font-medium tabular-nums {{ $row['overdue'] > 0 ? 'text-critical-600' : 'text-[var(--text-DEFAULT)]' }}">{{ $row['overdue'] }}</dd></div>
                        @if ($row['targetDate'])
                            <div class="flex gap-1"><dt>{{ __('Target') }}</dt><dd class="tabular-nums text-[var(--text-DEFAULT)]">{{ $row['targetDate'] }}</dd></div>
                        @endif
                    </dl>
                </li>
            @endforeach
        </ul>
    </x-ui.card>
</div>

@php
    use App\Services\ProjectBudget;
    use App\Support\Formats;

    $rows = $this->progress;
    $budgets = $this->budget;

    $planned = 0;
    $actual = 0;
    $withBudget = 0;
    $over = 0;
    $unconverted = [];
    $currencies = [];

    foreach ($rows as $row) {
        $budget = $budgets[$row['id']] ?? null;

        if (! $budget instanceof ProjectBudget) {
            continue;
        }

        $currencies[$budget->currency] = true;
        $actual += $budget->actualMinor;

        if ($budget->hasBudget()) {
            $planned += (int) $budget->plannedMinor;
            $withBudget++;
        }

        if ($budget->isOverBudget()) {
            $over++;
        }

        foreach ($budget->unconvertedAmounts() as $code => $amount) {
            $unconverted[$code] = true;
        }
    }

    // Summing across currencies would be an invented exchange rate. When the scope holds
    // more than one, the totals are withheld and the per-project table — which is
    // per-currency and therefore true — is what the report offers instead.
    $mixed = count($currencies) > 1;
    $currency = $mixed ? null : array_key_first($currencies);

    $groups = [];
    foreach ($rows as $row) {
        $budget = $budgets[$row['id']] ?? null;

        if (! $budget instanceof ProjectBudget || (! $budget->hasBudget() && $budget->actualMinor === 0)) {
            continue;
        }

        $groups[] = [
            'label' => $row['name'],
            'display' => $budget->actual().' '.$budget->currency,
            'segments' => [
                ['label' => __('Spent'), 'value' => $budget->actualMinor / 100, 'display' => $budget->actual(), 'color' => 'brand'],
                [
                    'label' => __('Remaining'),
                    'value' => max(0, ($budget->varianceMinor() ?? 0)) / 100,
                    'display' => ProjectBudget::format(max(0, $budget->varianceMinor() ?? 0)),
                    'color' => 'gray',
                ],
                [
                    'label' => __('Over'),
                    'value' => max(0, -($budget->varianceMinor() ?? 0)) / 100,
                    'display' => ProjectBudget::format(max(0, -($budget->varianceMinor() ?? 0))),
                    'color' => 'red',
                ],
            ],
        ];
    }
@endphp

<div class="space-y-4">
    <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
        <x-chart.stat :label="__('Planned')"
                      :value="$mixed ? __('Mixed') : ProjectBudget::format($planned).' '.($currency ?? '')"
                      :hint="trans_choice('{0}No project carries a budget|{1}:count project with a budget|[2,*]:count projects with a budget', $withBudget, ['count' => $withBudget])" />
        <x-chart.stat :label="__('Spent')"
                      :value="$mixed ? __('Mixed') : ProjectBudget::format($actual).' '.($currency ?? '')"
                      :hint="__('Expenses booked in this range')" />
        <x-chart.stat :label="__('Variance')"
                      :value="$mixed ? __('Mixed') : ProjectBudget::format($planned - $actual).' '.($currency ?? '')"
                      :tone="! $mixed && $planned - $actual < 0 ? 'critical' : 'neutral'" />
        <x-chart.stat :label="__('Over budget')" :value="Formats::number($over)"
                      :tone="$over > 0 ? 'critical' : 'neutral'"
                      :hint="__('Projects past their planned figure')" />
    </div>

    @if ($mixed)
        <p class="rounded-md border border-[var(--line-subtle)] bg-[var(--surface-sunken)] px-3 py-2 text-xs
                  text-[var(--text-muted)]">
            {{ __('These projects are budgeted in more than one currency. Planvio ships no exchange rates, so the totals above are withheld rather than summed at an invented one — the table below is exact, per project.') }}
        </p>
    @endif

    @if ($unconverted !== [])
        <p class="rounded-md border border-caution-500/40 bg-caution-50 px-3 py-2 text-xs text-caution-700
                  dark:bg-caution-950 dark:text-caution-100">
            {{ __('Some expenses were booked in another currency (:codes) and are reported separately rather than converted.', [
                'codes' => implode(', ', array_keys($unconverted)),
            ]) }}
        </p>
    @endif

    @if ($groups === [])
        <x-ui.card flush>
            <x-ui.empty-state icon="icon.document"
                              :title="__('No budget to report on')"
                              :description="__('A budget report needs a planned figure on a project or an expense booked against one. Neither has been recorded in this scope.')">
                <x-slot:actions>
                    <x-ui.button variant="secondary" size="md" :href="route('app.projects.index', $workspace)">
                        {{ __('Open a project') }}
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <x-ui.card :title="__('Planned against spent')"
                   :subtitle="__('Each bar is one project, in that project\'s own currency.')">
            <x-chart.stacked-bar orientation="horizontal" :groups="$groups"
                                 :label-heading="__('Project')"
                                 :caption="__('Budget spent, remaining and overspend by project')" />
        </x-ui.card>

        <x-ui.card :title="__('By project')" flush>
            <div class="hidden overflow-x-auto md:block">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="border-b border-[var(--line-subtle)] text-start text-2xs uppercase tracking-wider text-[var(--text-muted)]">
                            <th scope="col" class="px-4 py-2 font-semibold">{{ __('Project') }}</th>
                            <th scope="col" class="px-3 py-2 font-semibold">{{ __('Currency') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-semibold">{{ __('Planned') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-semibold">{{ __('Spent') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-semibold">{{ __('Variance') }}</th>
                            <th scope="col" class="w-32 px-3 py-2 font-semibold">{{ __('Used') }}</th>
                            <th scope="col" class="px-4 py-2 text-end font-semibold">{{ __('Logged') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--line-subtle)]">
                        @foreach ($rows as $row)
                            @php $budget = $budgets[$row['id']] ?? null; @endphp
                            @continue(! $budget instanceof ProjectBudget)
                            <tr class="transition-colors hover:bg-[var(--surface-hover)]" wire:key="budget-{{ $row['id'] }}">
                                <th scope="row" class="max-w-56 truncate px-4 py-2 text-start font-medium text-[var(--text-strong)]">
                                    {{ $row['name'] }}
                                </th>
                                <td class="px-3 py-2 font-mono text-[10px] text-[var(--text-muted)]">{{ $budget->currency }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ $budget->planned() ?? '—' }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ $budget->actual() }}</td>
                                <td class="px-3 py-2 text-end tabular-nums {{ $budget->isOverBudget() ? 'font-semibold text-critical-600 dark:text-critical-500' : 'text-[var(--text-muted)]' }}">
                                    {{ $budget->variance() ?? '—' }}
                                </td>
                                <td class="px-3 py-2">
                                    @if ($budget->utilisation() !== null)
                                        <x-ui.progress :value="min(100, (int) round($budget->utilisation()))" size="sm" show-label
                                                       :color="$budget->isOverBudget() ? 'bg-critical-500' : null"
                                                       :label="__('Budget used on :project', ['project' => $row['name']])" />
                                    @else
                                        <span class="text-[var(--text-subtle)]">{{ __('No budget set') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-end tabular-nums text-[var(--text-muted)]">
                                    {{ $this->hours($budget->loggedMinutes) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <ul class="divide-y divide-[var(--line-subtle)] md:hidden">
                @foreach ($rows as $row)
                    @php $budget = $budgets[$row['id']] ?? null; @endphp
                    @continue(! $budget instanceof ProjectBudget)
                    <li class="px-4 py-3" wire:key="budget-card-{{ $row['id'] }}">
                        <div class="flex items-baseline justify-between gap-2">
                            <p dir="auto" class="min-w-0 flex-1 truncate text-sm font-medium text-[var(--text-strong)]">{{ $row['name'] }}</p>
                            <span class="shrink-0 text-xs tabular-nums text-[var(--text-muted)]">
                                {{ $budget->actual() }} {{ $budget->currency }}
                            </span>
                        </div>
                        @if ($budget->utilisation() !== null)
                            <x-ui.progress :value="min(100, (int) round($budget->utilisation()))" size="sm" show-label class="mt-2"
                                           :color="$budget->isOverBudget() ? 'bg-critical-500' : null"
                                           :label="__('Budget used on :project', ['project' => $row['name']])" />
                            <p class="mt-1 text-2xs text-[var(--text-muted)]">
                                {{ __('Planned :planned · variance :variance', [
                                    'planned' => $budget->planned(),
                                    'variance' => $budget->variance(),
                                ]) }}
                            </p>
                        @else
                            <p class="mt-1 text-2xs text-[var(--text-subtle)]">{{ __('No budget set') }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</div>

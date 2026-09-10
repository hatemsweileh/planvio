@php
    use App\Support\Bidi;

    $range = $this->range;
    $scoped = $this->scopedProject();
@endphp

<div class="page py-5">

    {{-- ---------------------------------------------------------------- --}}
    {{-- Header                                                           --}}
    {{-- ---------------------------------------------------------------- --}}
    <header class="flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-base font-semibold tracking-tight text-[var(--text-strong)]">{{ __('Reports') }}</h1>
            <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                {{-- Isolated: an Arabic word before a date makes its digits Arabic numbers,
                     which hands the hyphens to the paragraph and renders 2026-08-11 backwards. --}}
                {{ __(':from to :to', [
                    'from' => Bidi::ltr($range->fromDate()),
                    'to' => Bidi::ltr($range->toDate()),
                ]) }}
                <span class="text-[var(--text-subtle)]">
                    · {{ trans_choice('{1}:count day|[2,*]:count days', $range->lengthInDays(), ['count' => $range->lengthInDays()]) }}
                    · {{ $this->timezone() }}
                </span>
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-1.5">
            <div wire:loading.delay class="pe-1 text-[var(--text-subtle)]"
                 wire:target="preset,customFrom,customTo,projectFilter,selectTab">
                <x-ui.spinner class="size-4" />
            </div>

            <label class="sr-only" for="reports-project">{{ __('Project') }}</label>
            <x-ui.select id="reports-project" size="md" wire:model.live="projectFilter"
                         :options="$this->projectOptions" class="w-44 sm:w-56" />

            <label class="sr-only" for="reports-range">{{ __('Date range') }}</label>
            <x-ui.select id="reports-range" size="md" wire:model.live="preset"
                         :options="$this->presets()" class="w-36" />

            <x-ui.button variant="secondary" size="md" icon="icon.document" wire:click="export">
                {{ __('Export CSV') }}
            </x-ui.button>

            @if ($scoped)
                <x-ui.button variant="secondary" size="md" icon="icon.chart"
                             :href="route('app.projects.report', [$workspace, $scoped])">
                    {{ __('Status report') }}
                </x-ui.button>
            @endif
        </div>
    </header>

    @if ($preset === 'custom')
        <div class="mt-3 flex flex-wrap items-end gap-2 rounded-lg border border-[var(--line-subtle)]
                    bg-[var(--surface-panel)] px-3 py-2.5 shadow-panel">
            <x-ui.field :label="__('From')" for="reports-from" class="w-40">
                <x-ui.input id="reports-from" type="date" size="sm" wire:model.live.debounce.500ms="customFrom" />
            </x-ui.field>
            <x-ui.field :label="__('To')" for="reports-to" class="w-40">
                <x-ui.input id="reports-to" type="date" size="sm" wire:model.live.debounce.500ms="customTo" />
            </x-ui.field>
            <p class="pb-1.5 text-2xs text-[var(--text-subtle)]">
                {{ __('Both days are included. A range typed backwards is read the right way round.') }}
            </p>
        </div>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Tabs                                                             --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.tabs class="mt-4">
        @foreach ($this->tabs() as $item)
            <x-ui.tab :active="$tab === $item['key']" :icon="$item['icon']"
                      id="reports-tab-{{ $item['key'] }}"
                      aria-controls="reports-panel"
                      wire:click="selectTab('{{ $item['key'] }}')"
                      wire:key="reports-tab-{{ $item['key'] }}">{{ $item['label'] }}</x-ui.tab>
        @endforeach
    </x-ui.tabs>

    <div class="mt-4"
         id="reports-panel"
         role="tabpanel"
         aria-labelledby="reports-tab-{{ $tab }}"
         tabindex="0"
         wire:loading.class="opacity-60"
         wire:target="preset,customFrom,customTo,projectFilter,selectTab">
        @if ($this->projects->isEmpty())
            <x-ui.card flush>
                <x-ui.empty-state icon="icon.chart"
                                  :title="__('No project to report on')"
                                  :description="__('Reports are built from the projects you can open. Create one, or ask a workspace admin to add you to an existing project.')">
                    <x-slot:actions>
                        @can('project.create', $workspace)
                            <x-ui.button variant="primary" size="md" icon="icon.plus"
                                         :href="route('app.projects.create', $workspace)">
                                {{ __('Create a project') }}
                            </x-ui.button>
                        @endcan
                        <x-ui.button variant="secondary" size="md" :href="route('app.projects.index', $workspace)">
                            {{ __('Browse projects') }}
                        </x-ui.button>
                    </x-slot:actions>
                </x-ui.empty-state>
            </x-ui.card>
        @else
            @if ($this->projectsTruncated())
                <p class="mb-3 rounded-md border border-caution-500/40 bg-caution-50 px-3 py-2 text-xs
                          text-caution-700 dark:bg-caution-950 dark:text-caution-100">
                    {{ __('This workspace has more active projects than one report can chart legibly. Pick a project above to report on it exactly.') }}
                </p>
            @endif

            @include('livewire.app.reports._'.$tab)
        @endif
    </div>
</div>

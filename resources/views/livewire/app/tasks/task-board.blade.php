@php
    $columns = $this->columns;
    $cards = $this->cards;
    $counts = $this->counts;
    $totalCards = array_sum($counts);
@endphp

<div class="relative flex h-full min-h-0 flex-col">

    @include('livewire.app.tasks._project-header')

    {{-- Controls ---------------------------------------------------------- --}}
    <div class="shrink-0 border-b border-[var(--line-subtle)] bg-[var(--surface-panel)] px-4 py-2 sm:px-5">
        <div class="flex flex-wrap items-center gap-2">
            {{-- Full width on a phone so the search box is a search box and not a stub. --}}
            <div class="w-full sm:min-w-0 sm:flex-1">
                @include('livewire.app.tasks._filters')
            </div>

            <div class="flex shrink-0 items-center gap-1.5">
                @include('livewire.app.tasks._saved-views')
            </div>
        </div>
    </div>

    @if ($columns->isEmpty())
        <div class="mx-auto w-full max-w-2xl px-4 py-6">
            <x-ui.card flush>
                <x-ui.empty-state icon="icon.board"
                                  :title="__('This project has no columns')"
                                  :description="__('A board is made of statuses. Add the columns this project works in — backlog, in progress, review, done — and the cards will follow.')">
                    <x-slot:actions>
                        @can('update', $project)
                            <x-ui.button variant="primary" size="md" icon="icon.cog"
                                         :href="route('app.projects.settings', [$workspace, $project])">
                                {{ __('Set up columns') }}
                            </x-ui.button>
                        @endcan
                    </x-slot:actions>
                </x-ui.empty-state>
            </x-ui.card>
        </div>
    @elseif ($totalCards === 0 && $this->hasFilters())
        <div class="mx-auto w-full max-w-2xl px-4 py-6">
            <x-ui.card flush>
                <x-ui.empty-state icon="icon.search"
                                  :title="__('Nothing matches these filters')"
                                  :description="__('Every column is empty under the current filter set. Loosen one, or clear them all.')">
                    <x-slot:actions>
                        <x-ui.button variant="secondary" size="md" wire:click="clearFilters">{{ __('Clear filters') }}</x-ui.button>
                    </x-slot:actions>
                </x-ui.empty-state>
            </x-ui.card>
        </div>
    @else
        {{-- The board itself. Scrolls sideways; each column scrolls on its own. --}}
        <div class="scrollbar-thin min-h-0 flex-1 overflow-x-auto overflow-y-hidden"
             wire:loading.class="opacity-70"
             wire:target="search,clearFilters,toggleFilter,setDueRange,applySavedView">
            <div class="flex h-full items-stretch gap-3 p-3 sm:p-4">

                @foreach ($columns as $status)
                    @php
                        $statusId = (int) $status->getKey();
                        $columnCards = $cards[$statusId] ?? collect();
                        $count = $counts[$statusId] ?? 0;
                    @endphp

                    <section wire:key="column-{{ $statusId }}"
                             class="flex h-full w-[17.5rem] shrink-0 flex-col rounded-lg border border-[var(--line-subtle)]
                                    bg-[var(--surface-sunken)]"
                             aria-labelledby="column-heading-{{ $statusId }}">

                        <header class="flex shrink-0 items-center gap-2 border-b border-[var(--line-subtle)] px-2.5 py-2">
                            <x-ui.status-dot :color="$status->color ?? 'gray'" />
                            <h2 dir="auto" id="column-heading-{{ $statusId }}"
                                class="min-w-0 flex-1 truncate text-xs font-semibold uppercase tracking-wider
                                       text-[var(--text-DEFAULT)]">
                                {{ $status->name }}
                            </h2>
                            <span class="rounded bg-[var(--surface-active)] px-1.5 text-2xs font-medium tabular-nums
                                         text-[var(--text-muted)]">{{ $count }}</span>

                            @can('task.create', $project)
                                <x-ui.tooltip :label="__('Add to :column', ['column' => $status->name])">
                                    <button type="button"
                                            x-on:click="$dispatch('open-quick-create', { type: 'task', project: {{ $project->getKey() }}, status: {{ $statusId }} })"
                                            class="grid size-6 shrink-0 place-items-center rounded text-[var(--text-subtle)]
                                                   transition-colors hover:bg-[var(--surface-hover)] hover:text-[var(--text-DEFAULT)]"
                                            aria-label="{{ __('Add a task to :column', ['column' => $status->name]) }}">
                                        <x-icon.plus class="size-3.5" />
                                    </button>
                                </x-ui.tooltip>
                            @endcan
                        </header>

                        <div x-data="kanbanColumn({{ $statusId }})" class="min-h-0 flex-1 overflow-hidden">
                            <div x-ref="list"
                                 data-status-id="{{ $statusId }}"
                                 class="scrollbar-thin h-full space-y-2 overflow-y-auto p-2">

                                @foreach ($columnCards as $task)
                                    @include('livewire.app.tasks._card', ['task' => $task])
                                @endforeach

                                @if ($columnCards->isEmpty())
                                    {{-- A real drop target, not just a message: an empty column must accept a card. --}}
                                    <p class="rounded-md border border-dashed border-[var(--line-DEFAULT)] px-3 py-6
                                              text-center text-2xs leading-relaxed text-[var(--text-subtle)]">
                                        {{ __('Nothing here.') }}<br>{{ __('Drop a card, or use a card’s menu.') }}
                                    </p>
                                @endif
                            </div>
                        </div>

                        @if ($this->hasMore($statusId))
                            <div class="shrink-0 border-t border-[var(--line-subtle)] p-1.5">
                                <x-ui.button variant="ghost" size="sm" class="w-full"
                                             wire:click="loadMore({{ $statusId }})" wire:target="loadMore({{ $statusId }})">
                                    {{ __('Load :count more', ['count' => min(config('planvio.pagination.board_column'), $count - $this->limitFor($statusId))]) }}
                                </x-ui.button>
                            </div>
                        @endif
                    </section>
                @endforeach

                {{-- A tail spacer so the last column is not flush against the viewport edge. --}}
                <div class="w-1 shrink-0" aria-hidden="true"></div>
            </div>
        </div>

        @if ($totalCards === 0)
            <div class="pointer-events-none absolute inset-x-0 bottom-0 flex justify-center p-6">
                <div class="pointer-events-auto rounded-lg border border-[var(--line-subtle)] bg-[var(--surface-panel)]
                            px-4 py-3 text-center shadow-raised">
                    <p class="text-sm font-semibold text-[var(--text-strong)]">{{ __('The board is empty') }}</p>
                    <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                        {{ __('Cards appear in the column that matches their status.') }}
                    </p>
                    @can('task.create', $project)
                        <x-ui.button variant="primary" size="sm" icon="icon.plus" class="mt-2"
                                     x-on:click="$dispatch('open-quick-create', { type: 'task', project: {{ $project->getKey() }} })">
                            {{ __('New task') }}
                        </x-ui.button>
                    @endcan
                </div>
            </div>
        @endif
    @endif
</div>

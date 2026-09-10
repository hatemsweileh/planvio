@php
    use App\Support\Formats;

    /**
     * The task drawer.
     *
     * It opens over the calendar rather than navigating away, so closing it returns you to
     * the exact month, filters and scroll position you were in. It also carries the
     * pointer-free way to reschedule: a real date field and three relative buttons, which
     * is what makes dragging an enhancement rather than the only route.
     */
    $task = $this->drawerTask;
@endphp

<x-ui.drawer wire:model="drawerOpen" size="sm" :title="__('Task')">
    @if ($task)
        <header class="flex items-start justify-between gap-3 border-b border-[var(--line-subtle)] px-4 py-3">
            <div class="min-w-0">
                <p class="font-mono text-2xs text-[var(--text-subtle)]"><x-ui.bidi>{{ $task->key }}</x-ui.bidi></p>
                <h2 class="mt-0.5 text-sm font-semibold leading-snug text-[var(--text-strong)]">
                    {{ $task->title }}
                </h2>
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

            <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2.5 text-xs">
                <div>
                    <dt class="text-2xs uppercase tracking-wide text-[var(--text-subtle)]">{{ __('Project') }}</dt>
                    <dd class="mt-0.5 truncate text-[var(--text-DEFAULT)]">{{ $task->project?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-2xs uppercase tracking-wide text-[var(--text-subtle)]">{{ __('Assignee') }}</dt>
                    <dd class="mt-0.5 flex items-center gap-1.5">
                        @if ($task->assignee)
                            <x-ui.avatar :user="$task->assignee" size="xs" />
                            <span class="truncate text-[var(--text-DEFAULT)]">{{ $task->assignee->name }}</span>
                        @else
                            <span class="text-[var(--text-subtle)]">{{ __('Unassigned') }}</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-2xs uppercase tracking-wide text-[var(--text-subtle)]">{{ __('Start') }}</dt>
                    <dd class="mt-0.5 whitespace-nowrap tabular-nums text-[var(--text-DEFAULT)]">
                        {{ Formats::date($task->start_date) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-2xs uppercase tracking-wide text-[var(--text-subtle)]">{{ __('Due') }}</dt>
                    <dd class="mt-0.5 whitespace-nowrap tabular-nums text-[var(--text-DEFAULT)]">
                        {{ Formats::date($task->due_date) }}
                    </dd>
                </div>
            </dl>

            <div class="mt-3">
                <p class="text-2xs uppercase tracking-wide text-[var(--text-subtle)]">{{ __('Progress') }}</p>
                <x-ui.progress :value="$task->progress" show-label class="mt-1.5" :label="__('Task progress')" />
            </div>

            @if ($excerpt = $this->drawerExcerpt($task))
                <p class="mt-3 whitespace-pre-line text-xs leading-relaxed text-[var(--text-muted)]">{{ $excerpt }}</p>
            @endif

            @can('update', $task)
                <div class="mt-4 rounded-lg border border-[var(--line-subtle)] bg-[var(--surface-sunken)] p-3">
                    <x-ui.field :label="__('Reschedule')" for="calendar-reschedule"
                                :hint="__('Dragging the card does the same thing. This is here so a pointer is never required.')">
                        <div class="flex items-center gap-1.5">
                            <x-ui.input id="calendar-reschedule" type="date" size="sm"
                                        wire:model="rescheduleTo" class="flex-1" />
                            <x-ui.button variant="primary" size="sm" wire:click="reschedule"
                                         wire:target="reschedule">{{ __('Move') }}</x-ui.button>
                        </div>
                    </x-ui.field>

                    <div class="mt-2 flex flex-wrap items-center gap-1">
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
                </div>
            @endcan
        </div>

        <footer class="flex items-center justify-between gap-2 border-t border-[var(--line-subtle)]
                       bg-[var(--surface-sunken)] px-4 py-3">
            <x-ui.button variant="ghost" size="md" wire:click="closeTask">{{ __('Close') }}</x-ui.button>
            <x-ui.button variant="secondary" size="md"
                         :href="route('app.tasks.show', [$workspace, $task])"
                         trailing-icon="icon.chevron-right" class="[&>svg]:flip-rtl">
                {{ __('Open task') }}
            </x-ui.button>
        </footer>
    @endif
</x-ui.drawer>

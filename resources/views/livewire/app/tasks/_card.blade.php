{{--
    One board card.

    The whole card is the drag handle, so there is no thin grip to hunt for, and the status
    menu is marked `data-no-drag` so reaching for it never starts a drag. That menu is not a
    convenience: it is the keyboard and touch route to the same move, and dragging must
    never be the only way to do something.
--}}
@php
    $subtasks = $task->relationLoaded('subtasks') ? $task->getRelation('subtasks') : collect();
    $subtaskTotal = $subtasks->count();
    $subtaskDone = $subtasks->filter(fn ($subtask) => $subtask->completed_at !== null)->count();
    $canUpdate = auth()->user()?->can('update', $task) ?? false;
@endphp

<article wire:key="card-{{ $task->getKey() }}"
         data-task-id="{{ $task->getKey() }}"
         data-drag-handle
         class="group cursor-grab rounded-lg border border-[var(--line-subtle)] bg-[var(--surface-panel)] p-2.5
                shadow-panel transition-[border-color,box-shadow] hover:border-[var(--line-DEFAULT)]
                hover:shadow-raised active:cursor-grabbing">

    <div class="flex items-start gap-2">
        <a href="{{ route('app.tasks.show', [$workspace, $task]) }}"
           x-on:click.prevent="$dispatch('open-task', { taskId: {{ $task->getKey() }} })"
           class="min-w-0 flex-1 text-start">
            {{-- dir="auto", as in the list row: a title is the author's words, and a card wraps
                 to two or three lines, which is where a paragraph direction that is not the
                 title's own puts the closing punctuation at the wrong end. --}}
            <span dir="auto"
                  class="block text-sm font-medium leading-snug text-[var(--text-strong)]">{{ $task->title }}</span>
        </a>

        @if ($canUpdate)
            <div data-no-drag class="shrink-0">
                <x-ui.dropdown align="end" width="w-56">
                    <x-slot:trigger>
                        <button type="button"
                                class="grid size-6 place-items-center rounded text-[var(--text-subtle)] opacity-0
                                       transition-colors hover:bg-[var(--surface-hover)] hover:text-[var(--text-DEFAULT)]
                                       focus-visible:opacity-100 group-hover:opacity-100"
                                aria-label="{{ __('Move :key to another column', ['key' => $task->key]) }}">
                            <x-icon.dots class="size-4" />
                        </button>
                    </x-slot:trigger>

                    <p class="px-2 pb-1 pt-1 text-2xs font-semibold uppercase tracking-wider text-[var(--text-subtle)]">
                        {{ __('Move to') }}
                    </p>

                    @foreach ($this->columns as $column)
                        <x-ui.dropdown-item wire:click="moveToStatus({{ $task->getKey() }}, {{ $column->getKey() }})"
                                            :active="(int) $task->status_id === (int) $column->getKey()">
                            <span class="flex items-center gap-2 overflow-hidden">
                                <x-ui.tick :on="(int) $task->status_id === (int) $column->getKey()" />
                                <x-ui.status-dot :color="$column->color ?? 'gray'" />
                                <span dir="auto" class="truncate">{{ $column->name }}</span>
                            </span>
                        </x-ui.dropdown-item>
                    @endforeach

                    <x-ui.dropdown-separator />

                    <x-ui.dropdown-item icon="icon.chevron-right" class="[&>svg]:flip-rtl"
                                        x-on:click="$dispatch('open-task', { taskId: {{ $task->getKey() }} })">
                        {{ __('Open task') }}
                    </x-ui.dropdown-item>
                </x-ui.dropdown>
            </div>
        @endif
    </div>

    @if ($task->tags->isNotEmpty())
        <div class="mt-1.5 flex flex-wrap gap-1">
            @foreach ($task->tags as $tag)
                <x-ui.badge :color="$tag->color ?? 'gray'" size="sm">{{ $tag->name }}</x-ui.badge>
            @endforeach
        </div>
    @endif

    <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1">
        <span class="font-mono text-2xs tabular-nums text-[var(--text-subtle)]"><x-ui.bidi>{{ $task->key }}</x-ui.bidi></span>

        @if ($task->priority !== \App\Enums\Priority::None)
            <x-ui.tooltip :label="__('Priority: :priority', ['priority' => $task->priority->label()])">
                <span class="inline-flex items-center gap-1 whitespace-nowrap text-2xs font-medium text-[var(--text-muted)]">
                    <x-ui.status-dot :color="$task->priority->color()" size="sm" />
                    {{ $task->priority->label() }}
                </span>
            </x-ui.tooltip>
        @endif

        @if ($task->due_date)
            <span class="inline-flex items-center gap-1 whitespace-nowrap text-2xs tabular-nums
                         {{ $task->is_overdue ? 'font-semibold text-critical-600' : 'text-[var(--text-muted)]' }}">
                <x-icon.calendar class="size-3" />
                {{ $task->due_date->isoFormat('D MMM') }}
            </span>
        @endif

        <span class="ms-auto flex items-center gap-2">
            @include('livewire.app.tasks._indicators', ['task' => $task])

            @if ($task->assignee)
                <x-ui.tooltip :label="$task->assignee->name">
                    <x-ui.avatar :user="$task->assignee" size="xs" />
                </x-ui.tooltip>
            @endif
        </span>
    </div>

    @if ($subtaskTotal > 0)
        <div class="mt-2 flex items-center gap-2">
            <x-ui.progress :value="$subtaskDone" :max="$subtaskTotal" size="xs"
                           :label="__('Subtasks of :key', ['key' => $task->key])" />
            <span class="shrink-0 text-2xs tabular-nums text-[var(--text-subtle)]">{{ $subtaskDone }}/{{ $subtaskTotal }}</span>
        </div>
    @endif
</article>

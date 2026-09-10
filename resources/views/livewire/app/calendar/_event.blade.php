@php
    /**
     * One chip on the calendar.
     *
     * A task is a button: clicking it opens the drawer, dragging it moves its due date, and
     * — because a drag is unusable with a keyboard or a screen reader — Alt+← / Alt+→ on a
     * focused chip shifts the same date by a day through the same server method.
     *
     * A milestone or a project date is a link to the record it belongs to. Neither is
     * draggable: a milestone date moves through the milestone, and a project's target date
     * through project settings, where the consequences are visible.
     *
     * Expects `$item` and `$dense`.
     */
    $tone = $item['completed'] ?? false
        ? 'text-[var(--text-subtle)] line-through decoration-[var(--line-strong)]'
        : (($item['overdue'] ?? false) ? 'text-critical-600 dark:text-critical-500' : 'text-[var(--text-DEFAULT)]');

    $shell = 'group flex w-full items-center gap-1.5 rounded px-1.5 '
        .($dense ? 'py-0.5 text-2xs' : 'py-1 text-xs')
        .' text-start transition-colors hover:bg-[var(--surface-hover)] '
        .'focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[var(--accent)]';
@endphp

@if ($item['type'] === 'task')
    <button type="button"
            wire:click="openTask({{ $item['id'] }})"
            @if ($item['draggable'])
                draggable="true"
                x-on:dragstart="$event.dataTransfer.setData('text/plain', '{{ $item['id'] }}'); $event.dataTransfer.effectAllowed = 'move'"
                x-on:keydown.alt.arrow-left.prevent="$wire.shiftTask({{ $item['id'] }}, -1)"
                x-on:keydown.alt.arrow-right.prevent="$wire.shiftTask({{ $item['id'] }}, 1)"
            @endif
            class="{{ $shell }} {{ $item['draggable'] ? 'cursor-grab active:cursor-grabbing' : '' }}"
            aria-label="{{ $item['reference'] }} · {{ $item['title'] }}{{ $item['draggable'] ? ' · '.__('Alt plus left or right arrow moves this by a day') : '' }}">
        <span class="size-1.5 shrink-0 rounded-full" style="background-color: {{ $item['color'] }}"
              aria-hidden="true"></span>
        <span dir="auto" class="truncate {{ $tone }}">{{ $item['title'] }}</span>
        @unless ($dense)
            <span class="ms-auto shrink-0 font-mono text-[10px] text-[var(--text-subtle)]"><x-ui.bidi>{{ $item['reference'] }}</x-ui.bidi></span>
        @endunless
    </button>

@elseif ($item['type'] === 'milestone')
    <a href="{{ route('app.projects.show', [$workspace, $item['projectSlug']]) }}"
       class="{{ $shell }}"
       aria-label="{{ __('Milestone') }}: {{ $item['title'] }}">
        <x-icon.flag class="size-3 shrink-0" style="color: {{ $item['color'] }}" aria-hidden="true" />
        <span dir="auto" class="truncate font-medium {{ $tone }}">{{ $item['title'] }}</span>
        @unless ($dense)
            <span class="ms-auto shrink-0 text-[10px] text-[var(--text-subtle)]">{{ $item['statusName'] }}</span>
        @endunless
    </a>

@else
    <a href="{{ route('app.projects.show', [$workspace, $item['projectSlug']]) }}"
       class="{{ $shell }}"
       aria-label="{{ $item['title'] }}">
        <x-icon.folder class="size-3 shrink-0" style="color: {{ $item['color'] }}" aria-hidden="true" />
        <span dir="auto" class="truncate text-[var(--text-muted)]">{{ $item['title'] }}</span>
        @unless ($dense)
            <span class="ms-auto shrink-0 text-[10px] text-[var(--text-subtle)]">
                {{ $item['kind'] === 'start' ? __('Start') : __('Target') }}
            </span>
        @endunless
    </a>
@endif

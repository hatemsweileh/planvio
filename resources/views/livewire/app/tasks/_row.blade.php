{{--
    One table row.

    The title is a real link to the task's own page, intercepted so a plain click opens the
    drawer instead. That way the middle-click, the ctrl-click and the screen reader all get
    a URL, while the common case keeps your place in the list.
--}}
@php
    $isSelected = in_array((int) $task->getKey(), $selected);
    $canUpdate = auth()->user()?->can('update', $task) ?? false;
    $canAssign = auth()->user()?->can('assign', $task) ?? false;
@endphp

<tr wire:key="row-{{ $task->getKey() }}"
    class="group border-b border-[var(--line-subtle)] transition-colors
           {{ $isSelected ? 'bg-[var(--accent-soft)]' : 'bg-[var(--surface-panel)] hover:bg-[var(--surface-hover)]' }}">

    <td class="py-1.5 ps-4 pe-0 align-middle sm:ps-5">
        <input type="checkbox"
               value="{{ $task->getKey() }}"
               wire:model.live="selected"
               @checked($isSelected)
               class="size-3.5 rounded border-[var(--line-strong)] bg-[var(--surface-panel)] text-[var(--accent)]
                      checked:border-[var(--accent)] checked:bg-[var(--accent)]
                      focus:ring-2 focus:ring-[var(--accent-ring)]"
               aria-label="{{ __('Select :key', ['key' => $task->key]) }}">
    </td>

    @if ($this->showsColumn('key'))
        <td class="whitespace-nowrap px-2 py-1.5 align-middle font-mono text-2xs tabular-nums text-[var(--text-subtle)]">
            {{ $task->key }}
        </td>
    @endif

    @if ($this->showsColumn('title'))
        <td class="w-full max-w-0 px-2 py-1.5 align-middle">
            <div class="flex items-center gap-2">
                {{-- dir="auto": a title truncated inside an RTL row would otherwise lose its
                     beginning rather than its end, because the ellipsis falls at the inline end. --}}
                <a href="{{ route('app.tasks.show', [$workspace, $task]) }}"
                   dir="auto"
                   x-on:click.prevent="$dispatch('open-task', { taskId: {{ $task->getKey() }} })"
                   class="truncate text-sm font-medium text-[var(--text-strong)] transition-colors
                          hover:text-[var(--accent)] {{ $task->is_completed ? 'line-through decoration-[var(--line-strong)]' : '' }}">
                    {{ $task->title }}
                </a>

                @if ($task->ai_generated)
                    <x-ui.tooltip :label="__('Drafted by Planvio AI')">
                        <x-icon.sparkles class="size-3.5 shrink-0 text-accent-500" />
                    </x-ui.tooltip>
                @endif

                @foreach ($task->tags as $tag)
                    <x-ui.badge :color="$tag->color ?? 'gray'" size="sm" class="hidden lg:inline-flex">{{ $tag->name }}</x-ui.badge>
                @endforeach

                @include('livewire.app.tasks._indicators', ['task' => $task])
            </div>
        </td>
    @endif

    @if ($this->showsColumn('status'))
        <td class="whitespace-nowrap px-2 py-1.5 align-middle">
            @if ($canUpdate)
                <x-ui.dropdown width="w-56">
                    <x-slot:trigger>
                        <button type="button"
                                class="inline-flex h-6 items-center gap-1.5 rounded px-1.5 text-xs
                                       text-[var(--text-DEFAULT)] transition-colors hover:bg-[var(--surface-active)]"
                                aria-label="{{ __('Change status of :key', ['key' => $task->key]) }}">
                            <x-ui.status-dot :color="$task->status->color ?? 'gray'" size="sm" />
                            <span dir="auto" class="max-w-28 truncate">{{ $task->status?->name }}</span>
                        </button>
                    </x-slot:trigger>

                    @foreach ($this->statusOptions as $status)
                        <x-ui.dropdown-item wire:click="setStatus({{ $task->getKey() }}, {{ $status->getKey() }})"
                                            :active="(int) $task->status_id === (int) $status->getKey()">
                            <span class="flex items-center gap-2 overflow-hidden">
                                <x-ui.tick :on="(int) $task->status_id === (int) $status->getKey()" />
                                <x-ui.status-dot :color="$status->color ?? 'gray'" />
                                <span dir="auto" class="truncate">{{ $status->name }}</span>
                            </span>
                        </x-ui.dropdown-item>
                    @endforeach
                </x-ui.dropdown>
            @else
                <x-ui.badge :color="$task->status->color ?? 'gray'" size="sm" dot>{{ $task->status?->name }}</x-ui.badge>
            @endif
        </td>
    @endif

    @if ($this->showsColumn('priority'))
        <td class="whitespace-nowrap px-2 py-1.5 align-middle">
            @if ($canUpdate)
                <x-ui.dropdown width="w-44">
                    <x-slot:trigger>
                        <button type="button"
                                class="inline-flex h-6 items-center gap-1.5 rounded px-1.5 text-xs
                                       text-[var(--text-DEFAULT)] transition-colors hover:bg-[var(--surface-active)]"
                                aria-label="{{ __('Change priority of :key', ['key' => $task->key]) }}">
                            <x-ui.status-dot :color="$task->priority->color()" size="sm" />
                            {{ $task->priority->label() }}
                        </button>
                    </x-slot:trigger>

                    @foreach ($this->priorityOptions() as $priority)
                        <x-ui.dropdown-item wire:click="setPriority({{ $task->getKey() }}, '{{ $priority->value }}')"
                                            :active="$task->priority === $priority">
                            <span class="flex items-center gap-2 overflow-hidden">
                                <x-ui.tick :on="$task->priority === $priority" />
                                <x-ui.status-dot :color="$priority->color()" />
                                <span class="truncate">{{ $priority->label() }}</span>
                            </span>
                        </x-ui.dropdown-item>
                    @endforeach
                </x-ui.dropdown>
            @else
                <x-ui.badge :color="$task->priority->color()" size="sm">{{ $task->priority->label() }}</x-ui.badge>
            @endif
        </td>
    @endif

    @if ($this->showsColumn('assignee'))
        <td class="whitespace-nowrap px-2 py-1.5 align-middle">
            @if ($canAssign)
                <x-ui.dropdown width="w-60">
                    <x-slot:trigger>
                        <button type="button"
                                class="inline-flex h-6 max-w-40 items-center gap-1.5 rounded px-1 text-xs
                                       text-[var(--text-DEFAULT)] transition-colors hover:bg-[var(--surface-active)]"
                                aria-label="{{ __('Assign :key', ['key' => $task->key]) }}">
                            @if ($task->assignee)
                                <x-ui.avatar :user="$task->assignee" size="xs" />
                                <span dir="auto" class="truncate">{{ $task->assignee->name }}</span>
                            @else
                                <span class="grid size-5 shrink-0 place-items-center rounded-full border border-dashed
                                             border-[var(--line-strong)] text-[var(--text-subtle)]">
                                    <x-icon.users class="size-3" />
                                </span>
                                <span class="text-[var(--text-subtle)]">{{ __('Unassigned') }}</span>
                            @endif
                        </button>
                    </x-slot:trigger>

                    <x-ui.dropdown-item wire:click="setAssignee({{ $task->getKey() }}, null)"
                                        :active="$task->assignee_id === null">
                        <span class="flex items-center gap-2 overflow-hidden">
                            <x-ui.tick :on="$task->assignee_id === null" />
                            <span class="truncate">{{ __('Unassigned') }}</span>
                        </span>
                    </x-ui.dropdown-item>

                    <x-ui.dropdown-separator />

                    <div class="max-h-64 overflow-y-auto scrollbar-thin">
                        @foreach ($this->memberOptions as $member)
                            <x-ui.dropdown-item wire:click="setAssignee({{ $task->getKey() }}, {{ $member->getKey() }})"
                                                :active="(int) $task->assignee_id === (int) $member->getKey()">
                                <span class="flex items-center gap-2 overflow-hidden">
                                    <x-ui.tick :on="(int) $task->assignee_id === (int) $member->getKey()" />
                                    <x-ui.avatar :user="$member" size="xs" />
                                    <span dir="auto" class="truncate">{{ $member->name }}</span>
                                </span>
                            </x-ui.dropdown-item>
                        @endforeach
                    </div>
                </x-ui.dropdown>
            @elseif ($task->assignee)
                <span class="inline-flex items-center gap-1.5 text-xs">
                    <x-ui.avatar :user="$task->assignee" size="xs" />
                    <span class="max-w-32 truncate">{{ $task->assignee->name }}</span>
                </span>
            @else
                <span class="text-xs text-[var(--text-subtle)]">{{ __('Unassigned') }}</span>
            @endif
        </td>
    @endif

    @if ($this->showsColumn('start_date'))
        <td class="whitespace-nowrap px-2 py-1.5 align-middle text-xs tabular-nums text-[var(--text-muted)]">
            {{ $task->start_date?->isoFormat('D MMM') ?? '—' }}
        </td>
    @endif

    @if ($this->showsColumn('due_date'))
        <td class="whitespace-nowrap px-2 py-1.5 align-middle text-xs tabular-nums
                   {{ $task->is_overdue ? 'font-medium text-critical-600' : 'text-[var(--text-muted)]' }}">
            {{ $task->due_date?->isoFormat('D MMM') ?? '—' }}
        </td>
    @endif

    @if ($this->showsColumn('milestone'))
        <td dir="auto" class="max-w-36 truncate px-2 py-1.5 align-middle text-xs text-[var(--text-muted)]">
            {{ $task->milestone?->name ?? '—' }}
        </td>
    @endif

    @if ($this->showsColumn('progress'))
        <td class="px-2 py-1.5 align-middle">
            {{-- A fixed inner width: an auto-laid-out table would otherwise squeeze the bar
                 down to nothing to give the title column the room. --}}
            <div class="w-24">
                <x-ui.progress :value="$task->progress" size="xs" show-label
                               :label="__('Progress of :key', ['key' => $task->key])" />
            </div>
        </td>
    @endif

    @if ($this->showsColumn('updated_at'))
        <td class="whitespace-nowrap px-2 py-1.5 align-middle text-xs text-[var(--text-muted)]">
            <span x-data="relativeTime('{{ $task->updated_at?->toIso8601String() }}')" x-text="label">
                {{ $task->updated_at?->diffForHumans() }}
            </span>
        </td>
    @endif

    <td class="py-1.5 pe-4 align-middle sm:pe-5">
        <a href="{{ route('app.tasks.show', [$workspace, $task]) }}"
           x-on:click.prevent="$dispatch('open-task', { taskId: {{ $task->getKey() }} })"
           class="grid size-6 place-items-center rounded text-[var(--text-subtle)] opacity-0 transition-colors
                  hover:bg-[var(--surface-active)] hover:text-[var(--text-DEFAULT)] focus-visible:opacity-100
                  group-hover:opacity-100"
           aria-label="{{ __('Open :key', ['key' => $task->key]) }}">
            <x-icon.chevron-right class="size-4 flip-rtl" />
        </a>
    </td>
</tr>

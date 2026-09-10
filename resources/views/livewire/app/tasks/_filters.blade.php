{{--
    The task filter bar.

    Shared verbatim by the list and the board — both host components use the FiltersTasks
    trait, so every control here is bound to the same property on either surface and a
    filtered URL means the same thing on both.
--}}
@php
    $dueRanges = [
        'overdue' => __('Overdue'),
        'today' => __('Due today'),
        'week' => __('Due in 7 days'),
        'month' => __('Due in 30 days'),
        'none' => __('No due date'),
    ];
@endphp

<div x-data="{ open: false }" class="flex flex-wrap items-center gap-1.5">

    {{-- Search ------------------------------------------------------------ --}}
    <div class="relative w-full sm:w-56">
        <label for="task-search-{{ $this->getId() }}" class="sr-only">{{ __('Search tasks') }}</label>
        <x-ui.input id="task-search-{{ $this->getId() }}"
                    wire:model.live.debounce.300ms="search" busy-target="search"
                    :value="$search"
                    type="search"
                    size="md"
                    icon="icon.search"
                    autocomplete="off"
                    :placeholder="__('Search title or key…')" />

        <span wire:loading.delay wire:target="search"
              class="absolute end-2 top-1/2 -translate-y-1/2 text-[var(--text-subtle)]">
            <x-ui.spinner class="size-3.5" />
        </span>
    </div>

    {{--
        On a phone the controls fold behind one button. Nine stacked dropdowns above the
        first task is a filter bar that has eaten the list it filters.

        `sm:!flex` beats the inline `display:none` x-show writes, so from the small
        breakpoint up the row is simply always open.
    --}}
    <x-ui.button size="md" variant="{{ $this->hasFilters() ? 'soft' : 'secondary' }}" class="sm:hidden"
                 x-on:click="open = ! open" x-bind:aria-expanded="open.toString()">
        {{ __('Filters') }}@if ($this->hasFilters())<span class="tabular-nums">· {{ $this->activeFilterCount() }}</span>@endif
    </x-ui.button>

    <div x-show="open" class="flex w-full flex-wrap items-center gap-1.5 sm:!flex sm:w-auto">

    {{-- Status ------------------------------------------------------------ --}}
    <x-ui.dropdown width="w-56">
        <x-slot:trigger>
            <x-ui.button size="md" variant="{{ $statusIds ? 'soft' : 'secondary' }}" trailing-icon="icon.chevron-down">
                {{ __('Status') }}@if ($statusIds)<span class="tabular-nums">· {{ count($statusIds) }}</span>@endif
            </x-ui.button>
        </x-slot:trigger>

        <div class="max-h-72 overflow-y-auto scrollbar-thin">
            @forelse ($this->statusOptions as $status)
                @php $on = in_array($status->getKey(), $statusIds); @endphp
                <x-ui.dropdown-item wire:click="toggleFilter('statusIds', {{ $status->getKey() }})" :active="$on">
                    <span class="flex items-center gap-2 overflow-hidden">
                        <x-ui.tick :on="$on" />
                        <x-ui.status-dot :color="$status->color ?? 'gray'" />
                        <span dir="auto" class="truncate">{{ $status->name }}</span>
                    </span>
                </x-ui.dropdown-item>
            @empty
                <p class="px-2 py-1.5 text-xs text-[var(--text-subtle)]">{{ __('This project has no columns yet.') }}</p>
            @endforelse
        </div>
    </x-ui.dropdown>

    {{-- Assignee ---------------------------------------------------------- --}}
    <x-ui.dropdown width="w-60">
        <x-slot:trigger>
            <x-ui.button size="md" variant="{{ $assigneeIds || $unassignedOnly ? 'soft' : 'secondary' }}"
                         trailing-icon="icon.chevron-down">
                {{ __('Assignee') }}@if ($assigneeIds)<span class="tabular-nums">· {{ count($assigneeIds) }}</span>@endif
            </x-ui.button>
        </x-slot:trigger>

        <x-ui.dropdown-item wire:click="$toggle('unassignedOnly')" :active="$unassignedOnly">
            <span class="flex items-center gap-2 overflow-hidden">
                <x-ui.tick :on="$unassignedOnly" />
                <span class="truncate">{{ __('Unassigned only') }}</span>
            </span>
        </x-ui.dropdown-item>

        <x-ui.dropdown-separator />

        <div class="max-h-72 overflow-y-auto scrollbar-thin">
            @foreach ($this->memberOptions as $member)
                @php $on = in_array($member->getKey(), $assigneeIds); @endphp
                <x-ui.dropdown-item wire:click="toggleFilter('assigneeIds', {{ $member->getKey() }})" :active="$on">
                    <span class="flex items-center gap-2 overflow-hidden">
                        <x-ui.tick :on="$on" />
                        <x-ui.avatar :user="$member" size="xs" />
                        <span dir="auto" class="truncate">{{ $member->name }}</span>
                    </span>
                </x-ui.dropdown-item>
            @endforeach
        </div>
    </x-ui.dropdown>

    {{-- Priority ---------------------------------------------------------- --}}
    <x-ui.dropdown width="w-48">
        <x-slot:trigger>
            <x-ui.button size="md" variant="{{ $priorities ? 'soft' : 'secondary' }}" trailing-icon="icon.chevron-down">
                {{ __('Priority') }}@if ($priorities)<span class="tabular-nums">· {{ count($priorities) }}</span>@endif
            </x-ui.button>
        </x-slot:trigger>

        @foreach ($this->priorityOptions() as $priority)
            @php $on = in_array($priority->value, $priorities); @endphp
            <x-ui.dropdown-item wire:click="toggleFilter('priorities', '{{ $priority->value }}')" :active="$on">
                <span class="flex items-center gap-2 overflow-hidden">
                    <x-ui.tick :on="$on" />
                    <x-ui.status-dot :color="$priority->color()" />
                    <span class="truncate">{{ $priority->label() }}</span>
                </span>
            </x-ui.dropdown-item>
        @endforeach
    </x-ui.dropdown>

    {{-- Due --------------------------------------------------------------- --}}
    <x-ui.dropdown width="w-48">
        <x-slot:trigger>
            <x-ui.button size="md" variant="{{ $dueRange ? 'soft' : 'secondary' }}" trailing-icon="icon.chevron-down">
                {{ $dueRange ? $dueRanges[$dueRange] : __('Due') }}
            </x-ui.button>
        </x-slot:trigger>

        <x-ui.dropdown-item wire:click="setDueRange('')" :active="$dueRange === ''">
            <span class="flex items-center gap-2 overflow-hidden">
                <x-ui.tick :on="$dueRange === ''" />
                <span class="truncate">{{ __('Any time') }}</span>
            </span>
        </x-ui.dropdown-item>

        <x-ui.dropdown-separator />

        @foreach ($dueRanges as $value => $label)
            <x-ui.dropdown-item wire:click="setDueRange('{{ $value }}')" :active="$dueRange === $value">
                <span class="flex items-center gap-2 overflow-hidden">
                    <x-ui.tick :on="$dueRange === $value" />
                    <span class="truncate">{{ $label }}</span>
                </span>
            </x-ui.dropdown-item>
        @endforeach
    </x-ui.dropdown>

    {{-- Tags and milestones are only offered where the workspace has some. --}}
    @if ($this->tagOptions->isNotEmpty())
        <x-ui.dropdown width="w-56">
            <x-slot:trigger>
                <x-ui.button size="md" variant="{{ $tagIds ? 'soft' : 'secondary' }}" icon="icon.tag"
                             trailing-icon="icon.chevron-down">
                    {{ __('Tags') }}@if ($tagIds)<span class="tabular-nums">· {{ count($tagIds) }}</span>@endif
                </x-ui.button>
            </x-slot:trigger>

            <div class="max-h-72 overflow-y-auto scrollbar-thin">
                @foreach ($this->tagOptions as $tag)
                    @php $on = in_array($tag->getKey(), $tagIds); @endphp
                    <x-ui.dropdown-item wire:click="toggleFilter('tagIds', {{ $tag->getKey() }})" :active="$on">
                        <span class="flex items-center gap-2 overflow-hidden">
                            <x-ui.tick :on="$on" />
                            <x-ui.status-dot :color="$tag->color ?? 'gray'" />
                            <span dir="auto" class="truncate">{{ $tag->name }}</span>
                        </span>
                    </x-ui.dropdown-item>
                @endforeach
            </div>
        </x-ui.dropdown>
    @endif

    @if ($this->milestoneOptions->isNotEmpty())
        <x-ui.dropdown width="w-64">
            <x-slot:trigger>
                <x-ui.button size="md" variant="{{ $milestoneIds ? 'soft' : 'secondary' }}" icon="icon.flag"
                             trailing-icon="icon.chevron-down">
                    {{ __('Milestone') }}@if ($milestoneIds)<span class="tabular-nums">· {{ count($milestoneIds) }}</span>@endif
                </x-ui.button>
            </x-slot:trigger>

            <div class="max-h-72 overflow-y-auto scrollbar-thin">
                @foreach ($this->milestoneOptions as $milestone)
                    @php $on = in_array($milestone->getKey(), $milestoneIds); @endphp
                    <x-ui.dropdown-item wire:click="toggleFilter('milestoneIds', {{ $milestone->getKey() }})" :active="$on">
                        <span class="flex items-center gap-2 overflow-hidden">
                            <x-ui.tick :on="$on" />
                            <span dir="auto" class="truncate">{{ $milestone->name }}</span>
                        </span>
                    </x-ui.dropdown-item>
                @endforeach
            </div>
        </x-ui.dropdown>
    @endif

    {{-- Quick toggles ----------------------------------------------------- --}}
    <x-ui.button size="md" variant="{{ $overdueOnly ? 'soft' : 'ghost' }}" wire:click="$toggle('overdueOnly')"
                 aria-pressed="{{ $overdueOnly ? 'true' : 'false' }}">
        {{ __('Overdue') }}
    </x-ui.button>

    <x-ui.button size="md" variant="{{ $includeCompleted ? 'soft' : 'ghost' }}" wire:click="$toggle('includeCompleted')"
                 aria-pressed="{{ $includeCompleted ? 'true' : 'false' }}">
        {{ __('Show done') }}
    </x-ui.button>

    @if ($this->hasFilters())
        <x-ui.button size="md" variant="ghost" wire:click="clearFilters">
            {{ __('Clear') }}
            <span class="ms-0.5 rounded bg-[var(--surface-active)] px-1 text-2xs tabular-nums text-[var(--text-muted)]">
                {{ $this->activeFilterCount() }}
            </span>
        </x-ui.button>
    @endif
    </div>
</div>

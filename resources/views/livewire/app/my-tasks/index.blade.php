@php
    /**
     * Full class names, selected by key. Tailwind scans source text and never evaluates it,
     * so a class built from a variable compiles to nothing at all.
     */
    $dueText = [
        'late' => 'text-critical-600 dark:text-critical-100',
        'today' => 'text-caution-700 dark:text-caution-100',
        'soon' => 'text-[var(--text-DEFAULT)]',
        'later' => 'text-[var(--text-muted)]',
        'none' => 'text-[var(--text-subtle)]',
    ];
    $statusDot = [
        'gray' => 'bg-ink-400',
        'brand' => 'bg-brand-500',
        'blue' => 'bg-blue-500',
        'green' => 'bg-positive-500',
        'amber' => 'bg-caution-500',
        'orange' => 'bg-orange-500',
        'red' => 'bg-critical-500',
        'purple' => 'bg-accent-500',
        'teal' => 'bg-teal-500',
        'pink' => 'bg-pink-500',
    ];

    $tabs = $this->tabs();
    $groups = $this->groups;
    $rows = $this->tasks;
    $empty = $this->emptyCopy();
    $priorities = \App\Enums\Priority::cases();
@endphp

<div class="page py-5 lg:py-6"
     x-data="{ composing: false }"
     x-on:open-quick-create.window="composing = true; $nextTick(() => $refs.newTitle?.focus())">

    {{-- ---------------------------------------------------------------- --}}
    {{-- Header                                                           --}}
    {{-- ---------------------------------------------------------------- --}}
    <header class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div class="min-w-0">
            <h2 class="truncate text-base font-semibold tracking-tight text-[var(--text-strong)]">
                {{ __('My Tasks') }}
            </h2>
            <p class="mt-0.5 flex items-center gap-2 text-xs text-[var(--text-muted)]">
                <span>
                    {{ trans_choice(
                        '{0}Nothing assigned to you across your projects|{1}:count task assigned to you across your projects|[2,*]:count tasks assigned to you across your projects',
                        $this->counts['all'],
                        ['count' => $this->counts['all']],
                    ) }}
                </span>

                {{-- An inline edit is a write; it says so rather than appearing to be free. --}}
                <span wire:loading.delay wire:target="setStatus,setPriority,quickAdd"
                      class="inline-flex items-center gap-1.5 text-[var(--text-subtle)]" role="status">
                    <x-ui.spinner class="size-3" />
                    {{ __('Saving…') }}
                </span>
            </p>
        </div>

        <div class="flex w-full shrink-0 items-center gap-2 sm:w-auto">
            <div class="relative min-w-0 flex-1 sm:w-56 sm:flex-none">
                <x-ui.input type="search"
                            icon="icon.search"
                            wire:model.live.debounce.300ms="search" busy-target="search"
                            :placeholder="__('Search my tasks')"
                            :aria-label="__('Search my tasks')" />
                <span wire:loading.delay wire:target="search"
                      class="pointer-events-none absolute end-2 top-1/2 -translate-y-1/2">
                    <x-ui.spinner class="size-3.5 text-[var(--text-subtle)]" />
                </span>
            </div>

            @if ($this->canQuickAdd())
                <x-ui.button variant="primary" size="md" icon="icon.plus"
                             x-on:click="composing = true; $nextTick(() => $refs.newTitle?.focus())">
                    {{ __('Add task') }}
                </x-ui.button>
            @endif
        </div>
    </header>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Tabs                                                             --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.tabs class="mt-4">
        @foreach ($tabs as $tabDefinition)
            <x-ui.tab :active="$tab === $tabDefinition['key']"
                      :icon="$tabDefinition['icon']"
                      :count="$tabDefinition['count'] > 0 ? $tabDefinition['count'] : null"
                      wire:key="tab-{{ $tabDefinition['key'] }}"
                      wire:click="selectTab('{{ $tabDefinition['key'] }}')">
                {{ $tabDefinition['label'] }}
            </x-ui.tab>
        @endforeach
    </x-ui.tabs>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Quick add                                                        --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($this->canQuickAdd())
        <form wire:submit="quickAdd"
              x-on:keydown.escape="composing = false"
              class="mt-4 rounded-lg border border-[var(--line-subtle)] bg-[var(--surface-panel)] p-2 shadow-panel">

            <div class="flex items-center gap-2">
                <span class="grid size-8 shrink-0 place-items-center rounded-md bg-[var(--surface-sunken)]
                             text-[var(--text-subtle)]" aria-hidden="true">
                    <x-icon.plus class="size-4" />
                </span>

                <label for="quick-add-title" class="sr-only">{{ __('Task title') }}</label>
                <input id="quick-add-title"
                       type="text"
                       x-ref="newTitle"
                       x-on:focus="composing = true"
                       wire:model="newTitle"
                       autocomplete="off"
                       placeholder="{{ __('Add a task and press Enter') }}"
                       class="h-8 min-w-0 flex-1 rounded-md border-0 bg-transparent px-1 text-sm
                              text-[var(--text-strong)] placeholder:text-[var(--text-subtle)]
                              focus:outline-none focus:ring-0">

                <x-ui.button type="submit" variant="primary" size="sm" wire:target="quickAdd"
                             x-show="composing" x-cloak>
                    {{ __('Add') }}
                </x-ui.button>
            </div>

            {{-- The rest of the composer only appears once there is something to compose. --}}
            <div x-show="composing" x-cloak x-collapse
                 class="mt-2 flex flex-wrap items-center gap-2 border-t border-[var(--line-subtle)] pt-2">

                <div class="w-44">
                    <label for="quick-add-project" class="sr-only">{{ __('Project') }}</label>
                    <x-ui.select id="quick-add-project" size="sm" wire:model="newProjectId"
                                 :invalid="$errors->has('newProjectId')">
                        @foreach ($this->assignableProjects as $option)
                            <option value="{{ $option->getKey() }}">{{ $option->name }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div class="w-40">
                    <label for="quick-add-due" class="sr-only">{{ __('Due date') }}</label>
                    <x-ui.input id="quick-add-due" type="date" size="sm" wire:model="newDueDate"
                                :invalid="$errors->has('newDueDate')" />
                </div>

                <div class="w-32">
                    <label for="quick-add-priority" class="sr-only">{{ __('Priority') }}</label>
                    <x-ui.select id="quick-add-priority" size="sm" wire:model="newPriority"
                                 :options="$this->priorityOptions()" />
                </div>

                <button type="button"
                        x-on:click="composing = false"
                        class="ms-auto text-xs text-[var(--text-muted)] transition-colors hover:text-[var(--text-DEFAULT)]">
                    {{ __('Close') }}
                </button>
            </div>

            @if ($errors->any())
                <p class="mt-2 px-1 text-xs text-critical-600 dark:text-critical-100">
                    {{ $errors->first() }}
                </p>
            @endif
        </form>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- The list                                                         --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="mt-4 rounded-lg border border-[var(--line-subtle)] bg-[var(--surface-panel)] shadow-panel">
        <div wire:loading.delay.class="opacity-40"
             wire:target="selectTab,search,gotoPage,nextPage,previousPage"
             class="transition-opacity duration-150">

            @if ($groups === [])
                <x-ui.empty-state icon="icon.check-circle" :title="$empty['title']" :description="$empty['description']">
                    <x-slot:actions>
                        @if ($search !== '')
                            <x-ui.button size="md" wire:click="$set('search', '')">
                                {{ __('Clear search') }}
                            </x-ui.button>
                        @elseif ($tab !== 'all' && $this->counts['all'] > 0)
                            <x-ui.button size="md" icon="icon.list" wire:click="selectTab('all')">
                                {{ __('Show everything assigned to me') }}
                            </x-ui.button>
                        @elseif ($this->canQuickAdd())
                            <x-ui.button variant="primary" size="md" icon="icon.plus"
                                         x-on:click="composing = true; $nextTick(() => $refs.newTitle?.focus())">
                                {{ __('Add your first task') }}
                            </x-ui.button>
                        @else
                            <x-ui.button size="md" icon="icon.folder" :href="route('app.projects.index', $workspace)">
                                {{ __('Browse projects') }}
                            </x-ui.button>
                        @endif
                    </x-slot:actions>
                </x-ui.empty-state>
            @else
                @foreach ($groups as $group)
                    @php
                        $project = $group['project'];
                        $groupKey = $project?->getKey() ?? 0;
                    @endphp

                    <section wire:key="group-{{ $groupKey }}"
                             x-data="{ open: $persist(true).as('planvio.my-tasks.group.{{ $groupKey }}') }"
                             class="border-b border-[var(--line-subtle)] last:border-b-0">

                        <div class="flex items-center gap-2 bg-[var(--surface-sunken)] px-3 py-1.5">
                            <button type="button"
                                    x-on:click="open = ! open"
                                    :aria-expanded="open ? 'true' : 'false'"
                                    aria-controls="group-body-{{ $groupKey }}"
                                    class="flex min-w-0 flex-1 items-center gap-2 text-start">
                                <span class="flip-rtl inline-flex shrink-0">
                                    <x-icon.chevron-right class="size-3.5 text-[var(--text-subtle)] transition-transform"
                                                          x-bind:class="open && 'rotate-90'" />
                                </span>
                                @if ($project)
                                    <span class="size-2 shrink-0 rounded-[3px]"
                                          style="background-color: {{ $project->color }}" aria-hidden="true"></span>
                                @endif
                                <span class="truncate text-xs font-semibold text-[var(--text-DEFAULT)]">
                                    {{ $project?->name ?? __('No project') }}
                                </span>
                                <span class="shrink-0 rounded bg-[var(--surface-active)] px-1.5 text-[10px]
                                             font-medium tabular-nums text-[var(--text-muted)]">
                                    {{ count($group['tasks']) }}
                                </span>
                            </button>

                            @if ($project)
                                <a href="{{ route('app.projects.tasks', [$workspace, $project]) }}"
                                   class="shrink-0 text-2xs text-[var(--text-subtle)] transition-colors hover:text-[var(--accent)]">
                                    {{ __('Open project') }}
                                </a>
                            @endif
                        </div>

                        {{--
                            Plain x-show rather than x-collapse: collapse animates by clipping
                            the element, and a clipped group clips the inline status menu with
                            it. A group that opens instantly is a fair trade for a menu that
                            is never cut in half.
                        --}}
                        <ul id="group-body-{{ $groupKey }}" x-show="open"
                            class="divide-y divide-[var(--line-subtle)]">
                            @foreach ($group['tasks'] as $task)
                                @php
                                    $done = $task->completed_at !== null;
                                    $tone = $done ? 'none' : $this->dueTone($task->due_date);
                                    $category = $task->status?->category;
                                    $statusColour = $category?->color() ?? 'gray';
                                    $statuses = $project?->taskStatuses ?? collect();
                                @endphp

                                <li wire:key="task-{{ $task->getKey() }}"
                                    wire:loading.delay.class="opacity-50"
                                    wire:target="toggleComplete({{ $task->getKey() }})"
                                    class="flex flex-wrap items-center gap-x-2.5 gap-y-1.5 px-3 py-2
                                           transition-colors hover:bg-[var(--surface-hover)]">

                                    {{-- Completion is one click and it is reversible, so it asks nothing. --}}
                                    <button type="button"
                                            role="checkbox"
                                            aria-checked="{{ $done ? 'true' : 'false' }}"
                                            wire:click="toggleComplete({{ $task->getKey() }})"
                                            wire:target="toggleComplete({{ $task->getKey() }})"
                                            wire:loading.attr="disabled"
                                            class="grid size-4 shrink-0 place-items-center rounded border transition-colors
                                                   {{ $done
                                                       ? 'border-positive-600 bg-positive-600 text-white'
                                                       : 'border-[var(--line-strong)] hover:border-[var(--accent)]' }}"
                                            aria-label="{{ $done
                                                ? __('Reopen :title', ['title' => $task->title])
                                                : __('Complete :title', ['title' => $task->title]) }}">
                                        @if ($done)
                                            <svg class="size-3" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                                 stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="m4 10.5 4 4 8-9"/>
                                            </svg>
                                        @endif
                                    </button>

                                    <a href="{{ route('app.tasks.show', [$workspace, $task]) }}"
                                       class="min-w-0 basis-[calc(100%-2rem)] truncate text-sm
                                              hover:text-[var(--accent)] sm:flex-1 sm:basis-0
                                              {{ $done
                                                  ? 'text-[var(--text-muted)] line-through decoration-[var(--line-strong)]'
                                                  : 'text-[var(--text-strong)]' }}">
                                        {{ $task->title }}
                                    </a>

                                    {{--
                                        On a narrow screen the controls fall onto a second line
                                        and line up under the title; from `sm` up they sit on the
                                        right of the row where a dense list wants them.
                                    --}}
                                    <div class="ms-6.5 flex flex-1 items-center justify-start gap-1.5
                                                sm:ms-0 sm:flex-none sm:justify-end">
                                        <span class="hidden font-mono text-2xs text-[var(--text-subtle)] sm:inline">
                                            <x-ui.bidi>{{ $task->key }}</x-ui.bidi>
                                        </span>

                                        {{-- Inline status: the project's own columns, never a generic list. --}}
                                        <x-ui.dropdown align="end" width="w-52">
                                            <x-slot:trigger>
                                                <button type="button"
                                                        class="flex h-6 max-w-[8.5rem] items-center gap-1.5 rounded border
                                                               border-[var(--line-subtle)] bg-[var(--surface-panel)] px-1.5
                                                               text-2xs text-[var(--text-DEFAULT)] transition-colors
                                                               hover:border-[var(--line-strong)]"
                                                        aria-label="{{ __('Change status of :title', ['title' => $task->title]) }}">
                                                    <span class="size-1.5 shrink-0 rounded-full {{ $statusDot[$statusColour] ?? $statusDot['gray'] }}"
                                                          aria-hidden="true"></span>
                                                    <span dir="auto" class="truncate">{{ $task->status?->name ?? __('No status') }}</span>
                                                </button>
                                            </x-slot:trigger>

                                            @forelse ($statuses as $option)
                                                @php $optionColour = $option->category?->color() ?? 'gray'; @endphp
                                                <x-ui.dropdown-item wire:key="st-{{ $task->getKey() }}-{{ $option->getKey() }}"
                                                                    :active="(int) $task->status_id === (int) $option->getKey()"
                                                                    wire:click="setStatus({{ $task->getKey() }}, {{ $option->getKey() }})">
                                                    <span class="flex items-center gap-2">
                                                        <span class="size-1.5 rounded-full {{ $statusDot[$optionColour] ?? $statusDot['gray'] }}"
                                                              aria-hidden="true"></span>
                                                        {{ $option->name }}
                                                    </span>
                                                </x-ui.dropdown-item>
                                            @empty
                                                <p class="px-2 py-1.5 text-xs text-[var(--text-muted)]">
                                                    {{ __('This project has no columns.') }}
                                                </p>
                                            @endforelse
                                        </x-ui.dropdown>

                                        {{-- Inline priority: colour comes from the enum, never from a hex. --}}
                                        <x-ui.dropdown align="end" width="w-40">
                                            <x-slot:trigger>
                                                <button type="button"
                                                        aria-label="{{ __('Change priority of :title', ['title' => $task->title]) }}">
                                                    <x-ui.badge :color="$task->priority->color()" size="sm" dot
                                                                class="cursor-pointer transition-opacity hover:opacity-80">
                                                        {{ $task->priority->label() }}
                                                    </x-ui.badge>
                                                </button>
                                            </x-slot:trigger>

                                            @foreach ($priorities as $case)
                                                <x-ui.dropdown-item wire:key="pr-{{ $task->getKey() }}-{{ $case->value }}"
                                                                    :active="$task->priority === $case"
                                                                    wire:click="setPriority({{ $task->getKey() }}, '{{ $case->value }}')">
                                                    <span class="flex items-center gap-2">
                                                        <x-ui.badge :color="$case->color()" size="sm" dot>{{ $case->label() }}</x-ui.badge>
                                                    </span>
                                                </x-ui.dropdown-item>
                                            @endforeach
                                        </x-ui.dropdown>

                                        <span class="w-16 shrink-0 text-end text-2xs font-medium
                                                     {{ $dueText[$tone] ?? $dueText['none'] }}">
                                            {{ $done
                                                ? $task->completed_at?->translatedFormat('j M')
                                                : $this->dueLabel($task->due_date) }}
                                        </span>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            @endif
        </div>
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Pager                                                            --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($rows->hasPages())
        <div class="mt-3 flex items-center justify-between gap-3">
            <p class="text-xs tabular-nums text-[var(--text-muted)]">
                {{-- Formats::range isolates the span: an en dash between two figures is a
                     neutral, and in an Arabic sentence it prints "10–1". --}}
                {{ __('Showing :from–:to of :total', \App\Support\Formats::range(
                    $rows->firstItem(),
                    $rows->lastItem(),
                ) + ['total' => $rows->total()]) }}
            </p>
            <div class="flex items-center gap-2">
                <x-ui.button size="sm" wire:click="previousPage" :disabled="$rows->onFirstPage()">
                    {{ __('Previous') }}
                </x-ui.button>
                <x-ui.button size="sm" wire:click="nextPage" :disabled="! $rows->hasMorePages()">
                    {{ __('Next') }}
                </x-ui.button>
            </div>
        </div>
    @endif
</div>

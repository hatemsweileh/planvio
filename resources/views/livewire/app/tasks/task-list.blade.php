@php
    $groups = $this->groups;
    $paginator = $this->tasks;
    $pageIds = collect($paginator->items())->map(fn ($task) => (int) $task->getKey())->all();
    $allSelected = $pageIds !== [] && array_diff($pageIds, $selected) === [];
    $groupLabels = [
        'none' => __('No grouping'),
        'status' => __('Status'),
        'assignee' => __('Assignee'),
        'priority' => __('Priority'),
        'milestone' => __('Milestone'),
    ];
    $groupLabel = $groupLabels[$group] ?? $groupLabels['none'];
@endphp

<div class="flex h-full min-h-0 flex-col">

    @include('livewire.app.tasks._project-header')

    {{-- Controls ---------------------------------------------------------- --}}
    <div class="shrink-0 border-b border-[var(--line-subtle)] bg-[var(--surface-panel)] px-4 py-2 sm:px-5">
        <div class="flex flex-wrap items-center gap-2">
            {{-- Full width on a phone so the search box is a search box and not a stub. --}}
            <div class="w-full sm:min-w-0 sm:flex-1">
                @include('livewire.app.tasks._filters')
            </div>

            <div class="flex shrink-0 items-center gap-1.5">
                {{-- Grouping --}}
                <x-ui.dropdown align="end" width="w-48">
                    <x-slot:trigger>
                        <x-ui.button size="md" variant="{{ $group !== 'none' ? 'soft' : 'secondary' }}"
                                     trailing-icon="icon.chevron-down">
                            {{ __('Group') }}{{ $group !== 'none' ? ': '.$groupLabel : '' }}
                        </x-ui.button>
                    </x-slot:trigger>

                    @foreach ($groupLabels as $value => $label)
                        <x-ui.dropdown-item wire:click="groupBy('{{ $value }}')" :active="$group === $value">
                            <span class="flex items-center gap-2 overflow-hidden">
                                <x-ui.tick :on="$group === $value" />
                                <span class="truncate">{{ $label }}</span>
                            </span>
                        </x-ui.dropdown-item>
                    @endforeach
                </x-ui.dropdown>

                {{-- Column visibility --}}
                <x-ui.dropdown align="end" width="w-52">
                    <x-slot:trigger>
                        <x-ui.tooltip :label="__('Columns')">
                            <x-ui.button size="md" variant="secondary" icon-only :aria-label="__('Columns')">
                                <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6">
                                    <rect x="2.75" y="3.75" width="14.5" height="12.5" rx="1.75"/>
                                    <path d="M8 3.75v12.5M13 3.75v12.5"/>
                                </svg>
                            </x-ui.button>
                        </x-ui.tooltip>
                    </x-slot:trigger>

                    <p class="px-2 pb-1 pt-1 text-2xs font-semibold uppercase tracking-wider text-[var(--text-subtle)]">
                        {{ __('Columns') }}
                    </p>

                    @foreach ($this->columnOptions() as $column)
                        @if ($column['fixed'])
                            <div class="flex items-center gap-2 rounded px-2 py-1.5 text-sm text-[var(--text-subtle)]">
                                <x-ui.tick :on="true" />
                                <span class="truncate">{{ $column['label'] }}</span>
                            </div>
                        @else
                            <x-ui.dropdown-item wire:click="toggleColumn('{{ $column['key'] }}')"
                                                :active="$this->showsColumn($column['key'])">
                                <span class="flex items-center gap-2 overflow-hidden">
                                    <x-ui.tick :on="$this->showsColumn($column['key'])" />
                                    <span class="truncate">{{ $column['label'] }}</span>
                                </span>
                            </x-ui.dropdown-item>
                        @endif
                    @endforeach
                </x-ui.dropdown>

                @include('livewire.app.tasks._saved-views')
            </div>
        </div>
    </div>

    {{-- Bulk action bar ---------------------------------------------------- --}}
    @if ($selected)
        <div class="flex shrink-0 flex-wrap items-center gap-1.5 border-b border-[var(--line-subtle)]
                    bg-[var(--accent-soft)] px-4 py-2 sm:px-5">
            <span class="text-xs font-semibold text-[var(--accent-soft-text)]">
                {{ trans_choice('{1}:count task selected|[2,*]:count tasks selected', count($selected), ['count' => count($selected)]) }}
            </span>

            <div class="mx-1 h-4 w-px bg-[var(--line-DEFAULT)]" aria-hidden="true"></div>

            @can('task.assign', $project)
                <x-ui.dropdown width="w-56">
                    <x-slot:trigger>
                        <x-ui.button size="sm" variant="secondary" trailing-icon="icon.chevron-down">{{ __('Assign') }}</x-ui.button>
                    </x-slot:trigger>
                    <x-ui.dropdown-item wire:click="bulkAssign(null)">{{ __('Unassigned') }}</x-ui.dropdown-item>
                    <x-ui.dropdown-separator />
                    <div class="max-h-64 overflow-y-auto scrollbar-thin">
                        @foreach ($this->memberOptions as $member)
                            <x-ui.dropdown-item wire:click="bulkAssign({{ $member->getKey() }})">
                                <span class="flex items-center gap-2 overflow-hidden">
                                    <x-ui.avatar :user="$member" size="xs" />
                                    <span dir="auto" class="truncate">{{ $member->name }}</span>
                                </span>
                            </x-ui.dropdown-item>
                        @endforeach
                    </div>
                </x-ui.dropdown>
            @endcan

            <x-ui.dropdown width="w-56">
                <x-slot:trigger>
                    <x-ui.button size="sm" variant="secondary" trailing-icon="icon.chevron-down">{{ __('Status') }}</x-ui.button>
                </x-slot:trigger>
                @foreach ($this->statusOptions as $status)
                    <x-ui.dropdown-item wire:click="bulkStatus({{ $status->getKey() }})">
                        <span class="flex items-center gap-2 overflow-hidden">
                            <x-ui.status-dot :color="$status->color ?? 'gray'" />
                            <span dir="auto" class="truncate">{{ $status->name }}</span>
                        </span>
                    </x-ui.dropdown-item>
                @endforeach
            </x-ui.dropdown>

            <x-ui.dropdown width="w-48">
                <x-slot:trigger>
                    <x-ui.button size="sm" variant="secondary" trailing-icon="icon.chevron-down">{{ __('Priority') }}</x-ui.button>
                </x-slot:trigger>
                @foreach ($this->priorityOptions() as $priority)
                    <x-ui.dropdown-item wire:click="bulkPriority('{{ $priority->value }}')">
                        <span class="flex items-center gap-2 overflow-hidden">
                            <x-ui.status-dot :color="$priority->color()" />
                            <span class="truncate">{{ $priority->label() }}</span>
                        </span>
                    </x-ui.dropdown-item>
                @endforeach
            </x-ui.dropdown>

            @if ($this->milestoneOptions->isNotEmpty())
                <x-ui.dropdown width="w-64">
                    <x-slot:trigger>
                        <x-ui.button size="sm" variant="secondary" trailing-icon="icon.chevron-down">{{ __('Milestone') }}</x-ui.button>
                    </x-slot:trigger>
                    <x-ui.dropdown-item wire:click="bulkMilestone(null)">{{ __('No milestone') }}</x-ui.dropdown-item>
                    <x-ui.dropdown-separator />
                    @foreach ($this->milestoneOptions as $milestone)
                        <x-ui.dropdown-item wire:click="bulkMilestone({{ $milestone->getKey() }})">
                            {{ $milestone->name }}
                        </x-ui.dropdown-item>
                    @endforeach
                </x-ui.dropdown>
            @endif

            @if ($this->tagOptions->isNotEmpty())
                <x-ui.dropdown width="w-56">
                    <x-slot:trigger>
                        <x-ui.button size="sm" variant="secondary" icon="icon.tag" trailing-icon="icon.chevron-down">{{ __('Tag') }}</x-ui.button>
                    </x-slot:trigger>
                    <div class="max-h-64 overflow-y-auto scrollbar-thin">
                        @foreach ($this->tagOptions as $tag)
                            <x-ui.dropdown-item wire:click="bulkTag({{ $tag->getKey() }})">
                                <span class="flex items-center gap-2 overflow-hidden">
                                    <x-ui.status-dot :color="$tag->color ?? 'gray'" />
                                    <span dir="auto" class="truncate">{{ $tag->name }}</span>
                                </span>
                            </x-ui.dropdown-item>
                        @endforeach
                    </div>
                </x-ui.dropdown>
            @endif

            @can('task.delete', $project)
                <x-ui.button size="sm" variant="danger-ghost" icon="icon.trash"
                             wire:click="$set('confirmingBulkDelete', true)">{{ __('Delete') }}</x-ui.button>
            @endcan

            <x-ui.button size="sm" variant="ghost" wire:click="clearSelection" class="ms-auto">
                {{ __('Clear selection') }}
            </x-ui.button>
        </div>
    @endif

    {{-- Rows --------------------------------------------------------------- --}}
    <div class="scrollbar-thin min-h-0 flex-1 overflow-auto">
        @if ($paginator->total() === 0)
            <div class="mx-auto max-w-2xl px-4 py-6">
                @if ($this->hasFilters())
                    <x-ui.empty-state icon="icon.search"
                                      :title="__('Nothing matches these filters')"
                                      :description="__('Loosen a filter, or clear them all to see everything in this project.')">
                        <x-slot:actions>
                            <x-ui.button variant="secondary" size="md" wire:click="clearFilters">
                                {{ __('Clear filters') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state icon="icon.list"
                                      :title="__('No tasks yet')"
                                      :description="__('Break the work into tasks. Everything on the board, the timeline and the calendar starts here.')">
                        <x-slot:actions>
                            @can('task.create', $project)
                                <x-ui.button variant="primary" size="md" icon="icon.plus"
                                             x-on:click="$dispatch('open-quick-create', { type: 'task', project: {{ $project->getKey() }} })">
                                    {{ __('New task') }}
                                </x-ui.button>
                            @endcan
                            @can('ai.use', $workspace)
                                {{--
                                    `Js::from` written out rather than the `@js` directive,
                                    for the reason spelled out in tasks/_ai-actions.blade.php:
                                    a Blade directive inside an `<x-…>` tag attribute is not
                                    compiled, so `@js(…)` reached the browser verbatim and
                                    this button threw a syntax error instead of opening the
                                    panel. `{{ }}` renders a Htmlable unescaped, so this is
                                    the same JSON the directive would have produced.
                                --}}
                                <x-ui.button variant="secondary" size="md" icon="icon.sparkles"
                                             x-on:click="$dispatch('open-ai-panel', { prompt: {{ \Illuminate\Support\Js::from(__('Draft the first tasks for :project.', ['project' => $project->name])) }} })">
                                    {{ __('Plan it with AI') }}
                                </x-ui.button>
                            @endcan
                        </x-slot:actions>
                    </x-ui.empty-state>
                @endif
            </div>
        @else
            {{-- Desktop table --}}
            <table class="hidden w-full border-collapse text-sm md:table">
                <thead class="sticky top-0 z-10">
                    <tr class="bg-[var(--surface-sunken)] text-start">
                        <th scope="col" class="w-9 border-b border-[var(--line-subtle)] py-2 ps-4 pe-0 sm:ps-5">
                            <input type="checkbox"
                                   wire:click="toggleSelectPage"
                                   @checked($allSelected)
                                   class="size-3.5 rounded border-[var(--line-strong)] bg-[var(--surface-panel)]
                                          text-[var(--accent)] checked:border-[var(--accent)] checked:bg-[var(--accent)]
                                          focus:ring-2 focus:ring-[var(--accent-ring)]"
                                   aria-label="{{ __('Select all on this page') }}">
                        </th>

                        @foreach ($this->columnOptions() as $column)
                            @continue (! $this->showsColumn($column['key']))
                            <th scope="col"
                                class="whitespace-nowrap border-b border-[var(--line-subtle)] px-2 py-2
                                       text-2xs font-semibold uppercase tracking-wider text-[var(--text-muted)]
                                       {{ $column['key'] === 'title' ? 'w-full' : '' }}">
                                <button type="button" wire:click="sortBy('{{ $column['key'] }}')"
                                        class="inline-flex items-center gap-1 rounded transition-colors hover:text-[var(--text-DEFAULT)]"
                                        aria-label="{{ __('Sort by :column', ['column' => $column['label']]) }}">
                                    {{ $column['label'] }}
                                    @if ($sort === $column['key'])
                                        <svg class="size-3 text-[var(--accent)] {{ $direction === 'desc' ? 'rotate-180' : '' }}"
                                             viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.25"
                                             aria-hidden="true">
                                            <path d="m6 12 4-4 4 4" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    @endif
                                </button>
                            </th>
                        @endforeach

                        <th scope="col" class="w-8 border-b border-[var(--line-subtle)] py-2 pe-4 sm:pe-5">
                            <span class="sr-only">{{ __('Actions') }}</span>
                        </th>
                    </tr>
                </thead>

                <tbody wire:loading.class="opacity-60" wire:target="search,clearFilters,toggleFilter,setDueRange,sortBy,groupBy,gotoPage,nextPage,previousPage">
                    @foreach ($groups as $groupRow)
                        @if ($group !== 'none')
                            <tr>
                                <td colspan="99" class="bg-[var(--surface-canvas)] px-4 py-1.5 sm:px-5">
                                    <div class="flex items-center gap-2">
                                        @if ($groupRow['color'])
                                            <x-ui.status-dot :color="$groupRow['color']" />
                                        @endif
                                        <span class="text-xs font-semibold text-[var(--text-strong)]">{{ $groupRow['label'] }}</span>
                                        <span class="text-xs tabular-nums text-[var(--text-subtle)]">{{ $groupRow['rows']->count() }}</span>
                                    </div>
                                </td>
                            </tr>
                        @endif

                        @foreach ($groupRow['rows'] as $task)
                            @include('livewire.app.tasks._row', ['task' => $task])
                        @endforeach
                    @endforeach
                </tbody>
            </table>

            {{-- Mobile cards --}}
            <ul class="divide-y divide-[var(--line-subtle)] md:hidden" role="list">
                @foreach ($groups as $groupRow)
                    @if ($group !== 'none')
                        <li class="flex items-center gap-2 bg-[var(--surface-canvas)] px-4 py-1.5">
                            @if ($groupRow['color'])
                                <x-ui.status-dot :color="$groupRow['color']" />
                            @endif
                            <span class="text-xs font-semibold text-[var(--text-strong)]">{{ $groupRow['label'] }}</span>
                            <span class="text-xs tabular-nums text-[var(--text-subtle)]">{{ $groupRow['rows']->count() }}</span>
                        </li>
                    @endif

                    @foreach ($groupRow['rows'] as $task)
                        <li wire:key="m-{{ $task->getKey() }}" class="bg-[var(--surface-panel)]">
                            <a href="{{ route('app.tasks.show', [$workspace, $task]) }}"
                               x-on:click.prevent="$dispatch('open-task', { taskId: {{ $task->getKey() }} })"
                               class="block px-4 py-3 transition-colors active:bg-[var(--surface-hover)]">
                                <div class="flex items-center gap-2">
                                    <span class="font-mono text-2xs tabular-nums text-[var(--text-subtle)]"><x-ui.bidi>{{ $task->key }}</x-ui.bidi></span>
                                    <x-ui.badge :color="$task->status->color ?? 'gray'" size="sm" dot>{{ $task->status?->name }}</x-ui.badge>
                                    @if ($task->priority !== \App\Enums\Priority::None)
                                        <x-ui.badge :color="$task->priority->color()" size="sm">{{ $task->priority->label() }}</x-ui.badge>
                                    @endif
                                </div>

                                <p class="mt-1 text-sm font-medium leading-snug text-[var(--text-strong)]">{{ $task->title }}</p>

                                <div class="mt-1.5 flex items-center gap-3 text-xs text-[var(--text-muted)]">
                                    @if ($task->assignee)
                                        <span class="flex items-center gap-1.5">
                                            <x-ui.avatar :user="$task->assignee" size="xs" />
                                            <span dir="auto" class="truncate">{{ $task->assignee->name }}</span>
                                        </span>
                                    @else
                                        <span class="text-[var(--text-subtle)]">{{ __('Unassigned') }}</span>
                                    @endif

                                    @if ($task->due_date)
                                        <span class="{{ $task->is_overdue ? 'font-medium text-critical-600' : '' }}">
                                            {{ $task->due_date->isoFormat('D MMM') }}
                                        </span>
                                    @endif

                                    @include('livewire.app.tasks._indicators', ['task' => $task])
                                </div>
                            </a>
                        </li>
                    @endforeach
                @endforeach
            </ul>
        @endif
    </div>

    {{-- Pager -------------------------------------------------------------- --}}
    @if ($paginator->total() > 0)
        <div class="flex shrink-0 items-center justify-between gap-3 border-t border-[var(--line-subtle)]
                    bg-[var(--surface-panel)] px-4 py-2 text-xs text-[var(--text-muted)] sm:px-5">
            <p class="tabular-nums">
                {{-- Formats::range isolates the span: an en dash between two figures is a
                     neutral, and in an Arabic sentence it prints "10–1". --}}
                {{ __(':from–:to of :total', \App\Support\Formats::range(
                    $paginator->firstItem(),
                    $paginator->lastItem(),
                ) + ['total' => $paginator->total()]) }}
            </p>

            @if ($paginator->hasPages())
                <div class="flex items-center gap-1.5">
                    <x-ui.button size="sm" variant="secondary" wire:click="previousPage"
                                 :disabled="$paginator->onFirstPage()">{{ __('Previous') }}</x-ui.button>
                    <span class="tabular-nums">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
                    <x-ui.button size="sm" variant="secondary" wire:click="nextPage"
                                 :disabled="! $paginator->hasMorePages()">{{ __('Next') }}</x-ui.button>
                </div>
            @endif
        </div>
    @endif

    {{-- Bulk delete confirmation -------------------------------------------- --}}
    <x-ui.modal wire:model="confirmingBulkDelete" size="sm" :title="__('Delete selected tasks?')"
                :description="__('Their subtasks go with them. Deleted tasks can be restored by an administrator, but they leave every board and report immediately.')">
        <p class="text-sm text-[var(--text-muted)]">
            {{ trans_choice('{1}:count task will be deleted.|[2,*]:count tasks will be deleted.', count($selected), ['count' => count($selected)]) }}
        </p>

        <x-slot:footer>
            <x-ui.button variant="secondary" size="md" wire:click="$set('confirmingBulkDelete', false)">
                {{ __('Cancel') }}
            </x-ui.button>
            <x-ui.button variant="danger" size="md" wire:click="bulkDelete" wire:target="bulkDelete">
                {{ __('Delete tasks') }}
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>

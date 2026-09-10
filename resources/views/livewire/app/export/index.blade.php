@php
    use App\Services\Export\ExportType;
    use App\Support\Formats;

    $type = $this->exportType();
    $total = $this->total;
    $preview = $this->preview;
    $headers = $this->export->headers();
    $scoped = $this->scopedProject;
@endphp

<div class="page py-5">

    {{-- ---------------------------------------------------------------- --}}
    {{-- Header                                                           --}}
    {{-- ---------------------------------------------------------------- --}}
    <header class="flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-base font-semibold tracking-tight text-[var(--text-strong)]">{{ __('Export') }}</h1>
            <p class="mt-0.5 max-w-2xl text-xs text-[var(--text-muted)]">
                {{ __('A CSV of what you can see, filtered the way you filter a list. The link below is the export — you can bookmark it, and it will answer with today\'s data.') }}
            </p>
        </div>

        <div class="flex items-center gap-1.5">
            <div wire:loading.delay class="pe-1 text-[var(--text-subtle)]">
                <x-ui.spinner class="size-4" />
            </div>

            {{--
                An anchor when there is something to fetch, a dead button when there is not:
                `disabled` means nothing on an <a>, and a link that downloads an empty file
                is a worse answer than a control that is visibly unavailable.
            --}}
            @if ($total > 0)
                <x-ui.button variant="primary" size="md" icon="icon.document"
                             :href="$this->downloadUrl()"
                             wire:key="download-{{ $type->value }}-{{ $total }}">
                    {{ trans_choice('{1}Download 1 row|[2,*]Download :count rows', $total, ['count' => Formats::number($total)]) }}
                </x-ui.button>
            @else
                <x-ui.button variant="primary" size="md" icon="icon.document" disabled>
                    {{ __('Nothing to download') }}
                </x-ui.button>
            @endif
        </div>
    </header>

    {{-- ---------------------------------------------------------------- --}}
    {{-- What to export                                                   --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="mt-4 grid gap-2 sm:grid-cols-3">
        @foreach (ExportType::all() as $option)
            @php $on = $type === $option; @endphp
            <button type="button"
                    wire:click="selectType('{{ $option->value }}')"
                    wire:key="export-type-{{ $option->value }}"
                    aria-pressed="{{ $on ? 'true' : 'false' }}"
                    class="flex items-start gap-2.5 rounded-lg border p-3 text-start transition-colors
                           {{ $on
                                ? 'border-[var(--accent)] bg-[var(--accent-soft)] shadow-xs'
                                : 'border-[var(--line-subtle)] bg-[var(--surface-panel)] hover:border-[var(--line-strong)]' }}">
                <span class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-md
                             {{ $on ? 'bg-[var(--surface-panel)]' : 'bg-[var(--surface-sunken)]' }}">
                    <x-dynamic-component :component="$option->icon()"
                                         class="size-4 {{ $on ? 'text-[var(--accent)]' : 'text-[var(--text-subtle)]' }}" />
                </span>
                <span class="min-w-0">
                    <span class="block text-sm font-medium text-[var(--text-strong)]">{{ $option->label() }}</span>
                    <span class="mt-0.5 block text-xs leading-relaxed text-[var(--text-muted)]">{{ $option->description() }}</span>
                </span>
            </button>
        @endforeach
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Filters                                                          --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="mt-4 rounded-lg border border-[var(--line-subtle)] bg-[var(--surface-panel)] p-3 shadow-panel">
        <div class="flex flex-wrap items-center gap-1.5">

            {{-- Search --}}
            <div class="relative w-full sm:w-56">
                <label for="export-search" class="sr-only">{{ __('Search') }}</label>
                <x-ui.input id="export-search" wire:model.live.debounce.400ms="search" busy-target="search" type="search" size="md"
                            icon="icon.search" autocomplete="off"
                            :placeholder="$type === ExportType::Time ? __('Search descriptions…') : __('Search by name…')" />
            </div>

            {{-- Projects --}}
            <x-ui.dropdown width="w-64">
                <x-slot:trigger>
                    <x-ui.button size="md" variant="{{ $projectIds ? 'soft' : 'secondary' }}" trailing-icon="icon.chevron-down">
                        {{ __('Projects') }}@if ($projectIds)<span class="tabular-nums">· {{ count($projectIds) }}</span>@endif
                    </x-ui.button>
                </x-slot:trigger>

                <div class="max-h-72 overflow-y-auto scrollbar-thin">
                    @forelse ($this->projectOptions as $project)
                        @php $on = in_array($project->getKey(), $projectIds); @endphp
                        <x-ui.dropdown-item wire:click="toggleFilter('projectIds', {{ $project->getKey() }})" :active="$on"
                                            wire:key="export-project-{{ $project->getKey() }}">
                            <span class="flex items-center gap-2 overflow-hidden">
                                <x-ui.tick :on="$on" />
                                <span class="font-mono text-2xs text-[var(--text-subtle)]"><x-ui.bidi>{{ $project->key }}</x-ui.bidi></span>
                                <span dir="auto" class="truncate">{{ $project->name }}</span>
                            </span>
                        </x-ui.dropdown-item>
                    @empty
                        <p class="px-2 py-1.5 text-xs text-[var(--text-subtle)]">{{ __('You are not on any project yet.') }}</p>
                    @endforelse
                </div>
            </x-ui.dropdown>

            @if ($this->shows('taskFilters'))
                {{-- Status: only meaningful inside one project --}}
                @if ($scoped)
                    <x-ui.dropdown width="w-56">
                        <x-slot:trigger>
                            <x-ui.button size="md" variant="{{ $statusIds ? 'soft' : 'secondary' }}" trailing-icon="icon.chevron-down">
                                {{ __('Status') }}@if ($statusIds)<span class="tabular-nums">· {{ count($statusIds) }}</span>@endif
                            </x-ui.button>
                        </x-slot:trigger>

                        <div class="max-h-72 overflow-y-auto scrollbar-thin">
                            @foreach ($this->statusOptions as $status)
                                @php $on = in_array($status->getKey(), $statusIds); @endphp
                                <x-ui.dropdown-item wire:click="toggleFilter('statusIds', {{ $status->getKey() }})" :active="$on"
                                                    wire:key="export-status-{{ $status->getKey() }}">
                                    <span class="flex items-center gap-2 overflow-hidden">
                                        <x-ui.tick :on="$on" />
                                        <x-ui.status-dot :color="$status->color ?? 'gray'" />
                                        <span dir="auto" class="truncate">{{ $status->name }}</span>
                                    </span>
                                </x-ui.dropdown-item>
                            @endforeach
                        </div>
                    </x-ui.dropdown>
                @endif

                {{-- Priority --}}
                <x-ui.dropdown width="w-48">
                    <x-slot:trigger>
                        <x-ui.button size="md" variant="{{ $priorities ? 'soft' : 'secondary' }}" trailing-icon="icon.chevron-down">
                            {{ __('Priority') }}@if ($priorities)<span class="tabular-nums">· {{ count($priorities) }}</span>@endif
                        </x-ui.button>
                    </x-slot:trigger>

                    @foreach ($this->priorityOptions() as $priority)
                        @php $on = in_array($priority->value, $priorities); @endphp
                        <x-ui.dropdown-item wire:click="toggleFilter('priorities', '{{ $priority->value }}')" :active="$on"
                                            wire:key="export-priority-{{ $priority->value }}">
                            <span class="flex items-center gap-2 overflow-hidden">
                                <x-ui.tick :on="$on" />
                                <x-ui.badge :color="$priority->color()" size="sm">{{ $priority->label() }}</x-ui.badge>
                            </span>
                        </x-ui.dropdown-item>
                    @endforeach
                </x-ui.dropdown>

                {{-- Tags --}}
                <x-ui.dropdown width="w-56">
                    <x-slot:trigger>
                        <x-ui.button size="md" variant="{{ $tagIds ? 'soft' : 'secondary' }}" trailing-icon="icon.chevron-down">
                            {{ __('Tags') }}@if ($tagIds)<span class="tabular-nums">· {{ count($tagIds) }}</span>@endif
                        </x-ui.button>
                    </x-slot:trigger>

                    <div class="max-h-72 overflow-y-auto scrollbar-thin">
                        @forelse ($this->tagOptions as $tag)
                            @php $on = in_array($tag->getKey(), $tagIds); @endphp
                            <x-ui.dropdown-item wire:click="toggleFilter('tagIds', {{ $tag->getKey() }})" :active="$on"
                                                wire:key="export-tag-{{ $tag->getKey() }}">
                                <span class="flex items-center gap-2 overflow-hidden">
                                    <x-ui.tick :on="$on" />
                                    <span dir="auto" class="truncate">{{ $tag->name }}</span>
                                </span>
                            </x-ui.dropdown-item>
                        @empty
                            <p class="px-2 py-1.5 text-xs text-[var(--text-subtle)]">{{ __('This workspace has no tags.') }}</p>
                        @endforelse
                    </div>
                </x-ui.dropdown>

                {{-- Milestone --}}
                @if ($scoped && $this->milestoneOptions->isNotEmpty())
                    <x-ui.dropdown width="w-60">
                        <x-slot:trigger>
                            <x-ui.button size="md" variant="{{ $milestoneIds ? 'soft' : 'secondary' }}" trailing-icon="icon.chevron-down">
                                {{ __('Milestone') }}@if ($milestoneIds)<span class="tabular-nums">· {{ count($milestoneIds) }}</span>@endif
                            </x-ui.button>
                        </x-slot:trigger>

                        <div class="max-h-72 overflow-y-auto scrollbar-thin">
                            @foreach ($this->milestoneOptions as $milestone)
                                @php $on = in_array($milestone->getKey(), $milestoneIds); @endphp
                                <x-ui.dropdown-item wire:click="toggleFilter('milestoneIds', {{ $milestone->getKey() }})" :active="$on"
                                                    wire:key="export-milestone-{{ $milestone->getKey() }}">
                                    <span class="flex items-center gap-2 overflow-hidden">
                                        <x-ui.tick :on="$on" />
                                        <span dir="auto" class="truncate">{{ $milestone->name }}</span>
                                    </span>
                                </x-ui.dropdown-item>
                            @endforeach
                        </div>
                    </x-ui.dropdown>
                @endif

                {{-- Due window --}}
                <x-ui.dropdown width="w-48">
                    <x-slot:trigger>
                        <x-ui.button size="md" variant="{{ $dueRange ? 'soft' : 'secondary' }}" trailing-icon="icon.chevron-down">
                            {{ $dueRange ? $this->dueRanges()[$dueRange] : __('Due') }}
                        </x-ui.button>
                    </x-slot:trigger>

                    <x-ui.dropdown-item wire:click="setDueRange('')" :active="$dueRange === ''">
                        <span class="flex items-center gap-2">
                            <x-ui.tick :on="$dueRange === ''" />{{ __('Any time') }}
                        </span>
                    </x-ui.dropdown-item>
                    @foreach ($this->dueRanges() as $value => $label)
                        <x-ui.dropdown-item wire:click="setDueRange('{{ $value }}')" :active="$dueRange === $value"
                                            wire:key="export-due-{{ $value }}">
                            <span class="flex items-center gap-2">
                                <x-ui.tick :on="$dueRange === $value" />{{ $label }}
                            </span>
                        </x-ui.dropdown-item>
                    @endforeach
                </x-ui.dropdown>
            @endif

            {{-- People --}}
            @if ($this->shows('people'))
                <x-ui.dropdown width="w-60">
                    <x-slot:trigger>
                        <x-ui.button size="md" variant="{{ $assigneeIds || $unassignedOnly ? 'soft' : 'secondary' }}"
                                     trailing-icon="icon.chevron-down">
                            {{ $type === ExportType::Time ? __('Person') : __('Assignee') }}@if ($assigneeIds)<span class="tabular-nums">· {{ count($assigneeIds) }}</span>@endif
                        </x-ui.button>
                    </x-slot:trigger>

                    @if ($this->shows('taskFilters'))
                        <x-ui.dropdown-item wire:click="$toggle('unassignedOnly')" :active="$unassignedOnly">
                            <span class="flex items-center gap-2">
                                <x-ui.tick :on="$unassignedOnly" />{{ __('Unassigned only') }}
                            </span>
                        </x-ui.dropdown-item>
                        <x-ui.dropdown-separator />
                    @endif

                    <div class="max-h-72 overflow-y-auto scrollbar-thin">
                        @foreach ($this->memberOptions as $member)
                            @php $on = in_array($member->getKey(), $assigneeIds); @endphp
                            <x-ui.dropdown-item wire:click="toggleFilter('assigneeIds', {{ $member->getKey() }})" :active="$on"
                                                wire:key="export-member-{{ $member->getKey() }}">
                                <span class="flex items-center gap-2 overflow-hidden">
                                    <x-ui.tick :on="$on" />
                                    <x-ui.avatar :user="$member" size="xs" />
                                    <span dir="auto" class="truncate">{{ $member->name }}</span>
                                </span>
                            </x-ui.dropdown-item>
                        @endforeach
                    </div>
                </x-ui.dropdown>
            @endif

            {{-- Dates, for time --}}
            @if ($this->shows('dates'))
                <label class="sr-only" for="export-from">{{ __('From') }}</label>
                <x-ui.input id="export-from" type="date" size="md" class="w-36" wire:model.live="from" />
                <label class="sr-only" for="export-to">{{ __('To') }}</label>
                <x-ui.input id="export-to" type="date" size="md" class="w-36" wire:model.live="to" />
            @endif

            {{-- Switches --}}
            <x-ui.dropdown width="w-64" align="end" class="ms-auto">
                <x-slot:trigger>
                    <x-ui.button size="md" variant="secondary" trailing-icon="icon.chevron-down">{{ __('Options') }}</x-ui.button>
                </x-slot:trigger>

                @if ($this->shows('taskFilters'))
                    <x-ui.dropdown-item wire:click="$toggle('includeCompleted')" :active="$includeCompleted">
                        <span class="flex items-center gap-2">
                            <x-ui.tick :on="$includeCompleted" />{{ __('Include completed tasks') }}
                        </span>
                    </x-ui.dropdown-item>
                    <x-ui.dropdown-item wire:click="$toggle('overdueOnly')" :active="$overdueOnly">
                        <span class="flex items-center gap-2">
                            <x-ui.tick :on="$overdueOnly" />{{ __('Overdue only') }}
                        </span>
                    </x-ui.dropdown-item>
                @endif

                @if ($this->shows('billable'))
                    <x-ui.dropdown-item wire:click="$toggle('billableOnly')" :active="$billableOnly">
                        <span class="flex items-center gap-2">
                            <x-ui.tick :on="$billableOnly" />{{ __('Billable time only') }}
                        </span>
                    </x-ui.dropdown-item>
                @endif

                <x-ui.dropdown-item wire:click="$toggle('includeArchived')" :active="$includeArchived">
                    <span class="flex items-center gap-2">
                        <x-ui.tick :on="$includeArchived" />{{ __('Include archived projects') }}
                    </span>
                </x-ui.dropdown-item>
            </x-ui.dropdown>

            @if ($this->activeFilterCount() > 0)
                <x-ui.button size="md" variant="ghost" wire:click="clearFilters">{{ __('Clear') }}</x-ui.button>
            @endif
        </div>
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Preview                                                          --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="mt-4 overflow-hidden rounded-lg border border-[var(--line-subtle)] bg-[var(--surface-panel)] shadow-panel"
         wire:loading.class="opacity-60">

        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--line-subtle)] px-4 py-2.5">
            <div class="min-w-0">
                <h2 class="text-sm font-semibold text-[var(--text-strong)]">{{ __('Preview') }}</h2>
                <p class="mt-0.5 text-2xs text-[var(--text-muted)]">
                    {{ trans_choice('{0}No row matches|{1}1 row|[2,*]:count rows', $total, ['count' => Formats::number($total)]) }}
                    · {{ trans_choice('{1}:count column|[2,*]:count columns', count($headers), ['count' => count($headers)]) }}
                    @if ($this->activeFilterCount() > 0)
                        · {{ trans_choice('{1}:count filter applied|[2,*]:count filters applied', $this->activeFilterCount(), ['count' => $this->activeFilterCount()]) }}
                    @endif
                </p>
            </div>

            @if ($preview !== [])
                <p class="text-2xs text-[var(--text-subtle)]">
                    {{ __('First :count rows, exactly as the file holds them.', ['count' => count($preview)]) }}
                </p>
            @endif
        </div>

        @if ($preview === [])
            <x-ui.empty-state icon="icon.document"
                              :title="__('Nothing matches these filters')"
                              :description="__('Widen the selection — or pick another table above. An export always contains what you can see and nothing else, so an empty result here means an empty file.')">
                <x-slot:actions>
                    @if ($this->activeFilterCount() > 0)
                        <x-ui.button variant="secondary" size="sm" wire:click="clearFilters">{{ __('Clear filters') }}</x-ui.button>
                    @endif
                </x-slot:actions>
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto scrollbar-thin">
                <table class="w-full min-w-max text-xs">
                    <thead>
                        <tr class="border-b border-[var(--line-subtle)] text-start text-2xs uppercase tracking-wider text-[var(--text-muted)]">
                            @foreach ($headers as $header)
                                <th scope="col" class="whitespace-nowrap px-3 py-2 font-semibold">{{ $header }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--line-subtle)]">
                        @foreach ($preview as $index => $row)
                            <tr wire:key="export-preview-{{ $index }}">
                                @foreach ($row as $cell)
                                    <td class="max-w-64 truncate px-3 py-1.5 text-[var(--text-DEFAULT)]">
                                        {{ $cell === null || $cell === '' ? '—' : $cell }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- What the file does about spreadsheets                            --}}
    {{-- ---------------------------------------------------------------- --}}
    <p class="mt-3 max-w-3xl text-2xs leading-relaxed text-[var(--text-subtle)]">
        <x-icon.shield class="me-1 inline size-3.5 align-[-2px]" aria-hidden="true" />
        {{ __('A cell that begins with =, +, -, @ or a tab is a formula to Excel, LibreOffice and Google Sheets. Planvio stores those as text, so it writes them out with a leading apostrophe: the spreadsheet shows the original characters and runs nothing. The file is UTF-8 with a byte-order mark so accented names survive Excel on Windows.') }}
    </p>
</div>

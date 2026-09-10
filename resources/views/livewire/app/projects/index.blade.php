@php
    $projects = $this->projects;
    $favourites = $this->favouriteIds;

    /** A stored colour is data, not a design decision — but only a real hex reaches CSS. */
    $tint = static fn (?string $value): string =>
        is_string($value) && preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/', $value) === 1
            ? $value
            : 'var(--accent)';

    $sorts = [
        'recent' => __('Last updated'),
        'name' => __('Name'),
        'progress' => __('Progress'),
        'target' => __('Target date'),
        'health' => __('Health'),
        'created' => __('Newest'),
    ];

    $busy = 'search,status,health,type,memberId,archived,sort,clearFilters,setDisplay,'
        .'gotoPage,nextPage,previousPage';
@endphp

<div class="page py-5">

    {{-- ------------------------------------------------------------------ --}}
    {{-- Title row                                                          --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-baseline gap-2">
            <h2 class="text-base font-semibold tracking-tight text-[var(--text-strong)]">
                {{ __('Projects') }}
            </h2>
            @if ($projects->total() > 0)
                <span class="text-xs tabular-nums text-[var(--text-subtle)]">{{ $projects->total() }}</span>
            @endif
        </div>

        <div class="flex items-center gap-2">
            @if ($this->aiAvailable)
                <x-ui.button variant="secondary" size="md" icon="icon.sparkles"
                             x-on:click="$dispatch('open-ai-panel', { intent: 'create' })">
                    <span class="hidden sm:inline">{{ __('Create with AI') }}</span>
                    <span class="sm:hidden">{{ __('AI') }}</span>
                </x-ui.button>
            @endif

            @can('project.create', [\App\Models\Project::class, $workspace])
                <x-ui.button :href="route('app.projects.create', $workspace)"
                             variant="primary" size="md" icon="icon.plus">
                    {{ __('New project') }}
                </x-ui.button>
            @endcan
        </div>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Toolbar                                                            --}}
    {{-- ------------------------------------------------------------------ --}}
    {{-- One wrapping row rather than a scrolling strip: a filter you cannot see is a filter
         you will forget you set, and this bar can carry six controls. --}}
    <div class="mt-4 flex flex-wrap items-center gap-2">

        <div class="w-full sm:w-56 lg:w-64">
            <label for="projects-search" class="sr-only">{{ __('Search projects') }}</label>
            <x-ui.input id="projects-search"
                        type="search"
                        icon="icon.search"
                        wire:model.live.debounce.300ms="search" busy-target="search"
                        :placeholder="__('Search by name, key or client')" />
        </div>

        <div class="flex flex-1 flex-wrap items-center gap-2">
            <div class="min-w-32 flex-1 sm:max-w-40">
                <label for="projects-status" class="sr-only">{{ __('Filter by stage') }}</label>
                <x-ui.select id="projects-status" wire:model.live="status" :placeholder="__('Any stage')">
                    @foreach ($this->statuses as $status)
                        <option value="{{ $status->getKey() }}">{{ $status->name }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="min-w-32 flex-1 sm:max-w-40">
                <label for="projects-health" class="sr-only">{{ __('Filter by health') }}</label>
                <x-ui.select id="projects-health" wire:model.live="health" :placeholder="__('Any health')">
                    @foreach (\App\Enums\ProjectHealth::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="min-w-32 flex-1 sm:max-w-40">
                <label for="projects-type" class="sr-only">{{ __('Filter by type') }}</label>
                <x-ui.select id="projects-type" wire:model.live="type" :placeholder="__('Any type')">
                    @foreach (\App\Enums\ProjectType::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="min-w-32 flex-1 sm:max-w-40">
                <label for="projects-member" class="sr-only">{{ __('Filter by person') }}</label>
                <x-ui.select id="projects-member" wire:model.live="memberId" :placeholder="__('Anyone')">
                    @foreach ($this->people as $person)
                        <option value="{{ $person->getKey() }}">{{ $person->name }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="min-w-32 flex-1 sm:max-w-40">
                <label for="projects-archived" class="sr-only">{{ __('Archived projects') }}</label>
                <x-ui.select id="projects-archived" wire:model.live="archived">
                    <option value="active">{{ __('Active') }}</option>
                    <option value="archived">{{ __('Archived') }}</option>
                    <option value="all">{{ __('All') }}</option>
                </x-ui.select>
            </div>

            @if ($this->activeFilterCount > 0)
                <x-ui.button variant="ghost" size="md" wire:click="clearFilters" class="shrink-0">
                    {{ __('Clear') }}
                    <span class="ms-1 rounded bg-[var(--surface-active)] px-1 text-2xs tabular-nums">
                        {{ $this->activeFilterCount }}
                    </span>
                </x-ui.button>
            @endif
        </div>

        <div class="ms-auto flex items-center gap-2">
            <div class="w-40">
                <label for="projects-sort" class="sr-only">{{ __('Sort projects') }}</label>
                <x-ui.select id="projects-sort" wire:model.live="sort">
                    @foreach ($sorts as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            {{-- Segmented control. The choice is remembered per person, not per link. --}}
            <div class="inline-flex shrink-0 items-center gap-0.5 rounded-lg bg-[var(--surface-sunken)] p-0.5"
                 role="group" aria-label="{{ __('Layout') }}">
                @foreach ([['grid', 'icon.board', __('Grid')], ['list', 'icon.list', __('List')]] as [$mode, $icon, $modeLabel])
                    <button type="button"
                            wire:click="setDisplay('{{ $mode }}')"
                            aria-pressed="{{ $display === $mode ? 'true' : 'false' }}"
                            aria-label="{{ $modeLabel }}"
                            class="grid size-7 place-items-center rounded-md transition-colors
                                   {{ $display === $mode
                                        ? 'bg-[var(--surface-panel)] text-[var(--text-strong)] shadow-xs'
                                        : 'text-[var(--text-muted)] hover:text-[var(--text-DEFAULT)]' }}">
                        <x-dynamic-component :component="$icon" class="size-4" />
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Results                                                            --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="relative mt-4">

        <div wire:loading.delay.class="opacity-40" wire:target="{{ $busy }}"
             class="transition-opacity duration-150">

            @if ($projects->isEmpty())
                <div class="rounded-lg border border-[var(--line-subtle)] bg-[var(--surface-panel)] shadow-panel">
                    @if ($this->hasAnyProject)
                        <x-ui.empty-state icon="icon.search"
                                          :title="__('Nothing matches those filters')"
                                          :description="__('There are projects in this workspace, just none that satisfy every filter at once. Widen one and they come back.')">
                            <x-slot:actions>
                                <x-ui.button variant="secondary" size="md" wire:click="clearFilters">
                                    {{ __('Clear filters') }}
                                </x-ui.button>
                            </x-slot:actions>
                        </x-ui.empty-state>
                    @else
                        <x-ui.empty-state icon="icon.folder"
                                          :title="__('No projects yet')"
                                          :description="__('A project holds the tasks, milestones, files and decisions for one piece of work. Start from a blank board, or from a template that already has the plan in it.')">
                            <x-slot:actions>
                                @can('project.create', [\App\Models\Project::class, $workspace])
                                    <x-ui.button :href="route('app.projects.create', $workspace)"
                                                 variant="primary" size="md" icon="icon.plus">
                                        {{ __('New project') }}
                                    </x-ui.button>
                                @endcan
                                @if ($this->aiAvailable)
                                    <x-ui.button variant="secondary" size="md" icon="icon.sparkles"
                                                 x-on:click="$dispatch('open-ai-panel', { intent: 'create' })">
                                        {{ __('Describe it to AI') }}
                                    </x-ui.button>
                                @endif
                            </x-slot:actions>
                        </x-ui.empty-state>
                    @endif
                </div>

            @elseif ($display === 'grid')
                {{-- ---------------------------------------------------------- --}}
                {{-- Grid                                                       --}}
                {{-- ---------------------------------------------------------- --}}
                <ul class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($projects as $project)
                        @php $starred = in_array($project->getKey(), $favourites, true); @endphp

                        <li wire:key="project-card-{{ $project->getKey() }}"
                            class="group relative flex flex-col rounded-lg border border-[var(--line-subtle)]
                                   bg-[var(--surface-panel)] p-3.5 shadow-panel transition-[border-color,box-shadow]
                                   duration-150 hover:border-[var(--line-DEFAULT)] hover:shadow-raised
                                   focus-within:border-[var(--line-DEFAULT)]">

                            <div class="flex items-start gap-2.5">
                                <span class="grid size-8 shrink-0 place-items-center rounded-lg text-sm font-semibold leading-none"
                                      style="background-color: color-mix(in oklab, {{ $tint($project->color) }} 16%, transparent); color: {{ $tint($project->color) }}"
                                      aria-hidden="true">
                                    {{ $project->icon ?: mb_strtoupper(mb_substr((string) $project->name, 0, 1)) }}
                                </span>

                                <div class="min-w-0 flex-1">
                                    {{-- The whole card is the link target; the star floats above it. --}}
                                    <a dir="auto" href="{{ route('app.projects.show', [$workspace, $project]) }}"
                                       class="block truncate text-sm font-semibold text-[var(--text-strong)]
                                              after:absolute after:inset-0 after:content-['']">
                                        {{ $project->name }}
                                    </a>
                                    <p class="mt-0.5 flex items-center gap-1.5 text-2xs text-[var(--text-subtle)]">
                                        <span class="font-mono tracking-wide">{{ $project->display_key }}</span>
                                        @if ($project->status)
                                            <span aria-hidden="true">·</span>
                                            <span dir="auto" class="truncate">{{ $project->status->name }}</span>
                                        @endif
                                        @if ($project->is_archived)
                                            <span aria-hidden="true">·</span>
                                            <span>{{ __('Archived') }}</span>
                                        @endif
                                    </p>
                                </div>

                                <button type="button"
                                        wire:click="toggleFavouriteFor({{ $project->getKey() }})"
                                        aria-pressed="{{ $starred ? 'true' : 'false' }}"
                                        aria-label="{{ $starred
                                            ? __('Remove :project from favourites', ['project' => $project->name])
                                            : __('Add :project to favourites', ['project' => $project->name]) }}"
                                        class="relative z-10 -me-1 -mt-1 grid size-7 shrink-0 place-items-center rounded-md
                                               transition-colors hover:bg-[var(--surface-hover)]
                                               {{ $starred
                                                    ? 'text-caution-500'
                                                    : 'text-[var(--text-subtle)] opacity-0 group-hover:opacity-100 focus-visible:opacity-100' }}">
                                    <x-icon.star class="size-4" :fill="$starred ? 'currentColor' : 'none'" />
                                </button>
                            </div>

                            <div class="mt-3">
                                <div class="flex items-center justify-between gap-2 pb-1">
                                    <x-ui.badge :color="$project->health->color()" size="sm" dot>
                                        {{ $project->health->label() }}
                                    </x-ui.badge>
                                    <span class="text-xs font-medium tabular-nums text-[var(--text-muted)]">
                                        {{ $project->progress }}%
                                    </span>
                                </div>
                                <x-ui.progress :value="$project->progress" size="sm"
                                               :label="__('Progress of :project', ['project' => $project->name])" />
                            </div>

                            <dl class="mt-3 grid grid-cols-3 gap-2 border-t border-[var(--line-subtle)] pt-3">
                                @foreach ([
                                    [__('Tasks'), (int) $project->tasks_count, false],
                                    [__('Done'), (int) $project->completed_tasks_count, false],
                                    [__('Overdue'), (int) $project->overdue_tasks_count, true],
                                ] as [$statLabel, $statValue, $critical])
                                    <div>
                                        <dt class="text-2xs uppercase tracking-wide text-[var(--text-subtle)]">{{ $statLabel }}</dt>
                                        <dd class="text-sm font-semibold tabular-nums {{ $critical && $statValue > 0 ? 'text-critical-600' : 'text-[var(--text-strong)]' }}">
                                            {{ $statValue }}
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>

                            <div class="mt-3 flex items-center justify-between gap-2">
                                @if ($project->members->isNotEmpty())
                                    <x-ui.avatar-stack :users="$project->members" :max="4" size="sm" />
                                @else
                                    <span class="text-2xs text-[var(--text-subtle)]">{{ __('No members yet') }}</span>
                                @endif

                                @if ($project->target_date)
                                    <span class="inline-flex items-center gap-1 text-2xs {{ $project->is_overdue ? 'text-critical-600' : 'text-[var(--text-muted)]' }}">
                                        <x-icon.calendar class="size-3.5" />
                                        {{ $project->target_date->isoFormat('D MMM YYYY') }}
                                    </span>
                                @else
                                    <span class="text-2xs text-[var(--text-subtle)]">{{ __('No target date') }}</span>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>

            @else
                {{-- ---------------------------------------------------------- --}}
                {{-- List — a table from md up, stacked rows below              --}}
                {{-- ---------------------------------------------------------- --}}
                <div class="overflow-hidden rounded-lg border border-[var(--line-subtle)] bg-[var(--surface-panel)] shadow-panel">
                    <table class="hidden w-full border-collapse text-sm md:table">
                        <thead>
                            <tr class="border-b border-[var(--line-subtle)] bg-[var(--surface-sunken)] text-start">
                                <th scope="col" class="w-8 px-2 py-2"><span class="sr-only">{{ __('Favourite') }}</span></th>
                                <th scope="col" class="px-2 py-2 text-2xs font-semibold uppercase tracking-wide text-[var(--text-subtle)]">{{ __('Project') }}</th>
                                <th scope="col" class="px-2 py-2 text-2xs font-semibold uppercase tracking-wide text-[var(--text-subtle)]">{{ __('Stage') }}</th>
                                <th scope="col" class="px-2 py-2 text-2xs font-semibold uppercase tracking-wide text-[var(--text-subtle)]">{{ __('Health') }}</th>
                                <th scope="col" class="w-40 px-2 py-2 text-2xs font-semibold uppercase tracking-wide text-[var(--text-subtle)]">{{ __('Progress') }}</th>
                                <th scope="col" class="px-2 py-2 text-end text-2xs font-semibold uppercase tracking-wide text-[var(--text-subtle)]">{{ __('Tasks') }}</th>
                                <th scope="col" class="px-2 py-2 text-end text-2xs font-semibold uppercase tracking-wide text-[var(--text-subtle)]">{{ __('Overdue') }}</th>
                                <th scope="col" class="px-2 py-2 text-2xs font-semibold uppercase tracking-wide text-[var(--text-subtle)]">{{ __('Team') }}</th>
                                <th scope="col" class="px-2 py-2 text-2xs font-semibold uppercase tracking-wide text-[var(--text-subtle)]">{{ __('Target') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($projects as $project)
                                @php $starred = in_array($project->getKey(), $favourites, true); @endphp

                                <tr wire:key="project-row-{{ $project->getKey() }}"
                                    class="group border-b border-[var(--line-subtle)] transition-colors last:border-0 hover:bg-[var(--surface-hover)]">
                                    <td class="px-2 py-2 align-middle">
                                        <button type="button"
                                                wire:click="toggleFavouriteFor({{ $project->getKey() }})"
                                                aria-pressed="{{ $starred ? 'true' : 'false' }}"
                                                aria-label="{{ $starred
                                                    ? __('Remove :project from favourites', ['project' => $project->name])
                                                    : __('Add :project to favourites', ['project' => $project->name]) }}"
                                                class="grid size-6 place-items-center rounded transition-colors hover:bg-[var(--surface-active)]
                                                       {{ $starred ? 'text-caution-500' : 'text-[var(--text-subtle)] opacity-0 group-hover:opacity-100 focus-visible:opacity-100' }}">
                                            <x-icon.star class="size-3.5" :fill="$starred ? 'currentColor' : 'none'" />
                                        </button>
                                    </td>

                                    <td class="px-2 py-2 align-middle">
                                        <div class="flex items-center gap-2">
                                            <span class="grid size-6 shrink-0 place-items-center rounded text-2xs font-semibold leading-none"
                                                  style="background-color: color-mix(in oklab, {{ $tint($project->color) }} 16%, transparent); color: {{ $tint($project->color) }}"
                                                  aria-hidden="true">
                                                {{ $project->icon ?: mb_strtoupper(mb_substr((string) $project->name, 0, 1)) }}
                                            </span>
                                            <a href="{{ route('app.projects.show', [$workspace, $project]) }}"
                                               dir="auto" class="min-w-0 truncate font-medium text-[var(--text-strong)] hover:text-[var(--accent)]">
                                                {{ $project->name }}
                                            </a>
                                            <span class="shrink-0 font-mono text-2xs text-[var(--text-subtle)]">{{ $project->display_key }}</span>
                                            @if ($project->is_archived)
                                                <x-ui.badge color="gray" size="sm">{{ __('Archived') }}</x-ui.badge>
                                            @endif
                                        </div>
                                    </td>

                                    <td class="px-2 py-2 align-middle">
                                        @if ($project->status)
                                            <x-ui.badge :color="$project->status->category->color()" size="sm" dot>
                                                {{ $project->status->name }}
                                            </x-ui.badge>
                                        @else
                                            <span class="text-xs text-[var(--text-subtle)]">—</span>
                                        @endif
                                    </td>

                                    <td class="px-2 py-2 align-middle">
                                        <x-ui.badge :color="$project->health->color()" size="sm" dot>
                                            {{ $project->health->label() }}
                                        </x-ui.badge>
                                    </td>

                                    <td class="px-2 py-2 align-middle">
                                        <x-ui.progress :value="$project->progress" size="sm" show-label
                                                       :label="__('Progress of :project', ['project' => $project->name])" />
                                    </td>

                                    <td class="px-2 py-2 text-end align-middle tabular-nums text-[var(--text-muted)]">
                                        {{ $project->completed_tasks_count }}<span class="text-[var(--text-subtle)]">/{{ $project->tasks_count }}</span>
                                    </td>

                                    <td class="px-2 py-2 text-end align-middle tabular-nums {{ $project->overdue_tasks_count > 0 ? 'font-medium text-critical-600' : 'text-[var(--text-subtle)]' }}">
                                        {{ $project->overdue_tasks_count }}
                                    </td>

                                    <td class="px-2 py-2 align-middle">
                                        @if ($project->members->isNotEmpty())
                                            <x-ui.avatar-stack :users="$project->members" :max="3" size="xs" />
                                        @else
                                            <span class="text-xs text-[var(--text-subtle)]">—</span>
                                        @endif
                                    </td>

                                    <td class="whitespace-nowrap px-2 py-2 align-middle text-xs {{ $project->is_overdue ? 'text-critical-600' : 'text-[var(--text-muted)]' }}">
                                        {{ $project->target_date?->isoFormat('D MMM YYYY') ?? '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    {{-- Under md the table would need a horizontal scroll to stay readable,
                         so the same rows are re-cut as stacked blocks instead. --}}
                    <ul class="divide-y divide-[var(--line-subtle)] md:hidden">
                        @foreach ($projects as $project)
                            @php $starred = in_array($project->getKey(), $favourites, true); @endphp

                            <li wire:key="project-stack-{{ $project->getKey() }}" class="p-3">
                                <div class="flex items-start gap-2.5">
                                    <span class="grid size-8 shrink-0 place-items-center rounded-lg text-sm font-semibold leading-none"
                                          style="background-color: color-mix(in oklab, {{ $tint($project->color) }} 16%, transparent); color: {{ $tint($project->color) }}"
                                          aria-hidden="true">
                                        {{ $project->icon ?: mb_strtoupper(mb_substr((string) $project->name, 0, 1)) }}
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <a dir="auto" href="{{ route('app.projects.show', [$workspace, $project]) }}"
                                           class="block truncate text-sm font-semibold text-[var(--text-strong)]">
                                            {{ $project->name }}
                                        </a>
                                        <p class="mt-0.5 text-2xs text-[var(--text-subtle)]">
                                            <span class="font-mono">{{ $project->display_key }}</span>
                                            · {{ __(':done of :total tasks', ['done' => $project->completed_tasks_count, 'total' => $project->tasks_count]) }}
                                            @if ($project->target_date)
                                                · {{ $project->target_date->isoFormat('D MMM') }}
                                            @endif
                                        </p>
                                    </div>
                                    <button type="button"
                                            wire:click="toggleFavouriteFor({{ $project->getKey() }})"
                                            aria-pressed="{{ $starred ? 'true' : 'false' }}"
                                            aria-label="{{ $starred
                                                ? __('Remove :project from favourites', ['project' => $project->name])
                                                : __('Add :project to favourites', ['project' => $project->name]) }}"
                                            class="grid size-7 shrink-0 place-items-center rounded-md {{ $starred ? 'text-caution-500' : 'text-[var(--text-subtle)]' }}">
                                        <x-icon.star class="size-4" :fill="$starred ? 'currentColor' : 'none'" />
                                    </button>
                                </div>

                                <div class="mt-2.5 flex items-center gap-2">
                                    <x-ui.badge :color="$project->health->color()" size="sm" dot>
                                        {{ $project->health->label() }}
                                    </x-ui.badge>
                                    <x-ui.progress :value="$project->progress" size="sm" show-label
                                                   :label="__('Progress of :project', ['project' => $project->name])" />
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        {{-- A filter change is a server round trip; say so rather than freezing the grid. --}}
        <div wire:loading.delay.flex wire:target="{{ $busy }}"
             class="pointer-events-none absolute inset-x-0 top-6 hidden justify-center">
            <span class="inline-flex items-center gap-2 rounded-full border border-[var(--line-subtle)]
                         bg-[var(--surface-raised)] px-3 py-1.5 text-xs text-[var(--text-muted)] shadow-raised">
                <x-ui.spinner class="size-3.5" />
                {{ __('Updating…') }}
            </span>
        </div>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Pager                                                              --}}
    {{-- ------------------------------------------------------------------ --}}
    @if ($projects->hasPages())
        <nav class="mt-4 flex items-center justify-between gap-3" aria-label="{{ __('Projects pagination') }}">
            <p class="text-xs tabular-nums text-[var(--text-muted)]">
                {{-- Formats::range isolates the span: an en dash between two figures is a
                     neutral, and in an Arabic sentence it prints "10–1". --}}
                {{ __('Showing :from–:to of :total', \App\Support\Formats::range(
                    $projects->firstItem(),
                    $projects->lastItem(),
                ) + ['total' => $projects->total()]) }}
            </p>
            <div class="flex items-center gap-2">
                <x-ui.button variant="secondary" size="sm" wire:click="previousPage"
                             :disabled="$projects->onFirstPage()">
                    {{ __('Previous') }}
                </x-ui.button>
                <x-ui.button variant="secondary" size="sm" wire:click="nextPage"
                             :disabled="! $projects->hasMorePages()">
                    {{ __('Next') }}
                </x-ui.button>
            </div>
        </nav>
    @endif
</div>

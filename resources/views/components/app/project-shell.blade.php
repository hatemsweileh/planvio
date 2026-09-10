@props([
    'project',
    'workspace',
    'current' => 'overview',
    'favourite' => null,
    'counts' => [],
    'health' => null,
])

{{--
    The chrome every project screen sits in: identity, state, the actions menu and the tab
    bar. It lives here rather than in each tab's view so the nine tabs cannot drift apart —
    a tab added below appears on every screen at once, and the active underline is derived
    from one `$current` key rather than from nine copies of a route check.

    `$favourite` is tri-state on purpose. A boolean renders the star wired to
    `toggleFavourite()`, which a host component gets from
    App\Livewire\App\Projects\Concerns\InteractsWithProjectShell; null renders no star at
    all, so a tab that has not adopted the trait cannot ship a dead control.

    The `stats` slot sits between the header and the tabs. When it is absent the whole block
    sticks to the top of the scroll container — a board or a long task list keeps its tabs
    reachable — and when it is present it does not, because pinning a six-cell strip would
    eat a third of a phone screen.
--}}

@php
    // A stored colour is data, not a design decision — but only a real hex reaches CSS.
    $tint = is_string($project->color) && preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/', $project->color) === 1
        ? $project->color
        : 'var(--accent)';

    $sticky = ! isset($stats);

    /**
     * `projects.health` is a denormalised cache, refreshed when the calculator next runs.
     * A screen that has just measured health passes what it measured, so the badge in the
     * header and the facts in the body can never contradict each other.
     */
    $shownHealth = $health ?? $project->health;

    $tabs = [
        ['key' => 'overview', 'route' => 'app.projects.show', 'icon' => 'icon.home', 'label' => __('Overview')],
        ['key' => 'tasks', 'route' => 'app.projects.tasks', 'icon' => 'icon.list', 'label' => __('Tasks')],
        ['key' => 'board', 'route' => 'app.projects.board', 'icon' => 'icon.board', 'label' => __('Board')],
        ['key' => 'timeline', 'route' => 'app.projects.timeline', 'icon' => 'icon.timeline', 'label' => __('Timeline')],
        ['key' => 'calendar', 'route' => 'app.projects.calendar', 'icon' => 'icon.calendar', 'label' => __('Calendar')],
        ['key' => 'files', 'route' => 'app.projects.files', 'icon' => 'icon.paperclip', 'label' => __('Files')],
        ['key' => 'wiki', 'route' => 'app.projects.wiki', 'icon' => 'icon.document', 'label' => __('Wiki')],
        ['key' => 'activity', 'route' => 'app.projects.activity', 'icon' => 'icon.clock', 'label' => __('Activity')],
    ];
@endphp

<div {{ $attributes->merge([
    'class' => 'border-b border-[var(--line-subtle)] bg-[var(--surface-panel)] '
        .($sticky ? 'sticky top-0 z-20' : ''),
]) }}>
    <div class="page">

        {{-- ---------------------------------------------------------------- --}}
        {{-- Identity and state                                               --}}
        {{-- ---------------------------------------------------------------- --}}
        <div class="flex items-start gap-3 pt-4">

            <span class="grid size-9 shrink-0 place-items-center rounded-lg text-base font-semibold leading-none"
                  style="background-color: color-mix(in oklab, {{ $tint }} 16%, transparent); color: {{ $tint }}"
                  aria-hidden="true">
                @if ($project->icon)
                    {{ $project->icon }}
                @else
                    {{ mb_strtoupper(mb_substr((string) $project->name, 0, 1)) }}
                @endif
            </span>

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <h1 dir="auto" class="truncate text-base font-semibold tracking-tight text-[var(--text-strong)]">
                        {{ $project->name }}
                    </h1>

                    <span class="shrink-0 rounded border border-[var(--line-subtle)] bg-[var(--surface-sunken)]
                                 px-1.5 py-0.5 font-mono text-2xs font-medium tracking-wide text-[var(--text-muted)]">
                        {{ $project->display_key }}
                    </span>

                    @if ($project->is_archived)
                        <x-ui.badge color="gray" size="sm" icon="icon.archive">{{ __('Archived') }}</x-ui.badge>
                    @endif
                </div>

                <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1">
                    @if ($project->status)
                        <x-ui.badge :color="$project->status->category->color()" size="sm" dot>
                            {{ $project->status->name }}
                        </x-ui.badge>
                    @endif

                    <x-ui.badge :color="$shownHealth->color()" size="sm" dot>
                        {{ $shownHealth->label() }}
                    </x-ui.badge>

                    @if ($project->health_set_manually)
                        <x-ui.tooltip :label="__('Health was set by hand, so it is not recalculated automatically.')">
                            <span class="text-2xs text-[var(--text-subtle)]">{{ __('set by hand') }}</span>
                        </x-ui.tooltip>
                    @endif

                    @if ($project->target_date)
                        <span class="inline-flex items-center gap-1 text-xs {{ $project->is_overdue ? 'text-critical-600' : 'text-[var(--text-muted)]' }}">
                            <x-icon.calendar class="size-3.5" />
                            @if ($project->is_overdue)
                                {{ __('Overdue since :date', ['date' => $project->target_date->isoFormat('D MMM')]) }}
                            @else
                                {{ __('Due :date', ['date' => $project->target_date->isoFormat('D MMM YYYY')]) }}
                            @endif
                        </span>
                    @endif
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-1">
                {{ $actions ?? '' }}

                @if (! is_null($favourite))
                    <x-ui.tooltip :label="$favourite ? __('Remove from favourites') : __('Add to favourites')">
                        <x-ui.button variant="ghost" size="md" icon-only
                                     wire:click="toggleFavourite"
                                     :aria-label="$favourite ? __('Remove from favourites') : __('Add to favourites')"
                                     :aria-pressed="$favourite ? 'true' : 'false'"
                                     class="{{ $favourite ? 'text-caution-500 hover:text-caution-600' : '' }}">
                        <x-icon.star class="size-4" :fill="$favourite ? 'currentColor' : 'none'" />
                        </x-ui.button>
                    </x-ui.tooltip>
                @endif

                <x-ui.dropdown align="end" width="w-60">
                    <x-slot:trigger>
                        <x-ui.button variant="ghost" size="md" icon-only :aria-label="__('Project actions')">
                            <x-icon.dots class="size-4" />
                        </x-ui.button>
                    </x-slot:trigger>

                    @can('task.create', $project)
                        <x-ui.dropdown-item icon="icon.plus"
                                            x-on:click="$dispatch('open-quick-create', { type: 'task', project: {{ $project->getKey() }} })">
                            {{ __('New task') }}
                        </x-ui.dropdown-item>
                    @endcan

                    <div x-data="copyable('{{ route('app.projects.show', [$workspace, $project]) }}')" class="contents">
                        <x-ui.dropdown-item icon="icon.paperclip" x-on:click.stop="copy()">
                            <span x-show="!copied">{{ __('Copy link') }}</span>
                            <span x-show="copied" x-cloak class="text-positive-600">{{ __('Link copied') }}</span>
                        </x-ui.dropdown-item>
                    </div>

                    @can('useAi', $project)
                        <x-ui.dropdown-item :href="route('app.projects.ai', [$workspace, $project])" icon="icon.sparkles">
                            {{ __('Ask AI about this project') }}
                        </x-ui.dropdown-item>
                    @endcan

                    @can('update', $project)
                        <x-ui.dropdown-separator />
                        <x-ui.dropdown-item :href="route('app.projects.settings', [$workspace, $project])" icon="icon.cog">
                            {{ __('Project settings') }}
                        </x-ui.dropdown-item>
                    @endcan

                    @can('archive', $project)
                        <x-ui.dropdown-item icon="icon.archive"
                                            :href="route('app.projects.settings', [$workspace, $project]).'?section=danger'">
                            {{ $project->is_archived ? __('Restore project…') : __('Archive project…') }}
                        </x-ui.dropdown-item>
                    @endcan

                    @can('delete', $project)
                        <x-ui.dropdown-item icon="icon.trash" danger
                                            :href="route('app.projects.settings', [$workspace, $project]).'?section=danger'">
                            {{ __('Delete project…') }}
                        </x-ui.dropdown-item>
                    @endcan
                </x-ui.dropdown>
            </div>
        </div>

        {{ $stats ?? '' }}

        {{-- ---------------------------------------------------------------- --}}
        {{-- Tabs                                                             --}}
        {{-- ---------------------------------------------------------------- --}}
        <x-ui.tabs class="mt-3" aria-label="{{ __('Project sections') }}">
            @foreach ($tabs as $tab)
                {{-- `aria-current` is passed as a value rather than wrapped in an @if:
                     Blade directives are not parsed inside a component tag's attribute list,
                     and a null attribute is dropped by the attribute bag. --}}
                <x-ui.tab :href="route($tab['route'], [$workspace, $project])"
                          :icon="$tab['icon']"
                          :active="$current === $tab['key']"
                          :count="$counts[$tab['key']] ?? null"
                          :aria-current="$current === $tab['key'] ? 'page' : null">
                    {{ $tab['label'] }}
                </x-ui.tab>
            @endforeach

            @can('useAi', $project)
                <x-ui.tab :href="route('app.projects.ai', [$workspace, $project])"
                          icon="icon.sparkles"
                          :active="$current === 'ai'"
                          :aria-current="$current === 'ai' ? 'page' : null">
                    {{ __('AI') }}
                </x-ui.tab>
            @endcan
        </x-ui.tabs>
    </div>
</div>

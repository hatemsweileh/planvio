{{--
    The project chrome above the task surfaces: where you are, and the other ways to look at
    the same work. Shared by the list and the board so switching between them changes only
    the body.
--}}
<div class="shrink-0 border-b border-[var(--line-subtle)] bg-[var(--surface-panel)]">
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 pt-3 sm:px-5">
        <nav class="flex min-w-0 items-center gap-1.5 text-xs text-[var(--text-muted)]"
             aria-label="{{ __('Breadcrumb') }}">
            <a href="{{ route('app.projects.index', $workspace) }}"
               class="hidden transition-colors hover:text-[var(--text-DEFAULT)] sm:inline">{{ __('Projects') }}</a>
            <span class="hidden sm:inline" aria-hidden="true">/</span>
            <a href="{{ route('app.projects.show', [$workspace, $project]) }}"
               class="inline-flex min-w-0 items-center gap-1.5 transition-colors hover:text-[var(--text-DEFAULT)]">
                <span class="size-2 shrink-0 rounded-full" style="background-color: {{ $project->color }}"
                      aria-hidden="true"></span>
                <span dir="auto" class="truncate font-medium text-[var(--text-strong)]">{{ $project->name }}</span>
            </a>
            <span class="rounded bg-[var(--surface-sunken)] px-1.5 py-0.5 font-mono text-2xs
                         tracking-tight text-[var(--text-subtle)]">{{ $project->key }}</span>
        </nav>

        <div class="ms-auto flex items-center gap-1.5">
            {{ $actions ?? '' }}

            {{--
                The occasional destinations, behind one control rather than in the tab bar.
                Importing a spreadsheet, setting up a recurrence, printing a status report
                and pulling the data back out are all things somebody does a handful of
                times in a project's life; a tab for each would push the eight views people
                use every day off the edge of a laptop.

                The permissions are resolved before the trigger is drawn, not inside it: a
                menu button that opens onto nothing is worse than no button.
            --}}
            @php
                $mayImport = auth()->user()?->can('create', [App\Models\Task::class, $project]) ?? false;
                $mayRepeat = auth()->user()?->can('viewAny', [App\Models\RecurringTask::class, $project]) ?? false;
                $mayReport = auth()->user()?->can('reports.view', $project) ?? false;
            @endphp

            @if ($mayImport || $mayRepeat || $mayReport)
                <x-ui.dropdown align="end" width="w-56">
                    <x-slot:trigger>
                        <x-ui.button variant="ghost" size="md" icon-only icon="icon.dots"
                                     :aria-label="__('More project actions')" />
                    </x-slot:trigger>

                    @if ($mayImport)
                        <x-ui.dropdown-item icon="icon.plus"
                                            :href="route('app.projects.import', [$workspace, $project])">
                            {{ __('Import tasks from CSV') }}
                        </x-ui.dropdown-item>
                    @endif

                    @if ($mayRepeat)
                        <x-ui.dropdown-item icon="icon.clock"
                                            :href="route('app.projects.recurring', [$workspace, $project])">
                            {{ __('Recurring tasks') }}
                        </x-ui.dropdown-item>
                    @endif

                    @if ($mayReport)
                        @if ($mayImport || $mayRepeat)
                            <x-ui.dropdown-separator />
                        @endif

                        <x-ui.dropdown-item icon="icon.chart"
                                            :href="route('app.projects.report', [$workspace, $project])">
                            {{ __('Status report') }}
                        </x-ui.dropdown-item>

                        <x-ui.dropdown-item icon="icon.document"
                                            :href="route('app.export', $workspace).'?'.http_build_query(['project' => [$project->getKey()]])">
                            {{ __('Export to CSV') }}
                        </x-ui.dropdown-item>
                    @endif
                </x-ui.dropdown>
            @endif

            @can('task.create', $project)
                <x-ui.button variant="primary" size="md" icon="icon.plus"
                             x-on:click="$dispatch('open-quick-create', { type: 'task', project: {{ $project->getKey() }} })">
                    <span class="hidden sm:inline">{{ __('New task') }}</span>
                    <span class="sm:hidden">{{ __('New') }}</span>
                </x-ui.button>
            @endcan
        </div>
    </div>

    <x-ui.tabs class="mt-2 px-4 sm:px-5">
        <x-ui.tab :href="route('app.projects.show', [$workspace, $project])"
                  :active="request()->routeIs('app.projects.show')" icon="icon.home">{{ __('Overview') }}</x-ui.tab>
        <x-ui.tab :href="route('app.projects.tasks', [$workspace, $project])"
                  :active="request()->routeIs('app.projects.tasks')" icon="icon.list">{{ __('Tasks') }}</x-ui.tab>
        <x-ui.tab :href="route('app.projects.board', [$workspace, $project])"
                  :active="request()->routeIs('app.projects.board')" icon="icon.board">{{ __('Board') }}</x-ui.tab>
        <x-ui.tab :href="route('app.projects.calendar', [$workspace, $project])"
                  :active="request()->routeIs('app.projects.calendar')" icon="icon.calendar">{{ __('Calendar') }}</x-ui.tab>
        <x-ui.tab :href="route('app.projects.timeline', [$workspace, $project])"
                  :active="request()->routeIs('app.projects.timeline')" icon="icon.timeline">{{ __('Timeline') }}</x-ui.tab>
        <x-ui.tab :href="route('app.projects.files', [$workspace, $project])"
                  :active="request()->routeIs('app.projects.files')" icon="icon.paperclip">{{ __('Files') }}</x-ui.tab>
        <x-ui.tab :href="route('app.projects.wiki', [$workspace, $project])"
                  :active="request()->routeIs('app.projects.wiki*')" icon="icon.document">{{ __('Wiki') }}</x-ui.tab>
        <x-ui.tab :href="route('app.projects.activity', [$workspace, $project])"
                  :active="request()->routeIs('app.projects.activity')" icon="icon.clock">{{ __('Activity') }}</x-ui.tab>
    </x-ui.tabs>
</div>

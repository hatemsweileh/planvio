<div class="flex h-full min-h-0 flex-col lg:flex-row" x-data="{ railOpen: false }">

    @include('livewire.app.projects._wiki-rail', [
        'workspace' => $workspace,
        'project' => $project,
        'activeId' => null,
    ])

    <div class="scrollbar-thin min-h-0 flex-1 overflow-y-auto">

        {{-- Mobile: the rail is a disclosure, so the reading pane keeps the whole screen. --}}
        <div class="sticky top-0 z-10 flex h-11 items-center gap-2 border-b border-[var(--line-subtle)]
                    bg-[var(--surface-panel)] px-3 lg:hidden">
            <x-ui.button variant="ghost" size="sm" icon="icon.list" x-on:click="railOpen = !railOpen">
                <span x-text="railOpen ? @js(__('Hide documents')) : @js(__('Browse documents'))"></span>
            </x-ui.button>
        </div>

        <div class="page page-prose py-6 sm:py-8">

            <header class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-xs text-[var(--text-muted)]">
                        <a href="{{ route('app.projects.show', [$workspace, $project]) }}" wire:navigate
                           class="hover:text-[var(--text-DEFAULT)]">{{ $project->name }}</a>
                    </p>
                    <h1 class="mt-0.5 text-lg font-semibold tracking-tight text-[var(--text-strong)]">
                        {{ __('Wiki') }}
                    </h1>
                    <p class="mt-1 max-w-prose text-sm leading-relaxed text-[var(--text-muted)]">
                        {{ __('Briefs, decisions, runbooks — everything about :project that is not a task.', ['project' => $project->name]) }}
                    </p>
                </div>

                @can('create', [\App\Models\WikiPage::class, $project])
                    <x-ui.button variant="primary" icon="icon.plus" wire:click="startCreate">
                        {{ __('New page') }}
                    </x-ui.button>
                @endcan
            </header>

            @if ($this->pageCount === 0)
                <x-ui.card class="mt-6" flush>
                    <x-ui.empty-state icon="icon.document"
                                      :title="__('Nothing written down yet')"
                                      :description="__('A wiki is where a project keeps the things a task list cannot hold: the brief, the decisions, the way things are done here.')">
                        <x-slot:actions>
                            @can('create', [\App\Models\WikiPage::class, $project])
                                <x-ui.button variant="primary" icon="icon.plus" wire:click="startCreate">
                                    {{ __('Write the first page') }}
                                </x-ui.button>
                            @else
                                <x-ui.button variant="secondary" icon="icon.folder"
                                             :href="route('app.projects.show', [$workspace, $project])" wire:navigate>
                                    {{ __('Back to the project') }}
                                </x-ui.button>
                            @endcan
                        </x-slot:actions>
                    </x-ui.empty-state>
                </x-ui.card>
            @else
                <section class="mt-6" aria-labelledby="wiki-recent">
                    <h2 id="wiki-recent"
                        class="mb-2 text-xs font-semibold uppercase tracking-wide text-[var(--text-subtle)]">
                        {{ __('Recently edited') }}
                    </h2>

                    <x-ui.card flush>
                        <ul class="divide-y divide-[var(--line-subtle)]">
                            @foreach ($this->recent as $page)
                                <li wire:key="recent-{{ $page->getKey() }}">
                                    <a href="{{ route('app.projects.wiki.show', [$workspace, $project, $page]) }}"
                                       wire:navigate
                                       class="flex items-start gap-3 px-4 py-3 transition-colors hover:bg-[var(--surface-hover)]">
                                        <span class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-md
                                                     bg-[var(--surface-sunken)] text-[var(--text-subtle)]">
                                            <x-icon.document class="size-4" />
                                        </span>

                                        <span class="min-w-0 flex-1">
                                            <span class="flex items-center gap-1.5">
                                                <span dir="auto" class="truncate text-sm font-medium text-[var(--text-strong)]">
                                                    {{ $page->title }}
                                                </span>
                                                @if ($page->ai_generated)
                                                    <x-ui.badge color="purple" size="sm" icon="icon.sparkles">
                                                        {{ __('AI') }}
                                                    </x-ui.badge>
                                                @endif
                                                @if ($page->visibility !== \App\Enums\WikiVisibility::Project)
                                                    <x-ui.badge :color="$page->visibility->color()" size="sm">
                                                        {{ $page->visibility->label() }}
                                                    </x-ui.badge>
                                                @endif
                                            </span>

                                            @if (filled($page->excerpt))
                                                <span dir="auto" class="mt-0.5 line-clamp-2 block text-xs leading-relaxed text-[var(--text-muted)]">
                                                    {{ $page->excerpt }}
                                                </span>
                                            @endif

                                            <span class="mt-1 flex items-center gap-1.5 text-2xs text-[var(--text-subtle)]">
                                                <span>{{ __('Edited by :name', [
                                                    'name' => $page->editor?->name ?? $page->author?->name ?? __('Planvio'),
                                                ]) }}</span>
                                                <span aria-hidden="true">&middot;</span>
                                                <span x-data="relativeTime('{{ $page->updated_at?->toIso8601String() }}')"
                                                      x-text="label"></span>
                                            </span>
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </x-ui.card>
                </section>
            @endif
        </div>
    </div>
</div>

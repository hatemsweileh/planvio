{{--
    One level of the document rail, drawn recursively.

    Each level is its own `sortableList`, so a drag reorders siblings. The list reports the
    ids beneath it in document order — descendants included, because children are drawn
    inside their parent — and the component regroups them by their real parent before
    writing anything. Re-parenting is the explicit "Move under" choice on the row: it is the
    version of the gesture a keyboard can perform, and the only one the shared sortable can
    report unambiguously.

    Expects: $nodes, $tree, $depth, $activeId, $canManage, $workspace, $project, $parentOptions
--}}
<ul x-data="sortableList('reorderWikiPages')" class="space-y-px">
    @foreach ($nodes as $node)
        @php
            $children = $tree->get((string) $node->getKey(), collect());
            $isActive = $activeId !== null && (int) $activeId === (int) $node->getKey();
        @endphp

        <li data-sort-id="{{ $node->getKey() }}" wire:key="wiki-node-{{ $node->getKey() }}" x-data="{ expanded: true }">
            <div class="group/row flex items-center gap-0.5 rounded-md pe-1 transition-colors
                        {{ $isActive ? 'bg-[var(--accent-soft)]' : 'hover:bg-[var(--surface-hover)]' }}"
                 style="padding-inline-start: {{ $depth * 0.75 }}rem">

                @if ($canManage)
                    <span data-drag-handle
                          class="hidden size-5 shrink-0 cursor-grab place-items-center rounded text-[var(--text-subtle)]
                                 opacity-0 transition-opacity group-hover/row:opacity-100 sm:grid"
                          aria-hidden="true" title="{{ __('Drag to reorder') }}">
                        <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor">
                            <circle cx="8" cy="5" r="1.2"/><circle cx="12" cy="5" r="1.2"/>
                            <circle cx="8" cy="10" r="1.2"/><circle cx="12" cy="10" r="1.2"/>
                            <circle cx="8" cy="15" r="1.2"/><circle cx="12" cy="15" r="1.2"/>
                        </svg>
                    </span>
                @endif

                @if ($children->isNotEmpty())
                    <button type="button" x-on:click="expanded = !expanded"
                            class="flip-rtl grid size-5 shrink-0 place-items-center rounded text-[var(--text-subtle)]
                                   transition-colors hover:text-[var(--text-DEFAULT)]"
                            :aria-expanded="expanded"
                            aria-label="{{ __('Toggle :title', ['title' => $node->title]) }}">
                        <x-icon.chevron-right class="size-3.5 transition-transform duration-150"
                                              x-bind:class="expanded && 'rotate-90'" />
                    </button>
                @else
                    <span class="size-5 shrink-0" aria-hidden="true"></span>
                @endif

                <a href="{{ route('app.projects.wiki.show', [$workspace, $project, $node]) }}"
                   wire:navigate
                   @if ($isActive) aria-current="page" @endif
                   class="flex min-w-0 flex-1 items-center gap-1.5 py-1 text-sm transition-colors
                          {{ $isActive
                              ? 'font-medium text-[var(--accent-soft-text)]'
                              : 'text-[var(--text-DEFAULT)]' }}">
                    <span dir="auto" class="truncate">{{ $node->title }}</span>

                    @if ($node->ai_generated)
                        <x-ui.tooltip :label="__('Drafted by Planvio AI')">
                            <x-icon.sparkles class="size-3 shrink-0 text-accent-500" />
                            <span class="sr-only">{{ __('Drafted by Planvio AI') }}</span>
                        </x-ui.tooltip>
                    @endif

                    @if ($node->visibility === \App\Enums\WikiVisibility::Private)
                        <span class="shrink-0 text-2xs text-[var(--text-subtle)]">{{ __('Private') }}</span>
                    @endif
                </a>

                @if ($canManage)
                    <x-ui.dropdown align="end" width="w-60"
                                   class="opacity-0 transition-opacity focus-within:opacity-100 group-hover/row:opacity-100">
                        <x-slot:trigger>
                            <button type="button"
                                    class="grid size-6 place-items-center rounded text-[var(--text-subtle)]
                                           transition-colors hover:bg-[var(--surface-active)]
                                           hover:text-[var(--text-DEFAULT)]"
                                    aria-label="{{ __('Actions for :title', ['title' => $node->title]) }}">
                                <x-icon.dots class="size-4" />
                            </button>
                        </x-slot:trigger>

                        <x-ui.dropdown-item icon="icon.plus"
                                            wire:click="startCreate({{ $node->getKey() }})">
                            {{ __('New subpage') }}
                        </x-ui.dropdown-item>

                        <x-ui.dropdown-separator />

                        <div class="px-2 pb-1.5 pt-1" x-on:click.stop>
                            <label for="move-{{ $node->getKey() }}"
                                   class="mb-1 block text-2xs font-medium uppercase tracking-wide text-[var(--text-subtle)]">
                                {{ __('Move under') }}
                            </label>
                            <x-ui.select id="move-{{ $node->getKey() }}" size="sm"
                                         x-on:change="$wire.movePage({{ $node->getKey() }}, $event.target.value ? Number($event.target.value) : null); open = false">
                                <option value="" @selected($node->parent_id === null)>{{ __('Top level') }}</option>
                                @foreach ($parentOptions as $optionId => $optionLabel)
                                    @continue((int) $optionId === (int) $node->getKey())
                                    <option value="{{ $optionId }}" @selected((int) $node->parent_id === (int) $optionId)>
                                        {{ $optionLabel }}
                                    </option>
                                @endforeach
                            </x-ui.select>
                        </div>

                        <x-ui.dropdown-separator />

                        <x-ui.dropdown-item icon="icon.trash" danger
                                            wire:click="deletePage({{ $node->getKey() }})"
                                            wire:confirm="{{ __('Delete “:title”? Its subpages move up a level.', ['title' => $node->title]) }}">
                            {{ __('Delete page') }}
                        </x-ui.dropdown-item>
                    </x-ui.dropdown>
                @endif
            </div>

            @if ($children->isNotEmpty())
                <div x-show="expanded" x-collapse.duration.150ms>
                    @include('livewire.app.projects._wiki-tree', [
                        'nodes' => $children,
                        'tree' => $tree,
                        'depth' => $depth + 1,
                        'activeId' => $activeId,
                        'canManage' => $canManage,
                        'workspace' => $workspace,
                        'project' => $project,
                        'parentOptions' => $parentOptions,
                    ])
                </div>
            @endif
        </li>
    @endforeach
</ul>

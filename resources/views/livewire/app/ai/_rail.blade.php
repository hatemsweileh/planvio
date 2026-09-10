{{--
    The conversation rail.

    Threads are grouped by when they were last used rather than paginated, because that is
    how people look for one: "the thing I asked about on Tuesday". A thread scoped to a
    project carries that project's colour, so the rail also answers "which of these was about
    the launch" without opening any of them.
--}}
<div class="flex h-full min-h-0 flex-col bg-[var(--surface-sunken)]">

    <div class="shrink-0 space-y-2 border-b border-[var(--line-subtle)] p-3">
        <x-ui.button variant="secondary" size="md" icon="icon.plus"
                     wire:click="startNewConversation"
                     class="w-full justify-start">
            {{ __('New conversation') }}
        </x-ui.button>

        <x-ui.input icon="icon.search"
                    size="sm"
                    type="search"
                    wire:model.live.debounce.300ms="search" busy-target="search"
                    :placeholder="__('Search conversations')"
                    :aria-label="__('Search conversations')" />
    </div>

    <nav class="scrollbar-thin min-h-0 flex-1 overflow-y-auto px-2 py-2" aria-label="{{ __('Conversations') }}">
        @forelse ($this->conversationGroups as $group)
            <div class="mb-3 last:mb-0">
                <p class="px-2 pb-1 text-2xs font-semibold uppercase tracking-wider text-[var(--text-subtle)]">
                    {{ $group['label'] }}
                </p>

                <ul class="space-y-0.5">
                    @foreach ($group['conversations'] as $thread)
                        @php $active = $this->conversationId === (int) $thread->getKey(); @endphp
                        <li wire:key="thread-{{ $thread->getKey() }}">
                            <button type="button"
                                    wire:click="openConversation({{ $thread->getKey() }})"
                                    aria-current="{{ $active ? 'true' : 'false' }}"
                                    class="group flex w-full items-start gap-2 rounded-md px-2 py-1.5 text-start
                                           transition-colors
                                           {{ $active
                                               ? 'bg-[var(--surface-panel)] shadow-xs'
                                               : 'hover:bg-[var(--surface-hover)]' }}">
                                @if ($thread->project)
                                    <span class="mt-1.5 size-1.5 shrink-0 rounded-full"
                                          style="background-color: {{ $thread->project->color }}"
                                          aria-hidden="true"></span>
                                @else
                                    <span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-[var(--line-strong)]"
                                          aria-hidden="true"></span>
                                @endif

                                <span class="min-w-0 flex-1">
                                    <span dir="auto" class="block truncate text-xs font-medium
                                                 {{ $active ? 'text-[var(--text-strong)]' : 'text-[var(--text-DEFAULT)]' }}">
                                        {{ $thread->title ?: __('Untitled conversation') }}
                                    </span>
                                    <span class="mt-0.5 flex items-center gap-1.5 text-2xs text-[var(--text-subtle)]">
                                        @if ($thread->project)
                                            <span dir="auto" class="truncate">{{ $thread->project->name }}</span>
                                            <span aria-hidden="true">&middot;</span>
                                        @endif
                                        <span x-data="relativeTime('{{ ($thread->last_activity_at ?? $thread->created_at)?->toIso8601String() }}')"
                                              x-text="label"></span>
                                    </span>
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @empty
            <x-ui.empty-state compact
                              icon="icon.chat"
                              :title="filled($this->search) ? __('Nothing matches') : __('No conversations yet')"
                              :description="filled($this->search)
                                  ? __('No thread of yours has that in its title.')
                                  : __('Whatever you ask first becomes the name of the thread.')" />
        @endforelse
    </nav>
</div>

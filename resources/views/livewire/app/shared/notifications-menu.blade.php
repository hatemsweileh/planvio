{{--
    The notifications panel is anchored like every other overlay in the product, so it is
    placed against the window rather than against its own corner of the top bar: it can no
    longer be clipped by the bar, and a long list is capped and scrolls itself instead of
    running off the bottom of the screen. The hand-rolled `w-[min(22rem,100vw-1.5rem)]`
    that used to keep it on screen horizontally is no longer needed for that — a plain
    width is enough now that the positioner owns the edges.
--}}
<div x-data="anchored({ placement: 'bottom', align: 'end' })"
     x-on:keydown.escape.window="hide()"
     x-on:close-dropdowns.window="hide()"
     x-on:click.outside="onOutside($event)"
     class="relative">

    <button type="button"
            x-ref="trigger"
            x-on:click="toggle(); if (open && ! $wire.loaded) $wire.load()"
            :aria-expanded="open"
            aria-haspopup="menu"
            class="relative grid size-8 place-items-center rounded-md text-[var(--text-muted)]
                   transition-colors hover:bg-[var(--surface-hover)] hover:text-[var(--text-DEFAULT)]"
            aria-label="{{ trans_choice(
                '{0}Notifications|{1}Notifications, :count unread|[2,*]Notifications, :count unread',
                $unread,
                ['count' => $unread],
            ) }}">
        <x-icon.bell class="size-4" />

        @if ($unread > 0)
            <span class="absolute -end-0.5 -top-0.5 grid min-w-4 place-items-center rounded-full
                         bg-[var(--accent)] px-1 text-[10px] font-semibold leading-4 tabular-nums text-white
                         ring-2 ring-[var(--surface-panel)]">
                {{ $unread > 99 ? '99+' : $unread }}
            </span>
        @endif
    </button>

    <div x-ref="panel" popover="manual" role="menu" class="pv-overlay w-88">
        {{-- No `overflow-hidden` here: `.pv-overlay-surface` already sets `overflow-y: auto`,
             which clips to the border radius just as well and, unlike `hidden`, lets the
             panel scroll if the positioner ever has to cap its height. A Tailwind utility
             would win the cascade and turn that cap back into a clip. --}}
        <div class="pv-overlay-surface scrollbar-thin rounded-lg border
                    border-[var(--line-subtle)] bg-[var(--surface-raised)] shadow-overlay">

        <div class="flex h-10 items-center justify-between gap-2 border-b border-[var(--line-subtle)] px-3">
            <p class="text-xs font-semibold text-[var(--text-strong)]">{{ __('Notifications') }}</p>

            @if ($unread > 0)
                <button type="button" wire:click="markAllRead" wire:target="markAllRead"
                        class="text-xs text-[var(--accent)] transition-opacity hover:underline
                               disabled:opacity-50"
                        wire:loading.attr="disabled">
                    {{ __('Mark all read') }}
                </button>
            @endif
        </div>

        <div class="scrollbar-thin max-h-[min(26rem,60vh)] overflow-y-auto">

            {{--
                First open costs one round trip. A skeleton of the shape that is coming keeps
                the panel from jumping, and never claims "all caught up" before it knows.
            --}}
            @unless ($loaded)
                <div class="space-y-3 p-3" aria-hidden="true">
                    @for ($skeleton = 0; $skeleton < 3; $skeleton++)
                        <div class="flex animate-pulse gap-2.5">
                            <span class="size-6 shrink-0 rounded-md bg-[var(--surface-hover)]"></span>
                            <div class="min-w-0 flex-1 space-y-1.5">
                                <span class="block h-3 w-2/3 rounded bg-[var(--surface-hover)]"></span>
                                <span class="block h-2.5 w-full rounded bg-[var(--surface-hover)]"></span>
                            </div>
                        </div>
                    @endfor
                </div>
                <span class="sr-only" role="status">{{ __('Loading notifications…') }}</span>
            @else
                @forelse ($this->grouped as $bucket => $rows)
                    <p class="sticky top-0 z-10 bg-[var(--surface-raised)] px-3 pb-1 pt-2.5 text-2xs
                              font-semibold uppercase tracking-wider text-[var(--text-subtle)]">
                        {{ $bucket }}
                    </p>

                    @foreach ($rows as $notification)
                        @php
                            $data = $notification->data;
                            $isAi = (bool) $notification->is_ai;
                            $isUnread = $notification->read_at === null;
                            $url = is_string($data['url'] ?? null) && $data['url'] !== '' ? $data['url'] : null;
                        @endphp

                        <div wire:key="notification-{{ $notification->getKey() }}"
                             class="group relative flex gap-2.5 px-3 py-2.5 transition-colors
                                    hover:bg-[var(--surface-hover)] {{ $isUnread ? 'bg-[var(--accent-soft)]' : '' }}">

                            <span class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-md
                                         {{ $isAi
                                             ? 'bg-accent-100 text-accent-600 dark:bg-accent-500/20 dark:text-accent-100'
                                             : 'bg-[var(--surface-sunken)] text-[var(--text-subtle)]' }}">
                                <x-dynamic-component :component="$this->iconFor($notification->category)" class="size-3.5" />
                            </span>

                            <div class="min-w-0 flex-1">
                                @if ($url)
                                    {{--
                                        Marking read and navigating are one gesture, so the write is
                                        awaited rather than raced against the page unload. The href
                                        stays real: middle-click, "copy link" and a screen reader all
                                        need it.
                                    --}}
                                    <a href="{{ $url }}"
                                       @if ($isUnread)
                                           x-on:click.prevent="$wire.markRead('{{ $notification->getKey() }}')
                                               .finally(() => window.location.assign(@js($url)))"
                                       @endif
                                       class="block text-sm font-medium text-[var(--text-strong)] hover:underline">
                                        {{ $data['title'] ?? __('Notification') }}
                                        {{-- Stretches the link across the row without nesting interactive elements. --}}
                                        <span class="absolute inset-0" aria-hidden="true"></span>
                                    </a>
                                @else
                                    <p class="text-sm font-medium text-[var(--text-strong)]">
                                        {{ $data['title'] ?? __('Notification') }}
                                    </p>
                                @endif

                                @if (filled($data['body'] ?? null))
                                    <p class="mt-0.5 line-clamp-2 text-xs leading-relaxed text-[var(--text-muted)]">
                                        {{ $data['body'] }}
                                    </p>
                                @endif

                                <p class="mt-1 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-2xs text-[var(--text-subtle)]">
                                    @if ($isAi)
                                        <x-ui.badge color="purple" size="sm" icon="icon.sparkles">{{ __('AI') }}</x-ui.badge>
                                    @elseif (filled($data['actor_name'] ?? null))
                                        <span dir="auto" class="truncate">{{ $data['actor_name'] }}</span>
                                        <span aria-hidden="true">&middot;</span>
                                    @endif
                                    <span x-data="relativeTime('{{ $notification->created_at?->toIso8601String() }}')"
                                          x-text="label"></span>
                                </p>
                            </div>

                            @if ($isUnread)
                                <button type="button"
                                        wire:click="markRead('{{ $notification->getKey() }}')"
                                        class="relative z-10 mt-1 grid size-5 shrink-0 place-items-center rounded
                                               text-[var(--text-subtle)] opacity-0 transition
                                               hover:bg-[var(--surface-active)] hover:text-[var(--text-DEFAULT)]
                                               focus-visible:opacity-100 group-hover:opacity-100"
                                        aria-label="{{ __('Mark as read') }}">
                                    <svg class="size-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="m4 10.5 4 4 8-9"/>
                                    </svg>
                                </button>
                            @endif
                        </div>
                    @endforeach
                @empty
                    <x-ui.empty-state icon="icon.bell"
                                      :title="__('You are all caught up')"
                                      :description="__('Mentions, assignments and updates you follow will show up here.')"
                                      compact />
                @endforelse
            @endunless
        </div>

        @if ($workspace ?? null)
            <a href="{{ route('app.inbox', $workspace) }}"
               class="flex h-9 items-center justify-center border-t border-[var(--line-subtle)]
                      bg-[var(--surface-sunken)] text-xs font-medium text-[var(--text-muted)]
                      transition-colors hover:text-[var(--text-DEFAULT)]">
                {{ __('Open the inbox') }}
            </a>
        @endif
        </div>
    </div>
</div>

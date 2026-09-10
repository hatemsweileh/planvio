@php
    $rows = $this->notifications;
    $empty = $this->emptyCopy();
    $bucket = null;
@endphp

<div class="page py-5 lg:py-6">

    {{-- ---------------------------------------------------------------- --}}
    {{-- Header                                                           --}}
    {{-- ---------------------------------------------------------------- --}}
    <header class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div class="min-w-0">
            <h2 class="truncate text-base font-semibold tracking-tight text-[var(--text-strong)]">
                {{ __('Inbox') }}
            </h2>
            <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                {{ $this->unreadTotal() > 0
                    ? trans_choice('{1}:count unread|[2,*]:count unread', $this->unreadTotal(), ['count' => $this->unreadTotal()])
                    : __('Everything here has been read') }}
            </p>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            <x-ui.tabs variant="pill">
                <x-ui.tab variant="pill" :active="! $unreadOnly" wire:click="$set('unreadOnly', false)">
                    {{ __('All') }}
                </x-ui.tab>
                <x-ui.tab variant="pill" :active="$unreadOnly" wire:click="$set('unreadOnly', true)">
                    {{ __('Unread') }}
                </x-ui.tab>
            </x-ui.tabs>

            <x-ui.button size="md" icon="icon.check-circle"
                         wire:click="markAllRead"
                         wire:target="markAllRead"
                         :disabled="$this->unreadTotal() === 0">
                {{ __('Mark all read') }}
            </x-ui.button>
        </div>
    </header>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Filters                                                          --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.tabs class="mt-4">
        @foreach ($this->tabs as $tab)
            <x-ui.tab :active="$filter === $tab['key']"
                      :icon="$tab['icon']"
                      :count="$tab['count'] > 0 ? $tab['count'] : null"
                      wire:key="tab-{{ $tab['key'] }}"
                      wire:click="selectFilter('{{ $tab['key'] }}')">
                {{ $tab['label'] }}
            </x-ui.tab>
        @endforeach
    </x-ui.tabs>

    {{-- ---------------------------------------------------------------- --}}
    {{-- The list                                                         --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="relative mt-4 overflow-hidden rounded-lg border border-[var(--line-subtle)]
                bg-[var(--surface-panel)] shadow-panel">

        {{-- A switch of filter is a round trip; the list dims rather than disappears. --}}
        <div wire:loading.delay.class="opacity-40" wire:target="selectFilter,resetFilters,markAllRead,unreadOnly,gotoPage,nextPage,previousPage"
             class="transition-opacity duration-150">

            @if ($rows->isEmpty())
                <x-ui.empty-state icon="icon.inbox" :title="$empty['title']" :description="$empty['description']">
                    <x-slot:actions>
                        @if ($filter !== 'all' || $unreadOnly)
                            <x-ui.button size="md" icon="icon.inbox" wire:click="resetFilters">
                                {{ __('Show everything') }}
                            </x-ui.button>
                        @else
                            <x-ui.button size="md" icon="icon.check-circle" :href="route('app.my-tasks', $workspace)">
                                {{ __('Go to my tasks') }}
                            </x-ui.button>
                        @endif
                    </x-slot:actions>
                </x-ui.empty-state>
            @else
                <ul>
                    @foreach ($rows as $notification)
                        @php
                            $payload = is_array($notification->data) ? $notification->data : [];
                            $isAi = (bool) $notification->is_ai;
                            $isUnread = $notification->read_at === null;
                            $url = is_string($payload['url'] ?? null) && $payload['url'] !== '' ? $payload['url'] : null;
                            $rowBucket = $this->bucket($notification->created_at);
                        @endphp

                        @if ($rowBucket !== $bucket)
                            @php $bucket = $rowBucket; @endphp
                            <li class="sticky top-0 z-10 border-y border-[var(--line-subtle)]
                                       bg-[var(--surface-sunken)] px-4 py-1.5 text-2xs font-semibold
                                       uppercase tracking-wider text-[var(--text-subtle)] first:border-t-0">
                                {{ $bucket }}
                            </li>
                        @endif

                        <li wire:key="notification-{{ $notification->getKey() }}"
                            class="group relative flex items-start gap-3 border-b border-[var(--line-subtle)]
                                   px-4 py-3 transition-colors last:border-b-0
                                   hover:bg-[var(--surface-hover)]
                                   {{ $isUnread ? 'bg-[var(--accent-soft)]' : '' }}">

                            {{-- Unread is a state, not a decoration: it gets its own column. --}}
                            <span class="mt-2 flex w-1.5 shrink-0 justify-center" aria-hidden="true">
                                @if ($isUnread)
                                    <span class="size-1.5 rounded-full bg-[var(--accent)]"></span>
                                @endif
                            </span>

                            <span class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-md
                                         {{ $isAi
                                             ? 'bg-accent-100 text-accent-600 ring-1 ring-accent-500/30 dark:bg-accent-500/20 dark:text-accent-100 dark:ring-accent-500/30'
                                             : 'bg-[var(--surface-sunken)] text-[var(--text-subtle)]' }}">
                                <x-dynamic-component :component="$this->iconFor($notification->category, $isAi)"
                                                     class="size-4" />
                            </span>

                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-3">
                                    @if ($url)
                                        {{--
                                            Marking read and navigating are one gesture, so the
                                            write is awaited rather than raced against the page
                                            unload. The href stays real: middle-click, "copy
                                            link" and a screen reader all need it.
                                        --}}
                                        <a href="{{ $url }}"
                                           @if ($isUnread)
                                               x-on:click.prevent="$wire.markRead('{{ $notification->getKey() }}')
                                                   .finally(() => window.location.assign(@js($url)))"
                                           @endif
                                           class="min-w-0 text-sm font-medium text-[var(--text-strong)] hover:text-[var(--accent)]">
                                            {{ $payload['title'] ?? __('Notification') }}
                                            <span class="absolute inset-0" aria-hidden="true"></span>
                                        </a>
                                    @else
                                        <p class="min-w-0 text-sm font-medium text-[var(--text-strong)]">
                                            {{ $payload['title'] ?? __('Notification') }}
                                        </p>
                                    @endif

                                    <span class="shrink-0 whitespace-nowrap text-2xs text-[var(--text-subtle)]"
                                          x-data="relativeTime('{{ $notification->created_at?->toIso8601String() }}')"
                                          x-text="label"></span>
                                </div>

                                @if (filled($payload['body'] ?? null))
                                    <p class="mt-0.5 line-clamp-2 text-xs leading-relaxed text-[var(--text-muted)]">
                                        {{ $payload['body'] }}
                                    </p>
                                @endif

                                <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1">
                                    @if ($isAi)
                                        <x-ui.badge color="purple" size="sm" icon="icon.sparkles">{{ __('AI') }}</x-ui.badge>
                                    @endif
                                    <x-ui.badge color="gray" size="sm">
                                        {{ $this->categoryLabel($notification->category) }}
                                    </x-ui.badge>
                                    @if (! $isAi && filled($payload['actor_name'] ?? null))
                                        <span class="truncate text-2xs text-[var(--text-subtle)]">
                                            {{ $payload['actor_name'] }}
                                        </span>
                                    @endif
                                </div>
                            </div>

                            {{--
                                Above the stretched link so the row action stays clickable, and
                                revealed on hover or focus so a quiet list stays quiet.
                            --}}
                            <div class="relative z-10 mt-0.5 flex shrink-0 items-center gap-1 opacity-0
                                        transition-opacity focus-within:opacity-100 group-hover:opacity-100">
                                @if ($isUnread)
                                    <x-ui.tooltip :label="__('Mark as read')">
                                        <x-ui.button variant="ghost" size="sm" icon-only
                                                     wire:click="markRead('{{ $notification->getKey() }}')"
                                                     :aria-label="__('Mark as read')">
                                            <svg class="size-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="m4 10.5 4 4 8-9"/>
                                            </svg>
                                        </x-ui.button>
                                    </x-ui.tooltip>
                                @else
                                    <x-ui.tooltip :label="__('Mark as unread')">
                                        <x-ui.button variant="ghost" size="sm" icon-only
                                                     wire:click="markUnread('{{ $notification->getKey() }}')"
                                                     :aria-label="__('Mark as unread')">
                                            <x-icon.bell class="size-3.5" />
                                        </x-ui.button>
                                    </x-ui.tooltip>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
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

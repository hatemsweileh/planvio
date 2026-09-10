@php
    /**
     * One running index across every group: the keyboard walks a flat list, even though
     * the eye reads grouped sections.
     */
    $groups = $this->groups;
    $index = 0;
    $total = array_sum(array_map(static fn (array $group): int => count($group['rows']), $groups));
@endphp

<div x-data="{
        open: false,
        active: 0,
        rows() {
            return Array.from(this.$refs.list?.querySelectorAll('[data-option]') ?? []);
        },
        clamp() {
            const count = this.rows().length;
            if (count === 0) { this.active = 0; return; }
            if (this.active >= count) this.active = count - 1;
            if (this.active < 0) this.active = 0;
        },
        move(delta) {
            const rows = this.rows();
            if (rows.length === 0) return;
            this.active = (this.active + delta + rows.length) % rows.length;
            rows[this.active]?.scrollIntoView({ block: 'nearest' });
        },
        choose() {
            this.clamp();
            this.rows()[this.active]?.click();
        },
        show() {
            this.open = true;
            this.active = 0;
            this.$nextTick(() => {
                this.$refs.input?.focus();
                this.$refs.input?.select();
            });
        },
        close() {
            this.open = false;
        },
     }"
     x-on:open-command-palette.window="show()"
     x-on:keydown.escape.window="open && close()"
     x-cloak
     class="relative z-[70]">

    <div x-show="open" class="fixed inset-0" role="dialog" aria-modal="true"
         aria-label="{{ __('Search or run a command') }}">

        <div x-show="open"
             x-transition:enter="ease-out duration-120" x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             x-on:click="close()"
             class="absolute inset-0 bg-ink-950/40 backdrop-blur-[2px]" aria-hidden="true"></div>

        <div class="absolute inset-x-0 top-0 flex justify-center px-3 pt-[8vh] sm:px-6">
            <div x-show="open"
                 x-transition:enter="ease-[cubic-bezier(0.32,0.72,0,1)] duration-160"
                 x-transition:enter-start="opacity-0 -translate-y-2 scale-97"
                 x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                 x-transition:leave="ease-in duration-100"
                 x-transition:leave-start="opacity-100 scale-100"
                 x-transition:leave-end="opacity-0 scale-97"
                 x-trap.noscroll="open"
                 x-on:keydown.down.prevent="move(1)"
                 x-on:keydown.up.prevent="move(-1)"
                 x-on:keydown.enter.prevent="choose()"
                 x-on:keydown.tab.prevent="move($event.shiftKey ? -1 : 1)"
                 class="w-full max-w-xl overflow-hidden rounded-xl border border-[var(--line-subtle)]
                        bg-[var(--surface-raised)] shadow-overlay">

                {{-- Query --}}
                <div class="flex h-12 items-center gap-2.5 border-b border-[var(--line-subtle)] px-3.5">
                    <svg class="size-4 shrink-0 text-[var(--text-subtle)]" viewBox="0 0 20 20" fill="none"
                         stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <circle cx="9" cy="9" r="5.5"/><path d="m13 13 4 4" stroke-linecap="round"/>
                    </svg>

                    <input x-ref="input"
                           type="text"
                           wire:model.live.debounce.250ms="query"
                           x-on:input="active = 0"
                           class="h-full min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-[var(--text-strong)]
                                  placeholder:text-[var(--text-subtle)] focus:outline-none focus:ring-0"
                           placeholder="{{ __('Search projects, tasks, people — or type a command') }}"
                           aria-label="{{ __('Search or run a command') }}"
                           role="combobox"
                           aria-expanded="true"
                           aria-controls="command-palette-list"
                           :aria-activedescendant="'command-palette-option-' + active"
                           autocomplete="off"
                           spellcheck="false">

                    <span wire:loading.delay wire:target="query" class="shrink-0">
                        <x-ui.spinner class="size-4 text-[var(--text-subtle)]" />
                    </span>

                    <kbd class="hidden shrink-0 rounded border border-[var(--line-subtle)] bg-[var(--surface-sunken)]
                                px-1.5 text-[10px] font-medium text-[var(--text-subtle)] sm:block">esc</kbd>
                </div>

                {{-- Results --}}
                <div x-ref="list" id="command-palette-list" role="listbox"
                     class="scrollbar-thin max-h-[min(24rem,60vh)] overflow-y-auto p-1.5">

                    {{--
                        Searching is rate limited; the commands below still work. Read off
                        `$this` rather than the extracted property: the component sets it
                        while building `$groups` above, which is after Livewire has already
                        snapshotted its public properties into this view's variables — the
                        local copy would be one render behind.
                    --}}
                    @if ($this->searchPausedFor > 0)
                        <p role="status"
                           class="mx-1 mb-1 mt-1 rounded-md bg-[var(--surface-sunken)] px-2 py-1.5 text-xs
                                  text-[var(--text-muted)]">
                            {{ trans_choice(
                                '{1}Searching again in a second.|[2,*]Searching again in :count seconds.',
                                $this->searchPausedFor,
                            ) }}
                        </p>
                    @endif

                    @forelse ($groups as $group)
                        <p class="px-2 pb-1 pt-2 text-2xs font-semibold uppercase tracking-wider text-[var(--text-subtle)]">
                            {{ $group['label'] }}
                        </p>

                        @foreach ($group['rows'] as $row)
                            @php($i = $index++)

                            <{{ isset($row['href']) ? 'a' : 'button' }}
                                wire:key="palette-{{ $row['key'] }}"
                                id="command-palette-option-{{ $i }}"
                                data-option="{{ $i }}"
                                role="option"
                                :aria-selected="active === {{ $i }} ? 'true' : 'false'"
                                @if (isset($row['href']))
                                    href="{{ $row['href'] }}"
                                    x-on:click="close()"
                                @else
                                    type="button"
                                    x-on:click="close(); $dispatch({{ Js::from($row['event']) }}, {{ Js::from($row['detail'] ?? []) }})"
                                @endif
                                x-on:mousemove="active = {{ $i }}"
                                :class="active === {{ $i }}
                                    ? 'bg-[var(--surface-hover)] text-[var(--text-strong)]'
                                    : 'text-[var(--text-DEFAULT)]'"
                                class="flex w-full items-center gap-2.5 rounded-md px-2 py-1.5 text-start text-sm
                                       transition-colors duration-75">

                                @if (! empty($row['color']))
                                    <span class="size-2 shrink-0 rounded-full"
                                          style="background-color: {{ $row['color'] }}" aria-hidden="true"></span>
                                @else
                                    <x-dynamic-component :component="$row['icon']"
                                                         class="size-4 shrink-0 text-[var(--text-subtle)]" />
                                @endif

                                <span dir="auto" class="min-w-0 flex-1 truncate">{{ $row['label'] }}</span>

                                @if (! empty($row['meta']))
                                    <span class="shrink-0 truncate text-2xs text-[var(--text-subtle)]">
                                        {{ $row['meta'] }}
                                    </span>
                                @endif

                                <span class="shrink-0 text-2xs text-[var(--text-subtle)]"
                                      x-show="active === {{ $i }}" x-cloak aria-hidden="true">&crarr;</span>
                            </{{ isset($row['href']) ? 'a' : 'button' }}>
                        @endforeach
                    @empty
                        {{-- "Nothing matched" is an answer, and it must not be given while
                             the question is still being asked. The markup here describes the
                             previous query until the round trip lands, so the empty state
                             stands down for placeholder rows whenever one is in flight. --}}
                        <div wire:loading.delay.flex wire:target="query" class="hidden px-1 py-2">
                            <x-ui.skeleton :rows="4" class="w-full" />
                        </div>

                        <div wire:loading.delay.remove wire:target="query">
                            <x-ui.empty-state icon="icon.search"
                                              :title="mb_strlen(trim($query)) >= 2 ? __('Nothing matched “:query”', ['query' => $query]) : __('Start typing')"
                                              :description="mb_strlen(trim($query)) >= 2
                                                  ? __('Try a project key, part of a task title, or a person’s name.')
                                                  : __('Search across projects, tasks, milestones, pages and people — or run a command.')"
                                              compact />
                        </div>
                    @endforelse
                </div>

                {{-- Key hints. Quiet, but they are how a palette teaches itself. --}}
                <div class="flex items-center gap-3 border-t border-[var(--line-subtle)] bg-[var(--surface-sunken)]
                            px-3 py-2 text-2xs text-[var(--text-subtle)]">
                    <span class="flex items-center gap-1">
                        <kbd class="rounded border border-[var(--line-subtle)] bg-[var(--surface-panel)] px-1">&uarr;</kbd>
                        <kbd class="rounded border border-[var(--line-subtle)] bg-[var(--surface-panel)] px-1">&darr;</kbd>
                        {{ __('to navigate') }}
                    </span>
                    <span class="flex items-center gap-1">
                        <kbd class="rounded border border-[var(--line-subtle)] bg-[var(--surface-panel)] px-1">&crarr;</kbd>
                        {{ __('to open') }}
                    </span>
                    <span class="ms-auto tabular-nums">
                        {{ trans_choice('{0}No results|{1}:count result|[2,*]:count results', $total, ['count' => $total]) }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

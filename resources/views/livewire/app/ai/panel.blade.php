@php
    $progress = $opened ? $this->progress : null;
@endphp

{{--
    The assistant drawer.

    Opening is pure Alpine so the panel is on screen in the same frame as the keystroke; the
    server is asked once, on open, for whatever context the caller sent with the event. From
    there it is the same machinery as the full AI workspace in a narrower column — the same
    gate, the same queued run, the same tool trace, the same approval card. Nothing happens
    in here that the full screen would not have shown.
--}}
<div x-data="{
        open: false,
        show(detail) {
            this.open = true;
            $wire.begin(
                detail?.projectId ?? null,
                detail?.taskId ?? null,
                typeof detail?.prompt === 'string' ? detail.prompt : '',
                typeof detail?.intent === 'string' ? detail.intent : '',
            );
            this.$nextTick(() => this.$refs.panel?.querySelector('textarea')?.focus());
        },
     }"
     x-on:open-ai-panel.window="show($event.detail)"
     x-on:keydown.escape.window="open && (open = false)"
     x-cloak
     class="relative z-[60]">

    @if ($this->workspace)
        <div x-show="open" class="fixed inset-0" role="dialog" aria-modal="true"
             aria-label="{{ __('Ask Planvio AI') }}">

            <div x-show="open"
                 x-transition:enter="ease-out duration-150" x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="ease-in duration-120" x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 x-on:click="open = false"
                 class="absolute inset-0 bg-ink-950/30" aria-hidden="true"></div>

            <div x-show="open"
                 x-ref="panel"
                 x-transition:enter="transform transition ease-[cubic-bezier(0.32,0.72,0,1)] duration-250"
                 x-transition:enter-start="slide-from-end" x-transition:enter-end="translate-x-0"
                 x-transition:leave="transform transition ease-in duration-180"
                 x-transition:leave-start="translate-x-0" x-transition:leave-end="slide-from-end"
                 x-trap.noscroll="open"
                 class="absolute inset-y-0 end-0 flex w-full max-w-lg flex-col border-s
                        border-[var(--line-subtle)] bg-[var(--surface-panel)] shadow-overlay">

                {{-- ------------------------------------------------------ --}}
                {{-- Header                                                 --}}
                {{-- ------------------------------------------------------ --}}
                <div class="flex h-14 shrink-0 items-center gap-2 border-b border-[var(--line-subtle)] px-3">
                    <span class="grid size-7 shrink-0 place-items-center rounded-md border
                                 border-[var(--line-subtle)] bg-[var(--surface-sunken)] text-[var(--text-strong)]">
                        <x-ui.brand-mark class="size-4" />
                    </span>

                    <h2 class="min-w-0 flex-1 truncate text-sm font-semibold text-[var(--text-strong)]">
                        {{ ($opened ? $this->conversation?->title : null) ?: __('Ask Planvio AI') }}
                    </h2>

                    <x-ui.tooltip :label="__('New conversation')">
                        <x-ui.button variant="ghost" size="sm" icon-only wire:click="startNewConversation"
                                     :aria-label="__('New conversation')">
                            <x-icon.plus class="size-4" />
                        </x-ui.button>
                    </x-ui.tooltip>

                    <x-ui.tooltip :label="__('Open the AI workspace')">
                        <x-ui.button variant="ghost" size="sm" icon-only
                                     :href="route('app.ai', $this->workspace)"
                                     :aria-label="__('Open the AI workspace')">
                            <x-icon.chevron-right class="size-4 flip-rtl" />
                        </x-ui.button>
                    </x-ui.tooltip>

                    <button type="button" x-on:click="open = false"
                            class="-me-1 grid size-7 shrink-0 place-items-center rounded text-[var(--text-subtle)]
                                   transition-colors hover:bg-[var(--surface-hover)] hover:text-[var(--text-DEFAULT)]"
                            aria-label="{{ __('Close') }}">
                        <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75">
                            <path d="m5 5 10 10M15 5 5 15" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>

                {{-- ------------------------------------------------------ --}}
                {{-- Thread                                                 --}}
                {{-- ------------------------------------------------------ --}}
                @if ($opened)
                    <div class="scrollbar-thin min-h-0 flex-1 overflow-y-auto"
                         @if ($progress?->live) wire:poll.visible.{{ $progress->interval }}="tick" @endif>
                        @include('livewire.app.ai._thread', ['compact' => true])
                    </div>

                    @include('livewire.app.ai._composer', ['compact' => true])
                @else
                    {{--
                        The body arrives with the first open. Until then this component is
                        drawn on every page in the product and must cost nothing.
                    --}}
                    <div class="grid min-h-0 flex-1 place-items-center">
                        <x-ui.spinner class="size-5 text-[var(--text-subtle)]" />
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>

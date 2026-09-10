@php
    /**
     * The real shortcuts, and only the real ones. Every row here is handled by the
     * `shortcuts` Alpine component in resources/js/app.js or by the component that listens
     * for the event it dispatches — a cheat sheet that lists a key nothing binds is worse
     * than no cheat sheet at all.
     *
     * The modifier is chosen from the user agent so a Mac shows ⌘ and everyone else Ctrl.
     */
    $modifier = str_contains(request()->userAgent() ?? '', 'Mac') ? '⌘' : 'Ctrl';

    $sections = [
        __('Global') => [
            [[$modifier, 'K'], __('Open the command palette')],
            [['/'], __('Search the workspace')],
            [['C'], __('Create something')],
            [['A'], __('Ask Planvio AI')],
            [['?'], __('Show this list')],
        ],
        __('In the command palette') => [
            [['↑', '↓'], __('Move between results')],
            [['↵'], __('Open the highlighted result')],
            [['Tab'], __('Move to the next result')],
            [['Esc'], __('Close')],
        ],
        __('Anywhere') => [
            [['Esc'], __('Close the open panel, drawer or menu')],
            [['Tab'], __('Move to the next control')],
        ],
    ];
@endphp

<div x-data="{ open: false }"
     x-on:open-shortcuts-help.window="open = true"
     x-on:keydown.escape.window="open && (open = false)"
     x-cloak
     class="relative z-[65]">

    <div x-show="open" class="fixed inset-0" role="dialog" aria-modal="true"
         aria-label="{{ __('Keyboard shortcuts') }}">

        <div x-show="open"
             x-transition:enter="ease-out duration-120" x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             x-on:click="open = false"
             class="absolute inset-0 bg-ink-950/40 backdrop-blur-[2px]" aria-hidden="true"></div>

        <div class="absolute inset-0 flex items-end justify-center p-0 sm:items-center sm:p-4">
            <div x-show="open"
                 x-transition:enter="ease-[cubic-bezier(0.32,0.72,0,1)] duration-180"
                 x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-97"
                 x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                 x-transition:leave="ease-in duration-120"
                 x-transition:leave-start="opacity-100 sm:scale-100"
                 x-transition:leave-end="opacity-0 translate-y-2 sm:scale-97"
                 x-trap.noscroll="open"
                 class="w-full max-w-lg rounded-t-xl border border-[var(--line-subtle)]
                        bg-[var(--surface-panel)] shadow-overlay sm:rounded-xl">

                <div class="flex items-start justify-between gap-4 px-5 pt-4">
                    <div>
                        <h2 class="text-base font-semibold text-[var(--text-strong)]">
                            {{ __('Keyboard shortcuts') }}
                        </h2>
                        <p class="mt-1 text-sm text-[var(--text-muted)]">
                            {{ __('Single keys are ignored while you are typing, so they never hijack a field.') }}
                        </p>
                    </div>

                    <button type="button" x-on:click="open = false"
                            class="-me-1.5 -mt-0.5 grid size-7 shrink-0 place-items-center rounded
                                   text-[var(--text-subtle)] transition-colors
                                   hover:bg-[var(--surface-hover)] hover:text-[var(--text-DEFAULT)]"
                            aria-label="{{ __('Close') }}">
                        <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75">
                            <path d="m5 5 10 10M15 5 5 15" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>

                <div class="scrollbar-thin max-h-[70vh] space-y-5 overflow-y-auto px-5 pb-5 pt-4">
                    @foreach ($sections as $heading => $rows)
                        <section>
                            <h3 class="text-2xs font-semibold uppercase tracking-wider text-[var(--text-subtle)]">
                                {{ $heading }}
                            </h3>

                            <dl class="mt-2 divide-y divide-[var(--line-subtle)]">
                                @foreach ($rows as [$keys, $label])
                                    <div class="flex items-center justify-between gap-4 py-1.5">
                                        <dt class="min-w-0 text-sm text-[var(--text-DEFAULT)]">{{ $label }}</dt>
                                        <dd class="flex shrink-0 items-center gap-1">
                                            @foreach ($keys as $key)
                                                <kbd class="grid h-5 min-w-5 place-items-center rounded border
                                                            border-[var(--line-subtle)] bg-[var(--surface-sunken)]
                                                            px-1.5 text-[11px] font-medium text-[var(--text-muted)]">{{ $key }}</kbd>
                                            @endforeach
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        </section>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>

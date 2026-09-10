@props([
    'paginator',
    'pageName' => 'page',
    'label' => null,
])

{{--
    The product's pager.

    Laravel's stock views and Livewire's own both ship a page-number strip designed for a
    marketing site; neither is scanned by this build's Tailwind sources, so neither would
    even arrive styled. This is the dense version: what you are looking at, and one step
    either way, on a single 32px row.

    It drives a Livewire paginator directly — `previousPage`/`nextPage` are the methods
    `WithPagination` provides — so a page change is a component update, not a navigation.

    The disabled state is bound (`:disabled`) rather than written with Blade's `@disabled`
    directive: inside an `<x-…>` component tag that directive currently compiles to broken
    PHP, and the failure surfaces a long way from here as "unexpected token endif".
--}}
@php
    $total = $paginator->total();
    $from = $paginator->firstItem();
    $to = $paginator->lastItem();
@endphp

@if ($total > 0)
    <div {{ $attributes->merge([
        'class' => 'flex items-center justify-between gap-3 border-t border-[var(--line-subtle)] px-3 py-2',
    ]) }}>
        <p class="text-xs tabular-nums text-[var(--text-muted)]" aria-live="polite">
            @if ($paginator->hasPages())
                {{-- Formats::range isolates the span: an en dash between two figures is a
                     neutral, and in an Arabic sentence it prints "10–1". --}}
                {{ __(':from–:to of :total', \App\Support\Formats::range($from, $to) + ['total' => $total]) }}
            @else
                {{ trans_choice('{1} :count item|[2,*] :count items', $total, ['count' => $total]) }}
            @endif
            @if ($label)
                <span class="text-[var(--text-subtle)]">{{ $label }}</span>
            @endif
        </p>

        @if ($paginator->hasPages())
            <div class="flex items-center gap-1">
                <x-ui.button variant="secondary" size="sm" icon-only
                             wire:click="previousPage('{{ $pageName }}')"
                             :disabled="$paginator->onFirstPage()"
                             :aria-label="__('Previous page')">
                    <x-icon.chevron-right class="size-4 rotate-180 flip-rtl" />
                </x-ui.button>

                <span class="px-1 text-xs tabular-nums text-[var(--text-muted)]">
                    {{ __('Page :current of :last', [
                        'current' => $paginator->currentPage(),
                        'last' => $paginator->lastPage(),
                    ]) }}
                </span>

                <x-ui.button variant="secondary" size="sm" icon-only
                             wire:click="nextPage('{{ $pageName }}')"
                             :disabled="! $paginator->hasMorePages()"
                             :aria-label="__('Next page')">
                    <x-icon.chevron-right class="size-4 flip-rtl" />
                </x-ui.button>
            </div>
        @endif
    </div>
@endif

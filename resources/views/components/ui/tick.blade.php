@props(['on' => false])

{{--
    The selected marker in a multi-select menu.

    It is always in the DOM, only invisible when off, so the labels beside it do not shift
    sideways as options are ticked — a menu whose rows jump under the cursor is a menu that
    mis-clicks.
--}}
<svg class="size-3.5 shrink-0 text-[var(--accent)] {{ $on ? 'opacity-100' : 'opacity-0' }}"
     viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.25" aria-hidden="true">
    <path d="m4 10.5 4 4 8-9" stroke-linecap="round" stroke-linejoin="round"/>
</svg>

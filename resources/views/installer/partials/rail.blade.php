{{--
    The step rail: where you are, what is behind you, what is left.

    It is not navigation. Nothing here is a link — jumping back to a completed step would
    invite editing settings the engine has already acted on, and jumping forward would open a
    form whose Continue button leads nowhere. The rail exists to answer "how much more of
    this is there", which on a first install is the only question anybody has.
--}}
@php
    /** @var \App\Services\Install\WizardStep|null $current */
    $current ??= null;
@endphp

<nav class="rail" aria-label="{{ __('Installation steps') }}">
    @foreach (\App\Services\Install\WizardStep::sequence() as $item)
        @php
            $state = $current === null
                ? 'is-todo'
                : ($item === $current ? 'is-current' : ($item->isBefore($current) ? 'is-done' : 'is-todo'));
        @endphp

        <div class="rail-item {{ $state }}"
             @if ($state === 'is-current') aria-current="step" @endif>
            <span class="rail-dot" aria-hidden="true">{{ $state === 'is-done' ? '✓' : $item->position() }}</span>
            <span class="rail-label">{{ $item->label() }}</span>
        </div>
    @endforeach
</nav>

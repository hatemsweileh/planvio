{{--
    The answer to a "Test …" button.

    `$result` is the flattened App\Services\Install\ConnectionTest — a status, a finished
    sentence, an optional note and an optional log reference. Nothing in it came back from the
    far end verbatim, so it is safe to render as it stands.
--}}
@php
    $status = \App\Services\Install\RequirementStatus::tryFrom($result['status'] ?? '')
        ?? \App\Services\Install\RequirementStatus::Fail;
@endphp

<div class="note {{ $status->cssClass() }}" role="status" aria-live="polite">
    <p><strong>{{ $status->symbol() }}</strong> {{ $result['message'] }}</p>

    @if (filled($result['detail'] ?? null))
        <p>{{ $result['detail'] }}</p>
    @endif

    @if (filled($result['reference'] ?? null))
        <p>{{ __('Log reference') }}: <code dir="ltr">{{ $result['reference'] }}</code></p>
    @endif
</div>

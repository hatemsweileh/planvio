{{--
    The head of every wizard panel: where you are, what this screen is, why it matters.
--}}
<div class="panel-head">
    <p class="eyebrow">{{ __('Step :position of :total', [
        'position' => $step->position(),
        'total' => count(\App\Services\Install\WizardStep::sequence()),
    ]) }}</p>
    <h1>{{ $heading }}</h1>
    @if (filled($lede ?? null))
        <p class="lede">{{ $lede }}</p>
    @endif
</div>

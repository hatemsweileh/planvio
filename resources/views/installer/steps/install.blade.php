<div @if ($this->isRunning()) wire:poll.600ms="tick" @endif>
    <section class="panel">
        @include('installer.partials.panel-head', ['step' => $step, 'heading' => $heading, 'lede' => $lede])

        <div class="panel-body stack">
            <div>
                <div class="bar" role="progressbar"
                     aria-valuenow="{{ $this->percent() }}" aria-valuemin="0" aria-valuemax="100"
                     aria-label="{{ __('Installation progress') }}">
                    <span style="width: {{ $this->percent() }}%"></span>
                </div>
                <p class="hint">{{ __(':done of :total steps complete', [
                    'done' => count($completed),
                    'total' => \App\Services\Install\InstallStep::count(),
                ]) }}</p>
            </div>

            @if ($failure !== [])
                {{-- Spec §140: the step, a safe reason, what to do, and a reference we can trace. --}}
                <div class="note is-fail" role="alert">
                    <p><strong>{{ __('Installation could not be completed.') }}</strong></p>
                    <p>{{ __('Nothing has been locked, and the steps that already succeeded will not be repeated.') }}</p>
                </div>

                <dl class="kv">
                    <dt>{{ __('Step') }}</dt>
                    <dd>{{ $failure['label'] ?? '' }}</dd>

                    <dt>{{ __('Reason') }}</dt>
                    <dd>{{ $failure['reason'] ?? '' }}</dd>

                    <dt>{{ __('Suggested action') }}</dt>
                    <dd>{{ $failure['suggestion'] ?? '' }}</dd>

                    <dt>{{ __('Log reference') }}</dt>
                    <dd><code dir="ltr">{{ $failure['reference'] ?? '' }}</code></dd>
                </dl>
            @endif

            <div class="rows">
                @foreach ($this->steps() as $item)
                    @php
                        $done = $this->isCompleted($item);
                        $active = $this->isCurrent($item);
                        $failed = $active && $failure !== [];
                    @endphp

                    <div class="row">
                        @if ($done)
                            <span class="mark is-pass" aria-hidden="true">✓</span>
                        @elseif ($failed)
                            <span class="mark is-fail" aria-hidden="true">✗</span>
                        @elseif ($active)
                            <span class="spin" aria-hidden="true"></span>
                        @else
                            <span class="mark" aria-hidden="true">{{ $item->position() }}</span>
                        @endif

                        <span class="row-name">{{ $item->label() }}</span>
                        <span class="row-detail">
                            @if ($done)
                                {{ __('Done') }}
                            @elseif ($failed)
                                {{ __('Failed') }}
                            @elseif ($active)
                                {{ $item->detail() }}
                            @else
                                {{ __('Waiting') }}
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="panel-foot">
            @if ($failure !== [])
                <button type="button" class="btn btn-secondary" wire:click="reconfigure">{{ __('Change my settings') }}</button>
                <span class="spacer"></span>
                <button type="button" class="btn btn-primary" wire:click="retry" wire:loading.attr="disabled">
                    <span class="spin" wire:loading wire:target="retry" aria-hidden="true"></span>
                    {{ __('Try this step again') }}
                </button>
            @elseif ($finished)
                <span class="spacer"></span>
                <a class="btn btn-primary" href="{{ route('install.finish') }}">{{ __('Continue') }}</a>
            @else
                <span class="pill">
                    <span class="spin" aria-hidden="true"></span>
                    {{ __('Working…') }}
                </span>
                <span class="spacer"></span>
                <span class="hint">{{ __('Do not close this page.') }}</span>
            @endif
        </div>
    </section>
</div>

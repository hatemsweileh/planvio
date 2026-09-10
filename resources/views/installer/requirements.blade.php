@extends('installer.layout')

@section('title', __('Requirements'))

@section('content')
    @php
        $counts = $report->counts();
        $blocking = $report->blocking();
    @endphp

    <section class="panel">
        @include('installer.partials.panel-head', [
            'step' => $step,
            'heading' => __('Check the server'),
            'lede' => __('Planvio needs a handful of things from PHP and from the folders it lives in. Everything marked in red has to be fixed before the installation can start; anything amber is a recommendation you can act on later.'),
        ])

        <div class="panel-body stack">
            @if (session('installer.error'))
                <div class="note is-fail" role="alert">
                    <p>{{ session('installer.error') }}</p>
                </div>
            @endif

            @if ($blocking === [])
                <div class="note is-pass">
                    <p><strong>{{ __('This server can run Planvio.') }}</strong></p>
                    <p>{{ trans_choice('{0}Everything checked out.|[1,*]:count checks passed, :warn worth looking at later.', $counts['pass'], [
                        'count' => $counts['pass'],
                        'warn' => $counts['warn'],
                    ]) }}</p>
                </div>
            @else
                <div class="note is-fail" role="alert">
                    <p><strong>{{ trans_choice('{1}One requirement is not met.|[2,*]:count requirements are not met.', count($blocking), ['count' => count($blocking)]) }}</strong></p>
                    <p>{{ __('Fix the items below, then reload this page. Planvio will not install until they pass.') }}</p>
                </div>
            @endif

            @foreach ($report->groups() as $group => $requirements)
                <div class="group">
                    <p class="group-title">{{ $groupTitles[$group] ?? $group }}</p>

                    <div class="rows">
                        @foreach ($requirements as $requirement)
                            <div class="row">
                                <span class="mark {{ $requirement->status->cssClass() }}"
                                      aria-hidden="true">{{ $requirement->status->symbol() }}</span>
                                <span class="row-name">{{ $requirement->label }}</span>
                                <span class="sr-only">{{ $requirement->status->label() }}</span>
                                {{-- A version, a path or a translated phrase; see welcome.blade.php. --}}
                                <span class="row-detail"><span dir="auto">{{ $requirement->detail }}</span></span>
                            </div>

                            @if ($requirement->remedy !== null)
                                <p class="row-remedy">{{ $requirement->remedy }}</p>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endforeach

            <div class="group">
                <p class="group-title">{{ __('Detected') }}</p>
                <dl class="kv">
                    @foreach ($detected as $label => $value)
                        <dt>{{ $label }}</dt>
                        <dd>{{ $value }}</dd>
                    @endforeach
                </dl>
            </div>
        </div>

        <div class="panel-foot">
            <a class="btn btn-ghost" href="{{ route('install.welcome') }}">{{ __('Back') }}</a>
            <span class="spacer"></span>
            <a class="btn btn-secondary" href="{{ route('install.requirements') }}">{{ __('Check again') }}</a>

            <form method="POST" action="{{ route('install.requirements.continue') }}">
                @csrf
                <button type="submit"
                        class="btn btn-primary"
                        @if ($blocking !== []) disabled aria-disabled="true" @endif>
                    {{ __('Continue') }}
                </button>
            </form>
        </div>
    </section>
@endsection

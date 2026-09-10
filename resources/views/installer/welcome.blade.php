@extends('installer.layout')

@section('title', __('Welcome'))

@section('content')
    <section class="panel">
        @include('installer.partials.panel-head', [
            'step' => $step,
            'heading' => __('Install :app', ['app' => config('planvio.brand.name', 'Planvio')]),
            'lede' => __('Plan the work, and let AI run it. This wizard writes your configuration, creates the database tables and sets up your account. It takes about three minutes and needs no shell access.'),
        ])

        <div class="panel-body stack">
            @if ($resumable)
                <div class="note is-warn">
                    <p><strong>{{ __('There is an installation already in progress.') }}</strong></p>
                    @if ($failure !== null)
                        <p>{{ __('It stopped at “:step”. You can pick up where it left off — nothing that already succeeded will be repeated.', ['step' => $failure['label'] ?? '']) }}</p>
                    @else
                        <p>{{ __('You can pick up where it left off — nothing that already succeeded will be repeated.') }}</p>
                    @endif
                </div>
            @endif

            <div class="rows">
                <div class="row">
                    <span class="row-name">{{ __('Version') }}</span>
                    <span class="row-detail"><span dir="ltr">{{ \App\Support\Version::app() }}</span></span>
                </div>
                <div class="row">
                    <span class="row-name">{{ __('Licence') }}</span>
                    {{--
                        The licence is stated on the first screen because it governs what the
                        person installing may do with what they are about to install, and
                        the AGPL asks something of anybody who offers it over a network. It
                        is named rather than merely linked: an installer is often read on a
                        machine with no way to open a second page.
                    --}}
                    <span class="row-detail">{{ __('GNU Affero General Public License v3.0. Free to run, study, modify and share.') }}</span>
                </div>
                @foreach ($detected as $label => $value)
                    <div class="row">
                        <span class="row-name">{{ $label }}</span>
                        {{--
                            A detected fact is either a machine identifier — a host, a version, a
                            path — or a translated phrase. `dir="auto"` lets each one take the
                            direction of what it actually says: inside an RTL cell a Latin run
                            has its trailing bracket or dot resolved to the paragraph and
                            printed at the wrong end, and forcing ltr would break the phrases.
                        --}}
                        <span class="row-detail"><span dir="auto">{{ $value }}</span></span>
                    </div>
                @endforeach
            </div>

            <div>
                <p class="group-title">{{ __('Before you begin') }}</p>
                <div class="stack-sm">
                    <p>{{ __('Create an empty MySQL or MariaDB database and a user with full privileges on it. On cPanel that is MySQL Databases; the finished names carry your account prefix, like acme_planvio.') }}</p>
                    <p>{{ __('Have the SMTP details for a mailbox on your domain to hand if you want email working straight away. You can skip that step and add it later.') }}</p>
                    <p>{{ __('Nothing is sent anywhere. Planvio does not phone home, does not check licences over the network and does not download anything during installation.') }}</p>
                </div>
            </div>
        </div>

        <div class="panel-foot">
            <span class="spacer"></span>
            @if ($resumable)
                <form method="POST" action="{{ route('install.restart') }}">
                    @csrf
                    <button type="submit" class="btn btn-secondary">{{ __('Start over') }}</button>
                </form>
                <a class="btn btn-primary" href="{{ $resumeUrl }}">{{ __('Resume installation') }}</a>
            @else
                <a class="btn btn-primary" href="{{ route('install.requirements') }}">{{ __('Start installation') }}</a>
            @endif
        </div>
    </section>
@endsection

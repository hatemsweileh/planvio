@extends('installer.layout')

@section('title', __('Finish'))

@section('content')
    <section class="panel">
        @include('installer.partials.panel-head', [
            'step' => $step,
            'heading' => __('Planvio is installed'),
            'lede' => __('Sign in with the administrator account you created. Everything else — projects, people, email, AI — is configurable from inside the product.'),
        ])

        <div class="panel-body stack">
            <div class="note is-pass">
                <p><strong>{{ __('The installer is now closed.') }}</strong></p>
                <p>{{ __('Every installer address is refused from here on, and the check is made on the server rather than by hiding the link.') }}</p>
            </div>

            <div class="group">
                <p class="group-title">{{ __('Next') }}</p>
                <div class="stack-sm">
                    <p>{{ __('Add the two cron entries so reminders, recurring tasks and the queue keep running. The commands are in docs/CRON.md, and your host calls this Cron Jobs.') }}</p>
                    <p>{{ __('Invite your team from Workspace settings, and rename the workspace if “:name” is not what you want to call it.', ['name' => config('app.name')]) }}</p>
                    <p>{{ __('If you skipped email or AI, both are in the admin panel under Settings.') }}</p>
                </div>
            </div>

            <div class="divider"></div>

            <div class="group">
                <p class="group-title">{{ __('If you ever need to reinstall') }}</p>
                <div class="stack-sm">
                    <p>{{ __('There is no web page that reopens this wizard, and there never will be: one would let anyone who found it point your installation at a database of their own. Reinstalling is deliberately two manual acts on the server, and doing only one of them will not reopen it.') }}</p>

                    <div class="rows">
                        <div class="row">
                            <span class="mark is-warn" aria-hidden="true">1</span>
                            <span class="row-name">{{ __('Delete the lock file') }}</span>
                            <span class="row-detail"><code>{{ $lockFile }}</code></span>
                        </div>
                        <div class="row">
                            <span class="mark is-warn" aria-hidden="true">2</span>
                            <span class="row-name">{{ __('Drop every table in the database') }}</span>
                            <span class="row-detail">{{ __('phpMyAdmin → your database → Check all → Drop') }}</span>
                        </div>
                    </div>

                    <p>{{ __('Deleting :progress as well makes the next installation start from the first step rather than resuming this one.', ['progress' => $checkpointFile]) }}</p>
                    <p><strong>{{ __('Both steps destroy data. Take a database backup first.') }}</strong></p>
                </div>
            </div>
        </div>

        <div class="panel-foot">
            <span class="spacer"></span>
            <a class="btn btn-primary" href="{{ $loginUrl }}">{{ __('Sign in to Planvio') }}</a>
        </div>
    </section>
@endsection

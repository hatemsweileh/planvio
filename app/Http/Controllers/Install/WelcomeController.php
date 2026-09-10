<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Services\Install\EnvironmentDetector;
use App\Services\Install\InstallCheckpoint;
use App\Services\Install\InstallState;
use App\Services\Install\WizardStep;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The first screen: what this is, which version, and what it is going to do.
 *
 * Also the resume point. If a previous attempt stopped part way through, the checkpoint on
 * disk knows it, and the welcome screen offers to carry on rather than pretending this is a
 * first visit — which is the whole difference between "installation failed" and "installation
 * failed and now I have to start again" (spec §139).
 */
final class WelcomeController extends Controller
{
    public function show(
        EnvironmentDetector $detector,
        InstallCheckpoint $checkpoint,
        InstallState $state,
    ): View {
        return view('installer.welcome', [
            'step' => WizardStep::Welcome,
            'detected' => $detector->summary(),
            'resumable' => $checkpoint->hasStarted() && ! $checkpoint->isFinished(),
            'failure' => $checkpoint->failure(),
            'resumeUrl' => $state->furthestAvailable()->url(),
        ]);
    }

    /**
     * Throw away a half-finished attempt and begin again.
     *
     * This is not the reset spec §74 forbids. It runs only behind `not-installed`, so it is
     * unreachable the moment the lock exists, and it cannot undo an installation: it forgets
     * which steps have run and the answers given, and touches neither the lock file nor a
     * single row. Its worst case is that the twelve steps — every one of them written to be
     * safe to repeat — run again from the top.
     *
     * A POST rather than a link, because it destroys the progress somebody may have spent
     * twenty minutes on and no prefetcher should be able to do that by following an href.
     */
    public function restart(InstallCheckpoint $checkpoint, InstallState $state): RedirectResponse
    {
        $checkpoint->forget();
        $state->forget();

        return redirect()->route(WizardStep::Requirements->routeName(), status: Response::HTTP_SEE_OTHER);
    }
}

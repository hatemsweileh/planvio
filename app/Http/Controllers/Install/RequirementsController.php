<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Services\Install\EnvironmentDetector;
use App\Services\Install\RequirementChecker;
use App\Services\Install\WizardStep;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The pre-flight report.
 *
 * A controller rather than a Livewire component, deliberately. This is the screen that has to
 * work when nothing else does — including when `storage/framework/views` is unwritable, which
 * is one of the things it reports — so it does a plain render and a plain form post, and
 * "re-check" is a page reload rather than a round trip through a component whose state has
 * nowhere to live.
 *
 * The gate is enforced on the POST, not merely by disabling the button: a mandatory
 * requirement that fails stops the wizard server-side, because a browser is not where that
 * decision gets made.
 */
final class RequirementsController extends Controller
{
    public function show(RequirementChecker $checker, EnvironmentDetector $detector): View
    {
        $report = $checker->check();

        return view('installer.requirements', [
            'step' => WizardStep::Requirements,
            'report' => $report,
            'detected' => $detector->summary(),
            'groupTitles' => self::groupTitles(),
        ]);
    }

    public function proceed(RequirementChecker $checker): RedirectResponse
    {
        if (! $checker->check()->passes()) {
            return redirect()
                ->route(WizardStep::Requirements->routeName())
                ->with('installer.error', __('Some requirements are still not met. Planvio cannot be installed until they are.'));
        }

        return redirect()->route(WizardStep::Database->routeName(), status: Response::HTTP_SEE_OTHER);
    }

    /**
     * @return array<string, string>
     */
    private static function groupTitles(): array
    {
        return [
            'php' => __('PHP'),
            'extensions' => __('PHP extensions'),
            'filesystem' => __('Folders and files'),
        ];
    }
}

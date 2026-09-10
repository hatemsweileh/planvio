<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Services\Install\InstallCheckpoint;
use App\Services\Install\InstallPaths;
use App\Services\Install\WizardStep;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * The success screen, and the only installer route that answers after the lock exists.
 *
 * It has to: this is where the installation reports that it worked, and by the time it
 * renders, `not-installed` would already be turning every other installer URL away. So it
 * guards itself on the checkpoint instead — a completed installation shows the summary,
 * anything else is sent to the front door.
 *
 * Nothing here is a secret. No address, no credential and no path that is not already
 * public: the page is reachable for as long as the checkpoint file sits beside the lock, and
 * what it holds is a list of step names and two timestamps.
 */
final class FinishController extends Controller
{
    public function show(InstallCheckpoint $checkpoint, InstallPaths $paths): View|RedirectResponse
    {
        if (! $checkpoint->isFinished()) {
            return redirect()->route(WizardStep::Welcome->routeName());
        }

        return view('installer.finish', [
            'step' => WizardStep::Finish,
            'lockFile' => $this->relative($paths, $paths->lockFile),
            'checkpointFile' => $this->relative($paths, $checkpoint->path()),
            'loginUrl' => route('login'),
        ]);
    }

    /**
     * Paths are shown relative to the installation root. The absolute path leaks the
     * hosting account's home directory, which is not something a success page needs to say
     * out loud, and `storage/app/…` is what File Manager shows anyway.
     */
    private function relative(InstallPaths $paths, string $path): string
    {
        $base = rtrim($paths->base, '/\\').DIRECTORY_SEPARATOR;

        return str_replace('\\', '/', str_starts_with($path, $base) ? substr($path, strlen($base)) : basename($path));
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Installer;

use App\Livewire\Installer\Concerns\WizardScreen;
use App\Services\Install\InstallationFailed;
use App\Services\Install\Installer;
use App\Services\Install\InstallStep;
use App\Services\Install\WizardStep;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The progress screen — and the thing actually doing the work.
 *
 * One poll, one step. The progress is therefore real: the row highlighted on screen is the
 * step running in the request that drew it, not an animation timed to look plausible. It also
 * keeps each request short, which is what lets a 50-migration schema install on a shared host
 * with a 30-second execution limit.
 *
 * A failure stops the polling and shows the four things spec §140 asks for — the step, a safe
 * explanation, a suggested action and a log reference — with a Retry that resumes at the
 * failed step. Nothing before it is repeated, because the checkpoint on disk remembers what
 * finished (spec §139), and no step is ever left half-applied by design.
 */
final class Install extends Component
{
    use WizardScreen;

    /**
     * Step keys already finished, from the checkpoint file.
     *
     * @var list<string>
     */
    public array $completed = [];

    /**
     * The step now running, or null when there is nothing left.
     */
    public ?string $current = null;

    /**
     * The failure record, exactly as it will be shown.
     *
     * @var array<string, string>
     */
    public array $failure = [];

    public bool $finished = false;

    protected function step(): WizardStep
    {
        return WizardStep::Install;
    }

    public function mount(Installer $installer): void
    {
        if ($this->guardOrder()) {
            return;
        }

        if ($this->state()->plan() === null) {
            $this->redirect($this->state()->furthestAvailable()->url(), navigate: false);

            return;
        }

        $this->sync($installer);
    }

    /**
     * Run the next outstanding step. Called by the poll, once per request.
     */
    public function tick(Installer $installer): void
    {
        if ($this->finished || $this->failure !== []) {
            return;
        }

        $plan = $this->state()->plan();

        if ($plan === null) {
            $this->redirect($this->state()->furthestAvailable()->url(), navigate: false);

            return;
        }

        try {
            $installer->runNext($plan);
        } catch (InstallationFailed) {
            // The engine has already written the failure to the checkpoint; sync() reads it
            // back, so a reload of this page shows the same explanation rather than silently
            // starting the failed step again.
            $this->sync($installer);

            return;
        }

        $this->sync($installer);

        if (! $this->finished) {
            return;
        }

        // The lock exists from here on, so this is the last request the wizard will be
        // allowed to make. Clearing the session takes four passwords off the disk with it.
        $this->state()->forget();

        $this->redirect(route(WizardStep::Finish->routeName()), navigate: false);
    }

    /**
     * Try the failed step again.
     */
    public function retry(Installer $installer): void
    {
        $installer->checkpoint()->clearFailure();

        $this->sync($installer);
    }

    /**
     * Go back and correct whatever caused the failure.
     *
     * The destination is derived from the failed step rather than being "the previous
     * screen": a migration that was refused is a database problem, and sending somebody to
     * the AI settings to fix it would be worse than sending them nowhere.
     */
    public function reconfigure(): void
    {
        $step = InstallStep::tryFrom($this->failure['step'] ?? '');

        $this->redirect(($step?->wizardStep() ?? WizardStep::Database)->url(), navigate: false);
    }

    /**
     * @return list<InstallStep>
     */
    public function steps(): array
    {
        return InstallStep::sequence();
    }

    public function percent(): int
    {
        return (int) round(count($this->completed) / max(1, InstallStep::count()) * 100);
    }

    public function isCompleted(InstallStep $step): bool
    {
        return in_array($step->value, $this->completed, true);
    }

    public function isCurrent(InstallStep $step): bool
    {
        return $this->current === $step->value;
    }

    public function isRunning(): bool
    {
        return ! $this->finished && $this->failure === [];
    }

    public function render(): View
    {
        return $this->screen(
            'installer.steps.install',
            __('Installing Planvio'),
            __('This takes under a minute on most servers. Leave this page open — closing it stops the installation part way through, though you can come back and carry on.'),
        );
    }

    /**
     * Redraw from the checkpoint file rather than from what this component remembers.
     *
     * The file is the record that outlives the page: reloading a failed installation, or
     * coming back to it in a new browser session, has to show the same state the run left
     * behind (spec §139).
     */
    private function sync(Installer $installer): void
    {
        $checkpoint = $installer->checkpoint();
        $completed = [];

        foreach (InstallStep::sequence() as $step) {
            if ($checkpoint->isCompleted($step)) {
                $completed[] = $step->value;
            }
        }

        $this->completed = $completed;
        $this->current = $checkpoint->nextStep()?->value;
        $this->finished = $checkpoint->isFinished();
        $this->failure = $checkpoint->failure() ?? [];
    }
}

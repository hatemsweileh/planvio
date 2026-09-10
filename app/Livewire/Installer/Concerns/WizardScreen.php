<?php

declare(strict_types=1);

namespace App\Livewire\Installer\Concerns;

use App\Services\Install\InstallCheckpoint;
use App\Services\Install\InstallState;
use App\Services\Install\WizardStep;
use Illuminate\Contracts\View\View;

/**
 * The half of every installer screen that is the same on all of them.
 *
 * Three jobs: refuse to open a step whose predecessors have not been completed, render into
 * the self-contained installer layout, and remember the answers.
 *
 * The order guard is server-side and runs in `mount()`. Livewire keeps component state in the
 * request payload rather than the session, so a hand-typed URL is a legitimate way to reach
 * step six with nothing behind it; the redirect back to the earliest outstanding step is what
 * makes that harmless.
 */
trait WizardScreen
{
    /**
     * The screen this component draws. Implemented by each step.
     */
    abstract protected function step(): WizardStep;

    protected function state(): InstallState
    {
        return app(InstallState::class);
    }

    /**
     * Redirect away when this screen is not the customer's to open yet.
     *
     * Returns true when the caller should stop: `mount()` cannot return a response, so the
     * component sets the redirect and simply does no further work.
     */
    protected function guardOrder(): bool
    {
        $state = $this->state();

        if ($state->allows($this->step())) {
            return false;
        }

        $this->redirect($state->furthestAvailable()->url(), navigate: false);

        return true;
    }

    /**
     * Store this screen's answers and move on.
     *
     * @param array<string, mixed> $data
     */
    protected function advance(array $data): void
    {
        $this->state()->put($this->step(), $data);

        // Editing a setting after a failed run invalidates the steps that already acted on
        // the old value, so those are dropped from the checkpoint and will run again.
        $checkpoint = app(InstallCheckpoint::class);

        if ($checkpoint->hasStarted() && ! $checkpoint->isFinished()) {
            $checkpoint->rewindTo($this->step()->rewindTarget());
        }

        $next = $this->step()->next();

        $this->redirect(($next ?? WizardStep::Install)->url(), navigate: false);
    }

    protected function goBack(): void
    {
        $previous = $this->step()->previous();

        $this->redirect(($previous ?? WizardStep::Welcome)->url(), navigate: false);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function screen(string $view, string $heading, string $lede, array $data = []): View
    {
        return view($view, [
            ...$data,
            'step' => $this->step(),
            'heading' => $heading,
            'lede' => $lede,
        ])
            ->extends('installer.layout')
            ->section('content')
            ->layoutData([
                'step' => $this->step(),
                'title' => $this->step()->label(),
            ]);
    }
}

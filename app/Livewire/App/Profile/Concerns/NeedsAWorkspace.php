<?php

declare(strict_types=1);

namespace App\Livewire\App\Profile\Concerns;

use App\Models\Workspace;
use App\Support\CurrentWorkspace;

/**
 * The account screens hang off the product chrome — sidebar, workspace switcher, command
 * palette — and every part of that chrome is addressed per workspace. An account with no
 * membership has nowhere for it to point, so it is sent to onboarding rather than shown a
 * half-drawn shell.
 *
 * `redirect()` during `mount()` skips the render and, on a full page load, aborts with the
 * response, so the layout is never reached with a null workspace.
 *
 * Exposed as a method the component calls rather than as a `mount()` of its own: each of
 * these screens has real work to do in `mount()`, and a class method silently overrides a
 * trait's — which would have made this guard disappear the moment a screen grew a form.
 */
trait NeedsAWorkspace
{
    public function ensureWorkspace(): void
    {
        if ($this->currentWorkspace() === null) {
            $this->redirect(route('workspaces.create'));
        }
    }

    protected function currentWorkspace(): ?Workspace
    {
        return app(CurrentWorkspace::class)->get();
    }
}

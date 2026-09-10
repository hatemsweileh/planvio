<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Workspace;
use Closure;

/**
 * Request/job-scoped holder for the tenant currently being operated on.
 *
 * Registered as a singleton. Nothing is bound by default: an unbound holder makes the
 * WorkspaceScope inert, so background jobs, console commands and AI runs must bind a
 * workspace explicitly before touching tenant data.
 */
final class CurrentWorkspace
{
    private ?Workspace $workspace = null;

    public function set(?Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function get(): ?Workspace
    {
        return $this->workspace;
    }

    public function id(): ?int
    {
        $key = $this->workspace?->getKey();

        return $key === null ? null : (int) $key;
    }

    public function has(): bool
    {
        return $this->workspace !== null;
    }

    public function forget(): void
    {
        $this->workspace = null;
    }

    /**
     * Bind $workspace for the duration of $callback, then restore whatever was bound before.
     *
     * @template TReturn
     *
     * @param Closure(Workspace): TReturn $callback
     * @return TReturn
     */
    public function runFor(Workspace $workspace, Closure $callback): mixed
    {
        $previous = $this->workspace;
        $this->workspace = $workspace;

        try {
            return $callback($workspace);
        } finally {
            $this->workspace = $previous;
        }
    }
}

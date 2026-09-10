<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Workspace;

/**
 * Project keys prefix every task number in a workspace ("WEB-42"), so two projects sharing
 * one would make task keys ambiguous. The database enforces unique(workspace_id, key); this
 * turns that constraint into an answer a person can act on.
 */
final class DuplicateProjectKey extends DomainException
{
    public static function inWorkspace(Workspace $workspace, string $key): self
    {
        return new self(
            __('The project key ":key" is already used in this workspace.', ['key' => $key]),
            [
                'workspace_id' => (int) $workspace->getKey(),
                'key' => $key,
            ],
        );
    }

    /**
     * Raised when deduplication ran out of candidates rather than when a caller supplied a
     * key that was already taken.
     */
    public static function exhausted(Workspace $workspace, string $base): self
    {
        return new self(
            __('No unique project key could be derived from this name. Choose a key manually.'),
            [
                'workspace_id' => (int) $workspace->getKey(),
                'base' => $base,
            ],
        );
    }
}

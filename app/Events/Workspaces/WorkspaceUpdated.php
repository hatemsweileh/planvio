<?php

declare(strict_types=1);

namespace App\Events\Workspaces;

use App\Models\User;
use App\Models\Workspace;

/**
 * Workspace settings changed. `changes` carries the {attribute: {old, new}} shape the
 * activity feed renders, so a listener never has to re-read the model to see what moved.
 */
final readonly class WorkspaceUpdated
{
    public function __construct(
        public Workspace $workspace,
        /** @var array<string, array{old: mixed, new: mixed}> */
        public array $changes,
        public ?User $actor,
    ) {}
}

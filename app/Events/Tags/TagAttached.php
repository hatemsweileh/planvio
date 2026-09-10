<?php

declare(strict_types=1);

namespace App\Events\Tags;

use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A tag was put on a task or a project.
 */
final class TagAttached implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Task|Project $taggable,
        public readonly Tag $tag,
        public readonly User $actor,
    ) {}
}

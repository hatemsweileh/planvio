<?php

declare(strict_types=1);

namespace App\Actions\Dependencies;

use App\Models\Task;
use DomainException;

/**
 * The proposed edge would close a loop in the dependency graph.
 *
 * A loop is not a cosmetic problem: nothing in it can ever start, the timeline has no
 * order to lay out, and any walk over the graph that is not defensively bounded runs until
 * it exhausts memory. The path is carried so the caller can show the person which chain
 * their new link would close.
 */
final class DependencyCycleDetected extends DomainException
{
    /**
     * @param list<int> $path task ids, from the dependency back round to the task itself
     */
    public function __construct(
        public readonly Task $task,
        public readonly Task $dependsOn,
        public readonly array $path,
        public readonly string $pathLabel,
    ) {
        parent::__construct(__('actions.dependencies.cycle', ['path' => $pathLabel]));
    }
}

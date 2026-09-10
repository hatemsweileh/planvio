<?php

declare(strict_types=1);

namespace App\Actions\Dependencies;

use App\Models\Task;
use DomainException;

/**
 * The shortest possible cycle, caught before the graph search so the common mistake gets
 * the plain explanation rather than a path of length one.
 */
final class SelfDependency extends DomainException
{
    public function __construct(public readonly Task $task)
    {
        parent::__construct(__('actions.dependencies.self_dependency'));
    }
}

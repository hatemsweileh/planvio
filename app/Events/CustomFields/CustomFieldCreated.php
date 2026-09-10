<?php

declare(strict_types=1);

namespace App\Events\CustomFields;

use App\Models\CustomField;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A user-defined attribute was added to tasks or projects.
 */
final class CustomFieldCreated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly CustomField $field,
        public readonly User $actor,
    ) {}
}

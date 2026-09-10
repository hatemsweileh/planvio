<?php

declare(strict_types=1);

namespace App\Events\CustomFields;

use App\Models\CustomField;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A custom field and every answer to it were removed.
 */
final class CustomFieldDeleted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly CustomField $field,
        public readonly User $actor,
        public readonly int $valuesRemoved,
    ) {}
}

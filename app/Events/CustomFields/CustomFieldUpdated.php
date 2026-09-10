<?php

declare(strict_types=1);

namespace App\Events\CustomFields;

use App\Models\CustomField;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A custom field's definition changed.
 */
final class CustomFieldUpdated implements ShouldDispatchAfterCommit
{
    /**
     * @param array<string, array{old: mixed, new: mixed}> $changes
     * @param list<string> $removedOptions choices that no longer exist on the field
     */
    public function __construct(
        public readonly CustomField $field,
        public readonly User $actor,
        public readonly array $changes = [],
        public readonly array $removedOptions = [],
    ) {}
}

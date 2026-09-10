<?php

declare(strict_types=1);

namespace App\Events\CustomFields;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * A task or project answered a custom field.
 */
final class CustomFieldValueSet implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly CustomFieldValue $value,
        public readonly CustomField $field,
        public readonly Model $entity,
        public readonly User $actor,
        public readonly mixed $previous = null,
    ) {}
}

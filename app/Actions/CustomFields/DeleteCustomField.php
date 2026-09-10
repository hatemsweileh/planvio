<?php

declare(strict_types=1);

namespace App\Actions\CustomFields;

use App\Events\CustomFields\CustomFieldDeleted;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Remove a field definition and every answer to it.
 *
 * `custom_fields` has no soft deletes and `custom_field_values.custom_field_id` cascades, so
 * this is final: the answers go with the definition. That is the correct outcome — an answer
 * whose field no longer exists cannot be read back, because the column it lives in is chosen
 * by the field's type — but it is also why the count is recorded first. Somebody deleting a
 * field that holds two thousand answers should be able to see afterwards that they did.
 *
 * Callers that want the field gone from the UI without losing the data should deactivate it
 * through {@see UpdateCustomField} instead.
 */
final class DeleteCustomField
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
    ) {}

    public function __invoke(CustomField $field, User $actor): CustomField
    {
        if (! $field->exists) {
            return $field;
        }

        $answers = CustomFieldValue::query()
            ->where('custom_field_id', $field->getKey())
            ->count();

        DB::transaction(function () use ($field, $actor, $answers): void {
            $this->activity->forUser($actor)->log($field, 'deleted', [
                'custom_field_id' => (int) $field->getKey(),
                'name' => (string) $field->name,
                'key' => (string) $field->key,
                'type' => $field->type->value,
                'entity' => (string) $field->entity,
                'project_id' => $field->project_id === null ? null : (int) $field->project_id,
                'values_removed' => $answers,
            ]);

            // Explicit rather than left to the foreign key: SQLite only enforces the
            // cascade when foreign keys are on, and a field whose answers outlived it
            // would leave rows nothing can interpret.
            CustomFieldValue::query()->where('custom_field_id', $field->getKey())->delete();

            $field->delete();
        });

        $this->events->dispatch(new CustomFieldDeleted($field, $actor, $answers));

        return $field;
    }
}

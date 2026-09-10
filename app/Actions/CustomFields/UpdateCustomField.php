<?php

declare(strict_types=1);

namespace App\Actions\CustomFields;

use App\Enums\CustomFieldType;
use App\Events\CustomFields\CustomFieldUpdated;
use App\Exceptions\InvalidCustomField;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Change a field's definition.
 *
 * Two rules protect the answers already given.
 *
 * The type cannot change once the field holds any. Answers live in a column chosen by the
 * type (§5.6): switching a `text` field to `number` would leave every existing answer in
 * `value_text` where nothing looks for it, and re-reading the field would report every task
 * as unanswered. Deactivating the field and defining a new one keeps the history.
 *
 * Removing an option does not delete the answers that used it. They stay, and the removed
 * choices travel on the event so the UI can show which records now hold a value the field
 * no longer offers — a decision for a person, not a silent data loss.
 */
final class UpdateCustomField
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
    ) {}

    /**
     * @param list<string>|null $options
     */
    public function __invoke(
        CustomField $field,
        User $actor,
        string $name,
        ?CustomFieldType $type = null,
        ?array $options = null,
        bool $isRequired = false,
        ?int $position = null,
        bool $isActive = true,
        ?string $key = null,
    ): CustomField {
        $name = trim($name);

        if ($name === '') {
            throw InvalidCustomField::nameRequired();
        }

        $newType = $type ?? $field->type;

        if ($newType !== $field->type && $this->hasAnswers($field)) {
            throw InvalidCustomField::typeChangeWithValues($field, $newType);
        }

        $workspaceId = (int) $field->workspace_id;
        $projectId = $field->project_id === null ? null : (int) $field->project_id;
        $entity = (string) $field->entity;

        $newKey = $key === null ? (string) $field->key : CustomFieldKey::make($name, $key);

        if ($newKey !== (string) $field->key) {
            CustomFieldKey::assertAvailable($newKey, $workspaceId, $projectId, $entity, (int) $field->getKey());
        }

        $previousOptions = $field->choices();
        $choices = CreateCustomField::normaliseOptions($newType, $options ?? $previousOptions);

        $field->name = mb_substr($name, 0, 255);
        $field->key = $newKey;
        $field->type = $newType;
        $field->options = $choices;
        $field->is_required = $isRequired;
        $field->is_active = $isActive;

        if ($position !== null) {
            $field->position = $position;
        }

        $changes = ActivityLogger::changes($field);

        if ($changes === []) {
            return $field;
        }

        $removedOptions = array_values(array_diff($previousOptions, $choices ?? []));

        DB::transaction(function () use ($field, $actor, $changes, $removedOptions): void {
            $field->save();

            $this->activity->forUser($actor)->log($field, 'updated', $changes + [
                'removed_options' => $removedOptions,
            ]);
        });

        $this->events->dispatch(new CustomFieldUpdated($field, $actor, $changes, $removedOptions));

        return $field->refresh();
    }

    private function hasAnswers(CustomField $field): bool
    {
        return CustomFieldValue::query()
            ->where('custom_field_id', $field->getKey())
            ->exists();
    }
}

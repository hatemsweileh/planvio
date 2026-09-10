<?php

declare(strict_types=1);

namespace App\Actions\CustomFields;

use App\Enums\CustomFieldType;
use App\Events\CustomFields\CustomFieldValueSet;
use App\Exceptions\InvalidCustomField;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use DateTimeInterface;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Answer a custom field for one task or project.
 *
 * `custom_field_values` is a column per type, not a blob, so that filtering and sorting stay
 * index-friendly (§5.6). That design only holds if every write puts the value in the column
 * its field's type owns and clears the rest — a field whose type was changed while empty
 * must not still report a value from the column it used to live in. This action is the one
 * place that decides which column that is.
 *
 * Validation is not a convenience here. A `select` answer outside the options, a `number`
 * that is a sentence, a `date` that is not one: each would be coerced by the database into
 * something that is quietly wrong in every report the field appears in. They are refused.
 *
 * Scope is checked as well as type. A field defined on one project cannot be answered by a
 * task in another, whatever the caller passes.
 */
final class SetCustomFieldValue
{
    /** `value_text` is a TEXT column; this keeps a pasted document out of it. */
    private const MAX_TEXT_CHARS = 10000;

    private const MAX_URL_CHARS = 2048;

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
    ) {}

    /**
     * @return CustomFieldValue|null null when the answer was cleared
     */
    public function __invoke(
        CustomField $field,
        Model $entity,
        User $actor,
        mixed $value,
    ): ?CustomFieldValue {
        if ($field->is_active !== true) {
            throw InvalidCustomField::inactive($field);
        }

        self::assertEntityMatches($field, $entity);
        self::assertEntityInScope($field, $entity);

        $existing = CustomFieldValue::query()
            ->where('custom_field_id', $field->getKey())
            ->where('entity_type', $entity->getMorphClass())
            ->where('entity_id', $entity->getKey())
            ->first();

        $previous = $existing?->value();

        if (self::isBlank($value)) {
            if ($field->is_required) {
                throw InvalidCustomField::valueRequired($field);
            }

            return $this->clear($field, $entity, $actor, $existing, $previous);
        }

        $columns = self::columnsFor($field, $value);

        $record = DB::transaction(function () use ($field, $entity, $actor, $existing, $columns, $previous): CustomFieldValue {
            $record = $existing ?? new CustomFieldValue([
                'custom_field_id' => $field->getKey(),
                'entity_id' => $entity->getKey(),
                'entity_type' => $entity->getMorphClass(),
            ]);

            $record->forceFill($columns);
            $record->save();

            // The row reads its own value back through its field's type, so the relation is
            // primed rather than left to a lazy load inside the write.
            $record->setRelation('field', $field);

            $this->activity->forUser($actor)->log($entity, 'custom_field_set', [
                'custom_field_id' => (int) $field->getKey(),
                'key' => (string) $field->key,
                'name' => (string) $field->name,
                'old' => self::loggable($previous),
                'new' => self::loggable($record->value()),
            ]);

            return $record;
        });

        $this->events->dispatch(new CustomFieldValueSet($record, $field, $entity, $actor, $previous));

        return $record;
    }

    /* ------------------------------------------------------------------ *
     * Scope
     * ------------------------------------------------------------------ */

    private static function assertEntityMatches(CustomField $field, Model $entity): void
    {
        $matches = match ((string) $field->entity) {
            CustomField::ENTITY_TASK => $entity instanceof Task,
            CustomField::ENTITY_PROJECT => $entity instanceof Project,
            default => false,
        };

        if (! $matches) {
            throw InvalidCustomField::entityMismatch($field, $entity);
        }
    }

    /**
     * Same workspace always; same project when the field belongs to one.
     */
    private static function assertEntityInScope(CustomField $field, Model $entity): void
    {
        if ((int) $entity->getAttribute('workspace_id') !== (int) $field->workspace_id) {
            throw InvalidCustomField::entityOutOfScope($field, $entity);
        }

        if ($field->project_id === null) {
            return;
        }

        $entityProjectId = $entity instanceof Project
            ? (int) $entity->getKey()
            : (int) $entity->getAttribute('project_id');

        if ($entityProjectId !== (int) $field->project_id) {
            throw InvalidCustomField::entityOutOfScope($field, $entity);
        }
    }

    /* ------------------------------------------------------------------ *
     * Typing
     * ------------------------------------------------------------------ */

    /**
     * The full row: the one column this field's type owns, and every other one nulled.
     *
     * @return array<string, mixed>
     */
    private static function columnsFor(CustomField $field, mixed $value): array
    {
        $columns = [
            'value_text' => null,
            'value_number' => null,
            'value_date' => null,
            'value_bool' => null,
            'value_json' => null,
        ];

        $type = $field->type;
        $columns[$type->valueColumn()] = match ($type) {
            CustomFieldType::Text => self::asText($field, $value),
            CustomFieldType::Url => self::asUrl($field, $value),
            CustomFieldType::Number => self::asNumber($field, $value),
            CustomFieldType::Date => self::asDate($field, $value),
            CustomFieldType::Checkbox => self::asBoolean($value),
            CustomFieldType::Select => self::asChoice($field, $value),
            CustomFieldType::MultiSelect => self::asChoices($field, $value),
        };

        return $columns;
    }

    private static function asText(CustomField $field, mixed $value): string
    {
        if (! is_scalar($value)) {
            throw InvalidCustomField::valueNotText($field);
        }

        $text = trim((string) $value);

        if (mb_strlen($text) > self::MAX_TEXT_CHARS) {
            throw InvalidCustomField::valueTooLong($field, mb_strlen($text), self::MAX_TEXT_CHARS);
        }

        return $text;
    }

    private static function asUrl(CustomField $field, mixed $value): string
    {
        if (! is_scalar($value)) {
            throw InvalidCustomField::valueNotAUrl($field);
        }

        $url = trim((string) $value);

        if (mb_strlen($url) > self::MAX_URL_CHARS) {
            throw InvalidCustomField::valueTooLong($field, mb_strlen($url), self::MAX_URL_CHARS);
        }

        // http and https only. A stored `javascript:` is a link the UI would render, and
        // rendering it is a click away from script running as the reader.
        if (preg_match('#^https?://[^\s/$.?\#].\S*$#i', $url) !== 1) {
            throw InvalidCustomField::valueNotAUrl($field);
        }

        return $url;
    }

    private static function asNumber(CustomField $field, mixed $value): string
    {
        if (is_bool($value) || ! is_numeric($value)) {
            throw InvalidCustomField::valueNotANumber($field);
        }

        // decimal(20,6): formatted rather than cast, so the value that reaches the driver
        // is the one the column stores.
        return number_format((float) $value, 6, '.', '');
    }

    private static function asDate(CustomField $field, mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        if (! is_scalar($value)) {
            throw InvalidCustomField::valueNotADate($field);
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            throw InvalidCustomField::valueNotADate($field);
        }
    }

    private static function asBoolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }

    private static function asChoice(CustomField $field, mixed $value): string
    {
        if (! is_scalar($value)) {
            throw InvalidCustomField::valueNotAnOption($field, '');
        }

        $choice = trim((string) $value);
        $match = self::matchOption($field, $choice);

        if ($match === null) {
            throw InvalidCustomField::valueNotAnOption($field, $choice);
        }

        return $match;
    }

    /**
     * @return list<string>
     */
    private static function asChoices(CustomField $field, mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        $chosen = [];

        foreach ($values as $candidate) {
            if (! is_scalar($candidate)) {
                throw InvalidCustomField::valueNotAnOption($field, '');
            }

            $choice = trim((string) $candidate);

            if ($choice === '') {
                continue;
            }

            $match = self::matchOption($field, $choice);

            if ($match === null) {
                throw InvalidCustomField::valueNotAnOption($field, $choice);
            }

            $chosen[$match] = $match;
        }

        if ($chosen === []) {
            throw InvalidCustomField::valueNotAnOption($field, '');
        }

        return array_values($chosen);
    }

    /**
     * Options are matched case-insensitively but stored exactly as the field defines them,
     * so a filter comparing against the definition always matches.
     */
    private static function matchOption(CustomField $field, string $value): ?string
    {
        foreach ($field->choices() as $option) {
            if (mb_strtolower($option, 'UTF-8') === mb_strtolower($value, 'UTF-8')) {
                return $option;
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------ *
     * Clearing
     * ------------------------------------------------------------------ */

    private function clear(
        CustomField $field,
        Model $entity,
        User $actor,
        ?CustomFieldValue $existing,
        mixed $previous,
    ): ?CustomFieldValue {
        if (! $existing instanceof CustomFieldValue) {
            return null;
        }

        DB::transaction(function () use ($field, $entity, $actor, $existing, $previous): void {
            $existing->delete();

            $this->activity->forUser($actor)->log($entity, 'custom_field_cleared', [
                'custom_field_id' => (int) $field->getKey(),
                'key' => (string) $field->key,
                'name' => (string) $field->name,
                'old' => self::loggable($previous),
                'new' => null,
            ]);
        });

        $this->events->dispatch(new CustomFieldValueSet($existing, $field, $entity, $actor, $previous));

        return null;
    }

    private static function isBlank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        return is_array($value) && $value === [];
    }

    private static function loggable(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        return is_scalar($value) || is_array($value) || $value === null ? $value : null;
    }
}

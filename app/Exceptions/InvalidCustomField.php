<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;

/**
 * A custom field, or an answer to one, cannot be written as asked.
 *
 * Answers live in a column chosen by the field's type (ARCHITECTURE.md §5.6), so a value
 * that does not match the type has nowhere correct to go: accepting it would either write
 * the wrong column or coerce the value into one, and both make the field lie to every
 * filter and report that reads it afterwards.
 */
final class InvalidCustomField extends DomainException
{
    public static function nameRequired(): self
    {
        return new self(__('actions.custom_fields.name_required'));
    }

    public static function keyRequired(): self
    {
        return new self(__('actions.custom_fields.key_required'));
    }

    public static function keyTaken(string $key, ?int $projectId, string $entity): self
    {
        return new self(
            __('actions.custom_fields.key_taken', ['key' => $key]),
            ['key' => $key, 'project_id' => $projectId, 'entity' => $entity],
        );
    }

    public static function invalidEntity(string $entity): self
    {
        return new self(
            __('actions.custom_fields.invalid_entity'),
            ['entity' => $entity],
        );
    }

    public static function optionsRequired(CustomFieldType $type): self
    {
        return new self(
            __('actions.custom_fields.options_required', ['type' => $type->label()]),
            ['type' => $type->value],
        );
    }

    public static function typeChangeWithValues(CustomField $field, CustomFieldType $type): self
    {
        return new self(
            __('actions.custom_fields.type_change_with_values'),
            [
                'custom_field_id' => (int) $field->getKey(),
                'from' => $field->type->value,
                'to' => $type->value,
            ],
        );
    }

    public static function projectInAnotherWorkspace(Project $project, Workspace $workspace): self
    {
        return new self(
            __('actions.custom_fields.project_in_another_workspace'),
            [
                'project_id' => (int) $project->getKey(),
                'project_workspace_id' => (int) $project->workspace_id,
                'workspace_id' => (int) $workspace->getKey(),
            ],
        );
    }

    public static function inactive(CustomField $field): self
    {
        return new self(
            __('actions.custom_fields.field_inactive', ['field' => $field->name]),
            ['custom_field_id' => (int) $field->getKey()],
        );
    }

    public static function entityMismatch(CustomField $field, Model $entity): self
    {
        return new self(
            __('actions.custom_fields.entity_mismatch', ['field' => $field->name]),
            [
                'custom_field_id' => (int) $field->getKey(),
                'expected_entity' => (string) $field->entity,
                'entity_type' => $entity->getMorphClass(),
            ],
        );
    }

    public static function entityOutOfScope(CustomField $field, Model $entity): self
    {
        return new self(
            __('actions.custom_fields.entity_out_of_scope', ['field' => $field->name]),
            [
                'custom_field_id' => (int) $field->getKey(),
                'field_project_id' => $field->project_id === null ? null : (int) $field->project_id,
                'entity_type' => $entity->getMorphClass(),
                'entity_id' => (int) $entity->getKey(),
            ],
        );
    }

    public static function valueRequired(CustomField $field): self
    {
        return new self(
            __('actions.custom_fields.value_required', ['field' => $field->name]),
            ['custom_field_id' => (int) $field->getKey()],
        );
    }

    public static function valueNotAnOption(CustomField $field, string $value): self
    {
        return new self(
            __('actions.custom_fields.value_not_an_option', ['value' => $value, 'field' => $field->name]),
            [
                'custom_field_id' => (int) $field->getKey(),
                'value' => $value,
                'options' => $field->choices(),
            ],
        );
    }

    public static function valueNotText(CustomField $field): self
    {
        return new self(
            __('actions.custom_fields.value_not_text', ['field' => $field->name]),
            ['custom_field_id' => (int) $field->getKey()],
        );
    }

    public static function valueNotANumber(CustomField $field): self
    {
        return new self(
            __('actions.custom_fields.value_not_a_number', ['field' => $field->name]),
            ['custom_field_id' => (int) $field->getKey()],
        );
    }

    public static function valueNotADate(CustomField $field): self
    {
        return new self(
            __('actions.custom_fields.value_not_a_date', ['field' => $field->name]),
            ['custom_field_id' => (int) $field->getKey()],
        );
    }

    public static function valueNotAUrl(CustomField $field): self
    {
        return new self(
            __('actions.custom_fields.value_not_a_url', ['field' => $field->name]),
            ['custom_field_id' => (int) $field->getKey()],
        );
    }

    public static function valueTooLong(CustomField $field, int $length, int $maximum): self
    {
        return new self(
            __('actions.custom_fields.value_too_long', ['field' => $field->name]),
            [
                'custom_field_id' => (int) $field->getKey(),
                'length' => $length,
                'maximum' => $maximum,
            ],
        );
    }
}

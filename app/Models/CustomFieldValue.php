<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomFieldType;
use Database\Factories\CustomFieldValueFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One answer to a {@see CustomField} for one task or project.
 *
 * The row is column-per-type rather than a single blob so that filtering and sorting stay
 * index-friendly; {@see self::value()} and {@see self::setValue()} hide that from callers.
 *
 * The table carries no `workspace_id` — it is tenant-scoped transitively through its field.
 */
final class CustomFieldValue extends Model
{
    /** @use HasFactory<CustomFieldValueFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'custom_field_id',
        'entity_id',
        'entity_type',
        'value_text',
        'value_number',
        'value_date',
        'value_bool',
        'value_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_number' => 'decimal:6',
            'value_date' => 'date',
            'value_bool' => 'boolean',
            'value_json' => 'array',
        ];
    }

    /* ---------------------------------------------------------------- *
     * Relations
     * ---------------------------------------------------------------- */

    /**
     * @return BelongsTo<CustomField, $this>
     */
    public function field(): BelongsTo
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    /* ---------------------------------------------------------------- *
     * Typed access
     * ---------------------------------------------------------------- */

    /**
     * The answer, read from the column its field's type is stored in.
     *
     * Returns null when the field is unreachable (deleted or not loadable), because a value
     * whose type is unknown cannot be interpreted safely.
     */
    public function value(): mixed
    {
        $type = $this->fieldType();

        if ($type === null) {
            return null;
        }

        return match ($type) {
            CustomFieldType::Number => $this->value_number === null ? null : (float) $this->value_number,
            CustomFieldType::MultiSelect => $this->value_json === null ? null : (array) $this->value_json,
            default => $this->getAttribute($type->valueColumn()),
        };
    }

    /**
     * Write the answer into the column its field's type owns and clear every other column,
     * so a field whose type was changed can never report a stale value from the old column.
     */
    public function setValue(mixed $value): void
    {
        $this->forceFill([
            'value_text' => null,
            'value_number' => null,
            'value_date' => null,
            'value_bool' => null,
            'value_json' => null,
        ]);

        $type = $this->fieldType();

        if ($type === null || $value === null) {
            return;
        }

        $this->setAttribute($type->valueColumn(), $this->coerce($type, $value));
    }

    private function fieldType(): ?CustomFieldType
    {
        $type = $this->field?->type;

        return $type instanceof CustomFieldType ? $type : null;
    }

    private function coerce(CustomFieldType $type, mixed $value): mixed
    {
        return match ($type) {
            CustomFieldType::Number => is_numeric($value) ? (float) $value : null,
            CustomFieldType::Date => $value instanceof Carbon ? $value : Carbon::parse((string) $value),
            CustomFieldType::Checkbox => (bool) $value,
            CustomFieldType::MultiSelect => array_values(is_array($value) ? $value : [$value]),
            default => is_scalar($value) ? (string) $value : null,
        };
    }

    /* ---------------------------------------------------------------- *
     * Scopes
     * ---------------------------------------------------------------- */

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForField(Builder $query, CustomField|int $field): Builder
    {
        return $query->where(
            $this->qualifyColumn('custom_field_id'),
            $field instanceof CustomField ? $field->getKey() : $field,
        );
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForEntity(Builder $query, Model $entity): Builder
    {
        return $query
            ->where($this->qualifyColumn('entity_type'), $entity->getMorphClass())
            ->where($this->qualifyColumn('entity_id'), $entity->getKey());
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForEntityType(Builder $query, string $entityType): Builder
    {
        return $query->where($this->qualifyColumn('entity_type'), $entityType);
    }
}

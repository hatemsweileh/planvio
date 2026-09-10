<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<CustomFieldValue>
 */
final class CustomFieldValueFactory extends Factory
{
    protected $model = CustomFieldValue::class;

    /**
     * The default matches CustomFieldFactory's default field type (text), so a value created
     * without states is readable through CustomFieldValue::value().
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'custom_field_id' => CustomField::factory(),
            'entity_type' => Task::class,
            'entity_id' => Task::factory(),
            'value_text' => fake()->sentence(),
            'value_number' => null,
            'value_date' => null,
            'value_bool' => null,
            'value_json' => null,
        ];
    }

    public function forField(CustomField $field): self
    {
        return $this->state(fn (): array => ['custom_field_id' => $field->getKey()]);
    }

    public function forEntity(Model $entity): self
    {
        return $this->state(fn (): array => [
            'entity_type' => $entity->getMorphClass(),
            'entity_id' => $entity->getKey(),
        ]);
    }

    public function text(string $value): self
    {
        return $this->state(fn (): array => $this->onlyColumn('value_text', $value));
    }

    public function number(float $value): self
    {
        return $this->state(fn (): array => $this->onlyColumn('value_number', $value));
    }

    public function date(Carbon|string $value): self
    {
        return $this->state(fn (): array => $this->onlyColumn(
            'value_date',
            $value instanceof Carbon ? $value->toDateString() : $value,
        ));
    }

    public function boolean(bool $value = true): self
    {
        return $this->state(fn (): array => $this->onlyColumn('value_bool', $value));
    }

    /**
     * @param array<int, mixed> $value
     */
    public function json(array $value): self
    {
        return $this->state(fn (): array => $this->onlyColumn('value_json', $value));
    }

    /**
     * Exactly one typed column carries the answer; the rest must be null.
     *
     * @return array<string, mixed>
     */
    private function onlyColumn(string $column, mixed $value): array
    {
        return [
            'value_text' => null,
            'value_number' => null,
            'value_date' => null,
            'value_bool' => null,
            'value_json' => null,
            $column => $value,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomField>
 */
final class CustomFieldFactory extends Factory
{
    protected $model = CustomField::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'project_id' => null,
            'entity' => CustomField::ENTITY_TASK,
            'name' => ucfirst(fake()->words(2, true)),
            // unique(workspace_id, project_id, entity, key) — a run-unique suffix keeps
            // several fields on one workspace from colliding.
            'key' => 'field_'.fake()->unique()->numberBetween(1, 999999),
            'type' => CustomFieldType::Text,
            'options' => null,
            'is_required' => false,
            'position' => 0,
            'is_active' => true,
        ];
    }

    public function ofType(CustomFieldType $type): self
    {
        return $this->state(fn (): array => [
            'type' => $type,
            'options' => $type->hasOptions() ? ['low', 'medium', 'high'] : null,
        ]);
    }

    public function forProject(Project $project): self
    {
        return $this->state(fn (): array => [
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->getKey(),
        ]);
    }

    public function forProjects(): self
    {
        return $this->state(fn (): array => ['entity' => CustomField::ENTITY_PROJECT]);
    }

    public function required(): self
    {
        return $this->state(fn (): array => ['is_required' => true]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}

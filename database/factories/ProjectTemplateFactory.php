<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProjectType;
use App\Enums\StatusCategory;
use App\Models\ProjectTemplate;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectTemplate>
 */
final class ProjectTemplateFactory extends Factory
{
    protected $model = ProjectTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'slug' => fake()->unique()->slug(3),
            'description' => fake()->sentence(),
            'icon' => '📋',
            'color' => '#3F66B0',
            'type' => ProjectType::General,
            'definition' => [
                'statuses' => [
                    ['name' => 'To do', 'category' => StatusCategory::Todo->value, 'position' => 0, 'is_default' => true],
                    ['name' => 'In progress', 'category' => StatusCategory::InProgress->value, 'position' => 1],
                    ['name' => 'Done', 'category' => StatusCategory::Done->value, 'position' => 2, 'is_completed' => true],
                ],
                'milestones' => [
                    ['name' => 'Kick-off', 'offset_days' => 0],
                ],
                'tasks' => [
                    ['title' => 'Define scope', 'status' => 'To do'],
                    ['title' => 'Agree the plan', 'status' => 'To do'],
                ],
                'tags' => ['planning'],
                'views' => [],
            ],
            'is_system' => false,
            'is_active' => true,
        ];
    }

    /**
     * A template shipped with Planvio: no owning workspace, visible everywhere.
     */
    public function system(): self
    {
        return $this->state(fn (): array => [
            'workspace_id' => null,
            'is_system' => true,
        ]);
    }

    public function ofType(ProjectType $type): self
    {
        return $this->state(fn (): array => ['type' => $type]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}

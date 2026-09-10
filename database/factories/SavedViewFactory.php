<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ViewType;
use App\Models\Project;
use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedView>
 */
final class SavedViewFactory extends Factory
{
    protected $model = SavedView::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'project_id' => null,
            'user_id' => User::factory(),
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'type' => ViewType::List,
            'filters' => ['status' => ['todo', 'in_progress']],
            'sorts' => [['field' => 'due_date', 'direction' => 'asc']],
            'columns' => ['title', 'assignee', 'due_date', 'status'],
            'group_by' => null,
            'is_shared' => false,
            'is_pinned' => false,
            'position' => 0,
        ];
    }

    public function forProject(Project $project): self
    {
        return $this->state(fn (): array => [
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->getKey(),
        ]);
    }

    public function ofType(ViewType $type): self
    {
        return $this->state(fn (): array => ['type' => $type]);
    }

    /**
     * A view owned by the workspace rather than by a person.
     */
    public function shared(): self
    {
        return $this->state(fn (): array => [
            'user_id' => null,
            'is_shared' => true,
        ]);
    }

    public function pinned(): self
    {
        return $this->state(fn (): array => ['is_pinned' => true]);
    }

    public function ownedBy(User $user): self
    {
        return $this->state(fn (): array => [
            'user_id' => $user->getKey(),
            'is_shared' => false,
        ]);
    }
}

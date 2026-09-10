<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WikiVisibility;
use App\Models\Project;
use App\Models\User;
use App\Models\WikiPage;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WikiPage>
 */
final class WikiPageFactory extends Factory
{
    protected $model = WikiPage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(4);

        return [
            'workspace_id' => Workspace::factory(),
            'project_id' => null,
            'parent_id' => null,
            'title' => rtrim($title, '.'),
            'slug' => fake()->unique()->slug(4),
            'content' => '<p>'.fake()->paragraph().'</p>',
            'excerpt' => fake()->sentence(),
            'position' => 0,
            // Workspace-wide by default because the default page belongs to no project.
            'visibility' => WikiVisibility::Workspace,
            'author_id' => User::factory(),
            'last_edited_by' => null,
            'ai_generated' => false,
        ];
    }

    public function forProject(Project $project): self
    {
        return $this->state(fn (): array => [
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->getKey(),
            'visibility' => WikiVisibility::Project,
        ]);
    }

    public function childOf(WikiPage $parent): self
    {
        return $this->state(fn (): array => [
            'workspace_id' => $parent->workspace_id,
            'project_id' => $parent->project_id,
            'parent_id' => $parent->getKey(),
            'visibility' => $parent->visibility,
        ]);
    }

    public function private(?User $author = null): self
    {
        return $this->state(fn (): array => array_filter([
            'visibility' => WikiVisibility::Private,
            'author_id' => $author?->getKey(),
        ], static fn (mixed $value): bool => $value !== null));
    }

    public function workspaceWide(): self
    {
        return $this->state(fn (): array => [
            'project_id' => null,
            'visibility' => WikiVisibility::Workspace,
        ]);
    }

    public function aiGenerated(): self
    {
        return $this->state(fn (): array => ['ai_generated' => true]);
    }
}

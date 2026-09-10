<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiMode;
use App\Enums\AiToolRisk;
use App\Enums\WorkspaceRole;
use App\Models\AiPolicy;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiPolicy>
 */
final class AiPolicyFactory extends Factory
{
    protected $model = AiPolicy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'project_id' => null,
            'name' => ucfirst(fake()->unique()->words(2, true)).' policy',
            'mode' => null,
            'allowed_tools' => null,
            'denied_tools' => null,
            'approval_required_tools' => null,
            'max_risk' => AiToolRisk::Medium,
            'allowed_roles' => null,
            'priority' => 0,
            'is_active' => true,
        ];
    }

    public function forProject(Project $project): self
    {
        return $this->state(fn (): array => [
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->getKey(),
        ]);
    }

    /**
     * A rule that applies to every workspace on the platform.
     */
    public function platformWide(): self
    {
        return $this->state(fn (): array => [
            'workspace_id' => null,
            'project_id' => null,
        ]);
    }

    /**
     * @param array<int, string> $tools
     */
    public function allowing(array $tools): self
    {
        return $this->state(fn (): array => ['allowed_tools' => $tools]);
    }

    /**
     * @param array<int, string> $tools
     */
    public function denying(array $tools): self
    {
        return $this->state(fn (): array => ['denied_tools' => $tools]);
    }

    /**
     * @param array<int, string> $tools
     */
    public function requiringApprovalFor(array $tools): self
    {
        return $this->state(fn (): array => ['approval_required_tools' => $tools]);
    }

    public function maxRisk(AiToolRisk $risk): self
    {
        return $this->state(fn (): array => ['max_risk' => $risk]);
    }

    public function forMode(AiMode $mode): self
    {
        return $this->state(fn (): array => ['mode' => $mode]);
    }

    /**
     * @param array<int, WorkspaceRole> $roles
     */
    public function forRoles(array $roles): self
    {
        return $this->state(fn (): array => [
            'allowed_roles' => array_map(static fn (WorkspaceRole $role): string => $role->value, $roles),
        ]);
    }

    public function withPriority(int $priority): self
    {
        return $this->state(fn (): array => ['priority' => $priority]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}

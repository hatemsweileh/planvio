<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiToolRisk;
use App\Enums\ToolRunStatus;
use App\Models\AiRun;
use App\Models\AiToolRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<AiToolRun>
 */
final class AiToolRunFactory extends Factory
{
    protected $model = AiToolRun::class;

    /**
     * The parent run is created inside this row's own workspace: a tool run whose run belongs
     * to a different tenant could never happen in production and would make assertions lie.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'ai_run_id' => fn (array $attributes): int => (int) AiRun::factory()
                ->create(['workspace_id' => $attributes['workspace_id']])
                ->getKey(),
            'project_id' => null,
            'user_id' => User::factory(),
            'tool' => 'search_tasks',
            'risk' => AiToolRisk::Read,
            'arguments' => ['query' => 'overdue'],
            'result_summary' => 'Found 3 tasks.',
            'status' => ToolRunStatus::Succeeded,
            'approval_required' => false,
            'approved_by' => null,
            'approved_at' => null,
            'rejected_reason' => null,
            'subject_id' => null,
            'subject_type' => null,
            'idempotency_key' => null,
            'duration_ms' => fake()->numberBetween(5, 400),
            'error' => null,
            'sequence' => 0,
        ];
    }

    public function forRun(AiRun $run): self
    {
        return $this->state(fn (): array => [
            'ai_run_id' => $run->getKey(),
            'workspace_id' => $run->workspace_id,
            'project_id' => $run->project_id,
            'user_id' => $run->user_id,
        ]);
    }

    public function forTool(string $tool, AiToolRisk $risk = AiToolRisk::Medium): self
    {
        return $this->state(fn (): array => [
            'tool' => $tool,
            'risk' => $risk,
        ]);
    }

    /**
     * A call that changes data, so the audit row carries a subject and an idempotency key.
     */
    public function mutating(): self
    {
        return $this->state(fn (): array => [
            'tool' => 'create_task',
            'risk' => AiToolRisk::Medium,
            'arguments' => ['title' => fake()->sentence(3)],
            'idempotency_key' => sha1((string) fake()->unique()->numberBetween(1, 999999)),
        ]);
    }

    public function forSubject(Model $subject): self
    {
        return $this->state(fn (): array => [
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);
    }

    public function pendingApproval(): self
    {
        return $this->state(fn (): array => [
            'tool' => 'delete_task',
            'risk' => AiToolRisk::Destructive,
            'status' => ToolRunStatus::PendingApproval,
            'approval_required' => true,
            'result_summary' => null,
            'duration_ms' => null,
        ]);
    }

    public function approvedBy(User $approver): self
    {
        return $this->state(fn (): array => [
            'status' => ToolRunStatus::Approved,
            'approval_required' => true,
            'approved_by' => $approver->getKey(),
            'approved_at' => Carbon::now(),
        ]);
    }

    public function rejected(string $reason = 'Not what I asked for'): self
    {
        return $this->state(fn (): array => [
            'status' => ToolRunStatus::Rejected,
            'approval_required' => true,
            'rejected_reason' => $reason,
            'result_summary' => null,
            'duration_ms' => null,
        ]);
    }

    public function failed(string $error = 'The action rejected the arguments'): self
    {
        return $this->state(fn (): array => [
            'status' => ToolRunStatus::Failed,
            'result_summary' => null,
            'error' => $error,
        ]);
    }

    public function atSequence(int $sequence): self
    {
        return $this->state(fn (): array => ['sequence' => $sequence]);
    }
}

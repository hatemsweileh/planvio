<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiMode;
use App\Enums\AiRunStatus;
use App\Enums\AiTrigger;
use App\Models\AiAutomation;
use App\Models\AiConversation;
use App\Models\AiProvider;
use App\Models\AiRun;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiRun>
 */
final class AiRunFactory extends Factory
{
    protected $model = AiRun::class;

    /**
     * The uuid is set here as well as by the model hook so that an unsaved `make()` already
     * carries one.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'workspace_id' => Workspace::factory(),
            'project_id' => null,
            'ai_conversation_id' => null,
            'user_id' => User::factory(),
            'trigger' => AiTrigger::Chat,
            'mode' => AiMode::Assistant,
            'objective' => fake()->sentence(),
            'status' => AiRunStatus::Queued,
            'steps' => 0,
            'tool_call_count' => 0,
            'error_count' => 0,
            'tokens_in' => 0,
            'tokens_out' => 0,
            'model' => 'gpt-4o-mini',
            'ai_provider_id' => null,
            'summary' => null,
            'error' => null,
            'started_at' => null,
            'finished_at' => null,
            'duration_ms' => null,
            'ai_automation_id' => null,
        ];
    }

    public function forProject(Project $project): self
    {
        return $this->state(fn (): array => [
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->getKey(),
        ]);
    }

    public function forConversation(AiConversation $conversation): self
    {
        return $this->state(fn (): array => [
            'workspace_id' => $conversation->workspace_id,
            'project_id' => $conversation->project_id,
            'ai_conversation_id' => $conversation->getKey(),
            'mode' => $conversation->mode,
        ]);
    }

    public function forAutomation(AiAutomation $automation): self
    {
        return $this->state(fn (): array => [
            'workspace_id' => $automation->workspace_id,
            'project_id' => $automation->project_id,
            'ai_automation_id' => $automation->getKey(),
            'trigger' => AiTrigger::Automation,
            'mode' => $automation->mode,
        ]);
    }

    public function usingProvider(AiProvider $provider): self
    {
        return $this->state(fn (): array => [
            'ai_provider_id' => $provider->getKey(),
            'model' => $provider->model,
        ]);
    }

    public function inMode(AiMode $mode): self
    {
        return $this->state(fn (): array => ['mode' => $mode]);
    }

    public function running(): self
    {
        return $this->state(fn (): array => [
            'status' => AiRunStatus::Running,
            'started_at' => Carbon::now()->subSeconds(5),
            'steps' => 1,
        ]);
    }

    public function awaitingApproval(): self
    {
        return $this->state(fn (): array => [
            'status' => AiRunStatus::AwaitingApproval,
            'started_at' => Carbon::now()->subSeconds(10),
            'steps' => 2,
            'tool_call_count' => 1,
        ]);
    }

    public function succeeded(): self
    {
        return $this->finishedWith(AiRunStatus::Succeeded)
            ->state(fn (): array => ['summary' => fake()->sentence()]);
    }

    public function failed(string $error = 'The provider returned an error'): self
    {
        return $this->finishedWith(AiRunStatus::Failed)
            ->state(fn (): array => [
                'error' => $error,
                'error_count' => 1,
            ]);
    }

    public function finishedWith(AiRunStatus $status): self
    {
        $startedAt = Carbon::now()->subSeconds(12);
        $finishedAt = Carbon::now()->subSeconds(2);

        return $this->state(fn (): array => [
            'status' => $status,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => 10_000,
            'steps' => 3,
            'tokens_in' => fake()->numberBetween(200, 2000),
            'tokens_out' => fake()->numberBetween(100, 1200),
        ]);
    }
}

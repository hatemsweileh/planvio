<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiMode;
use App\Enums\AutomationTrigger;
use App\Models\AiAutomation;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiAutomation>
 */
final class AiAutomationFactory extends Factory
{
    protected $model = AiAutomation::class;

    /**
     * Inactive by default so a test that creates one does not have the scheduler pick it up.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'project_id' => null,
            'name' => ucfirst(fake()->unique()->words(2, true)).' automation',
            'description' => fake()->sentence(),
            'trigger_type' => AutomationTrigger::Schedule,
            'schedule_cron' => '0 9 * * 1',
            'event' => null,
            'objective' => 'Summarise last week and flag anything overdue.',
            'mode' => AiMode::Copilot,
            'is_active' => false,
            'last_run_at' => null,
            'last_run_status' => null,
            'next_run_at' => null,
            'lock_token' => null,
            'locked_until' => null,
            'run_count' => 0,
            'failure_count' => 0,
            'created_by' => User::factory(),
        ];
    }

    public function forProject(Project $project): self
    {
        return $this->state(fn (): array => [
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->getKey(),
        ]);
    }

    public function active(): self
    {
        return $this->state(fn (): array => [
            'is_active' => true,
            'next_run_at' => Carbon::now()->addHour(),
        ]);
    }

    /**
     * Active, unclaimed and past its scheduled moment — what a cron tick should pick up.
     */
    public function due(): self
    {
        return $this->state(fn (): array => [
            'is_active' => true,
            'next_run_at' => Carbon::now()->subMinute(),
            'lock_token' => null,
            'locked_until' => null,
        ]);
    }

    public function onEvent(string $event): self
    {
        return $this->state(fn (): array => [
            'trigger_type' => AutomationTrigger::Event,
            'schedule_cron' => null,
            'event' => $event,
            'next_run_at' => null,
        ]);
    }

    public function inMode(AiMode $mode): self
    {
        return $this->state(fn (): array => ['mode' => $mode]);
    }

    /**
     * Claimed by another tick and still held.
     */
    public function locked(int $seconds = 900): self
    {
        return $this->state(fn (): array => [
            'is_active' => true,
            'lock_token' => Str::random(64),
            'locked_until' => Carbon::now()->addSeconds($seconds),
        ]);
    }

    public function failing(int $failures = 3): self
    {
        return $this->state(fn (): array => [
            'failure_count' => $failures,
            'last_run_at' => Carbon::now()->subHour(),
            'last_run_status' => 'failed',
        ]);
    }
}

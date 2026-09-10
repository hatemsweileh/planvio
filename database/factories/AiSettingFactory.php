<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiMode;
use App\Models\AiProvider;
use App\Models\AiSetting;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<AiSetting>
 */
final class AiSettingFactory extends Factory
{
    protected $model = AiSetting::class;

    /**
     * Off by default, mirroring a fresh install: the AI layer only runs once a workspace
     * turns it on and points at an active provider.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'is_enabled' => false,
            'ai_provider_id' => AiProvider::factory(),
            'default_mode' => AiMode::Assistant,
            'system_instructions' => null,
            'communication_style' => null,
            'language' => null,
            'autonomous_enabled' => false,
            'max_tool_calls_per_run' => 25,
            'max_run_seconds' => 180,
            'max_runs_per_day' => 500,
            'error_threshold' => 3,
            'retention_days' => null,
            'notify_on_action' => true,
            'kill_switch_engaged' => false,
            'kill_switch_reason' => null,
            'kill_switch_at' => null,
        ];
    }

    /**
     * The global default row every workspace inherits until it saves its own.
     * `workspace_id` is unique, so only one of these may exist at a time.
     */
    public function global(): self
    {
        return $this->state(fn (): array => ['workspace_id' => null]);
    }

    /**
     * Enabled and attached to an active provider — usable once config('ai.enabled') is true.
     */
    public function enabled(): self
    {
        return $this->state(fn (): array => [
            'is_enabled' => true,
            'ai_provider_id' => AiProvider::factory()->active(),
        ]);
    }

    public function copilot(): self
    {
        return $this->enabled()->state(fn (): array => ['default_mode' => AiMode::Copilot]);
    }

    public function autonomous(): self
    {
        return $this->enabled()->state(fn (): array => [
            'default_mode' => AiMode::Autonomous,
            'autonomous_enabled' => true,
        ]);
    }

    public function usingProvider(AiProvider $provider): self
    {
        return $this->state(fn (): array => ['ai_provider_id' => $provider->getKey()]);
    }

    public function killSwitched(string $reason = 'Engaged during a test'): self
    {
        return $this->state(fn (): array => [
            'kill_switch_engaged' => true,
            'kill_switch_reason' => $reason,
            'kill_switch_at' => Carbon::now(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiMode;
use App\Models\Scopes\WorkspaceScope;
use Database\Factories\AiSettingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The AI configuration for one workspace, or — with a null `workspace_id` — the global
 * default a workspace inherits until it saves its own row.
 *
 * That global tier is why the model does not register {@see WorkspaceScope}:
 * the ambient scope would hide the fallback row exactly when it is needed.
 */
final class AiSetting extends Model
{
    /** @use HasFactory<AiSettingFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id',
        'is_enabled',
        'ai_provider_id',
        'default_mode',
        'system_instructions',
        'communication_style',
        'language',
        'autonomous_enabled',
        'max_tool_calls_per_run',
        'max_run_seconds',
        'max_runs_per_day',
        'error_threshold',
        'retention_days',
        'notify_on_action',
        'kill_switch_engaged',
        'kill_switch_reason',
        'kill_switch_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'default_mode' => AiMode::class,
            'autonomous_enabled' => 'boolean',
            'max_tool_calls_per_run' => 'integer',
            'max_run_seconds' => 'integer',
            'max_runs_per_day' => 'integer',
            'error_threshold' => 'integer',
            'retention_days' => 'integer',
            'notify_on_action' => 'boolean',
            'kill_switch_engaged' => 'boolean',
            'kill_switch_at' => 'datetime',
        ];
    }

    /* ---------------------------------------------------------------- *
     * Relations
     * ---------------------------------------------------------------- */

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<AiProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    /* ---------------------------------------------------------------- *
     * Resolution
     * ---------------------------------------------------------------- */

    /**
     * The row that governs $workspace: its own if it has one, otherwise the global default.
     * Null when neither exists — the AI layer has never been configured.
     */
    public static function forWorkspace(?Workspace $workspace): ?self
    {
        if ($workspace !== null) {
            $own = self::query()
                ->where('workspace_id', $workspace->getKey())
                ->first();

            if ($own !== null) {
                return $own;
            }
        }

        return self::query()->whereNull('workspace_id')->first();
    }

    /* ---------------------------------------------------------------- *
     * Effective state
     * ---------------------------------------------------------------- */

    /**
     * The mode a run actually starts in. Autonomous degrades to copilot whenever autonomous
     * execution is not currently allowed, rather than failing the run (ARCHITECTURE.md §7.7).
     */
    public function effectiveMode(): AiMode
    {
        $mode = $this->default_mode ?? AiMode::Assistant;

        if ($mode === AiMode::Autonomous && ! $this->autonomousAllowed()) {
            return AiMode::Copilot;
        }

        return $mode;
    }

    /**
     * Whether the AI layer may run at all for this workspace: switched on, kill switch clear,
     * an active provider attached, and the platform master switch open.
     */
    public function isUsable(): bool
    {
        return $this->is_enabled
            && ! $this->kill_switch_engaged
            && $this->provider?->is_active === true
            && (bool) config('ai.enabled');
    }

    public function autonomousAllowed(): bool
    {
        return $this->autonomous_enabled && $this->isUsable();
    }

    public function isGlobal(): bool
    {
        return $this->workspace_id === null;
    }

    /* ---------------------------------------------------------------- *
     * Scopes
     * ---------------------------------------------------------------- */

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_enabled'), true);
    }

    /**
     * The global default row.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeGlobal(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('workspace_id'));
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForWorkspace(Builder $query, Workspace|int $workspace): Builder
    {
        return $query->where(
            $this->qualifyColumn('workspace_id'),
            $workspace instanceof Workspace ? $workspace->getKey() : $workspace,
        );
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeKillSwitched(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('kill_switch_engaged'), true);
    }
}

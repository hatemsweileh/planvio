<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiMode;
use App\Enums\AutomationTrigger;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\AiAutomationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A standing instruction the agent carries out on a schedule or in response to an event.
 *
 * `lock_token` / `locked_until` implement the overlap guard: a cron tick claims the automation
 * before running it, so a second tick on shared hosting no-ops (ARCHITECTURE.md §7.5). Taking
 * and releasing that claim is an action's job — the model only reports the state.
 */
final class AiAutomation extends Model
{
    /** @use HasFactory<AiAutomationFactory> */
    use BelongsToWorkspace;

    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id',
        'project_id',
        'name',
        'description',
        'trigger_type',
        'schedule_cron',
        'event',
        'objective',
        'mode',
        'is_active',
        'last_run_at',
        'last_run_status',
        'next_run_at',
        'lock_token',
        'locked_until',
        'run_count',
        'failure_count',
        'created_by',
    ];

    /**
     * `last_run_status` stays a plain string: the contract leaves it untyped and the column is
     * wider than the enum columns, so a future status label must not break hydration.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger_type' => AutomationTrigger::class,
            'mode' => AiMode::class,
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
            'locked_until' => 'datetime',
            'run_count' => 'integer',
            'failure_count' => 'integer',
        ];
    }

    /* ---------------------------------------------------------------- *
     * Relations
     * ---------------------------------------------------------------- */

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<AiRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(AiRun::class, 'ai_automation_id');
    }

    /* ---------------------------------------------------------------- *
     * Derived reads
     * ---------------------------------------------------------------- */

    public function isLocked(): bool
    {
        return $this->locked_until instanceof Carbon && $this->locked_until->isFuture();
    }

    public function isScheduled(): bool
    {
        return $this->trigger_type === AutomationTrigger::Schedule;
    }

    public function isEventDriven(): bool
    {
        return $this->trigger_type === AutomationTrigger::Event;
    }

    /* ---------------------------------------------------------------- *
     * Scopes
     * ---------------------------------------------------------------- */

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_active'), true);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForProject(Builder $query, Project|int $project): Builder
    {
        return $query->where(
            $this->qualifyColumn('project_id'),
            $project instanceof Project ? $project->getKey() : $project,
        );
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeTriggeredBy(Builder $query, AutomationTrigger $trigger): Builder
    {
        return $query->where($this->qualifyColumn('trigger_type'), $trigger->value);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForEvent(Builder $query, string $event): Builder
    {
        return $query
            ->where($this->qualifyColumn('trigger_type'), AutomationTrigger::Event->value)
            ->where($this->qualifyColumn('event'), $event);
    }

    /**
     * Automations no tick currently holds.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeUnlocked(Builder $query, ?Carbon $at = null): Builder
    {
        $moment = $at ?? Carbon::now();

        return $query->where(function (Builder $free) use ($moment): void {
            $free
                ->whereNull($this->qualifyColumn('locked_until'))
                ->orWhere($this->qualifyColumn('locked_until'), '<=', $moment);
        });
    }

    /**
     * Scheduled automations that are due and unclaimed, along
     * `(workspace_id, is_active, next_run_at)`.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeDue(Builder $query, ?Carbon $at = null): Builder
    {
        $moment = $at ?? Carbon::now();

        return $query
            ->where($this->qualifyColumn('is_active'), true)
            ->whereNotNull($this->qualifyColumn('next_run_at'))
            ->where($this->qualifyColumn('next_run_at'), '<=', $moment)
            ->unlocked($moment)
            ->orderBy($this->qualifyColumn('next_run_at'));
    }
}

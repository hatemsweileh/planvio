<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiMode;
use App\Enums\AiRunStatus;
use App\Enums\AiTrigger;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\AiRunFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One execution of the agent loop, from queued to a terminal status.
 *
 * The run is the parent of the audit trail: every tool call, message and activity produced
 * while it executed points back here (ARCHITECTURE.md §7.1).
 */
final class AiRun extends Model
{
    /** @use HasFactory<AiRunFactory> */
    use BelongsToWorkspace;

    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'uuid',
        'workspace_id',
        'project_id',
        'ai_conversation_id',
        'user_id',
        'trigger',
        'mode',
        'objective',
        'status',
        'steps',
        'tool_call_count',
        'error_count',
        'tokens_in',
        'tokens_out',
        'model',
        'ai_provider_id',
        'summary',
        'error',
        'started_at',
        'finished_at',
        'duration_ms',
        'ai_automation_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => AiTrigger::class,
            'mode' => AiMode::class,
            'status' => AiRunStatus::class,
            'steps' => 'integer',
            'tool_call_count' => 'integer',
            'error_count' => 'integer',
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $run): void {
            if (! is_string($run->uuid) || $run->uuid === '') {
                $run->uuid = (string) Str::uuid();
            }
        });
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
     * @return BelongsTo<AiConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    /**
     * The acting user whose authority the run borrows. Null once the account is deleted.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<AiToolRun, $this>
     */
    public function toolRuns(): HasMany
    {
        return $this->hasMany(AiToolRun::class)
            ->orderBy('sequence')
            ->orderBy('id');
    }

    /**
     * @return HasMany<AiMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<AiAutomation, $this>
     */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(AiAutomation::class, 'ai_automation_id');
    }

    /**
     * @return BelongsTo<AiProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    /* ---------------------------------------------------------------- *
     * Lifecycle
     * ---------------------------------------------------------------- */

    /**
     * Move the run into `running` and stamp its start. Re-entrant: a run resumed after an
     * approval keeps its original `started_at` so the duration covers the whole execution.
     */
    public function markRunning(): void
    {
        $this->status = AiRunStatus::Running;
        $this->started_at ??= Carbon::now();

        $this->save();
    }

    /**
     * Close the run in $status, recording how long it took.
     */
    public function markFinished(AiRunStatus $status, ?string $summary = null): void
    {
        $finishedAt = Carbon::now();

        $this->status = $status;
        $this->finished_at = $finishedAt;

        if ($summary !== null) {
            $this->summary = $summary;
        }

        if ($this->started_at instanceof Carbon) {
            $this->duration_ms = (int) round(abs($this->started_at->diffInMilliseconds($finishedAt)));
        }

        $this->save();
    }

    /**
     * A terminal run never advances again; nothing may be appended to it.
     */
    public function isTerminal(): bool
    {
        return $this->status?->isTerminal() ?? false;
    }

    /**
     * Wall-clock seconds spent. Falls back to the timestamps when `duration_ms` has not been
     * written yet, and reports elapsed time for a run still in flight.
     */
    public function durationSeconds(): ?float
    {
        if ($this->duration_ms !== null) {
            return $this->duration_ms / 1000;
        }

        if (! $this->started_at instanceof Carbon) {
            return null;
        }

        $end = $this->finished_at instanceof Carbon ? $this->finished_at : Carbon::now();

        return abs($this->started_at->diffInMilliseconds($end)) / 1000;
    }

    /* ---------------------------------------------------------------- *
     * Scopes
     * ---------------------------------------------------------------- */

    /**
     * Newest first, along the `(workspace_id, created_at)` index.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query
            ->orderByDesc($this->qualifyColumn('created_at'))
            ->orderByDesc($this->qualifyColumn('id'));
    }

    /**
     * @param Builder<static> $query
     * @param AiRunStatus|array<int, AiRunStatus> $status
     * @return Builder<static>
     */
    public function scopeWithStatus(Builder $query, AiRunStatus|array $status): Builder
    {
        if ($status instanceof AiRunStatus) {
            return $query->where($this->qualifyColumn('status'), $status->value);
        }

        return $query->whereIn(
            $this->qualifyColumn('status'),
            array_map(static fn (AiRunStatus $case): string => $case->value, $status),
        );
    }

    /**
     * Runs that have not reached a terminal status.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeUnfinished(Builder $query): Builder
    {
        return $query->whereIn(
            $this->qualifyColumn('status'),
            array_values(array_map(
                static fn (AiRunStatus $case): string => $case->value,
                array_filter(AiRunStatus::cases(), static fn (AiRunStatus $case): bool => ! $case->isTerminal()),
            )),
        );
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeAwaitingApproval(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), AiRunStatus::AwaitingApproval->value);
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
    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where(
            $this->qualifyColumn('user_id'),
            $user instanceof User ? $user->getKey() : $user,
        );
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeTriggeredBy(Builder $query, AiTrigger $trigger): Builder
    {
        return $query->where($this->qualifyColumn('trigger'), $trigger->value);
    }
}

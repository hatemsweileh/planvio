<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiToolRisk;
use App\Enums\ToolRunStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\AiToolRunFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One tool invocation inside an {@see AiRun} — the audit spine of the AI layer.
 *
 * Every mutation the agent performs leaves a row here: what was called, with which (redacted)
 * arguments, under whose authority, whether it needed approval and who granted it
 * (ARCHITECTURE.md §7.1). `arguments` is redacted before it is written and must never carry a
 * secret.
 */
final class AiToolRun extends Model
{
    /** @use HasFactory<AiToolRunFactory> */
    use BelongsToWorkspace;

    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'ai_run_id',
        'workspace_id',
        'project_id',
        'user_id',
        'tool',
        'risk',
        'arguments',
        'result_summary',
        'status',
        'approval_required',
        'approved_by',
        'approved_at',
        'rejected_reason',
        'subject_id',
        'subject_type',
        'idempotency_key',
        'duration_ms',
        'error',
        'sequence',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'risk' => AiToolRisk::class,
            'status' => ToolRunStatus::class,
            'approval_required' => 'boolean',
            'approved_at' => 'datetime',
            'duration_ms' => 'integer',
            'sequence' => 'integer',
        ];
    }

    /* ---------------------------------------------------------------- *
     * Relations
     * ---------------------------------------------------------------- */

    /**
     * @return BelongsTo<AiRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The acting user the call was authorised as.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The human who approved the call, when one was required.
     *
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * The record the tool acted on. Null for reads and for calls that never executed.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /* ---------------------------------------------------------------- *
     * Derived reads
     * ---------------------------------------------------------------- */

    public function isMutating(): bool
    {
        return ($this->risk ?? AiToolRisk::Read) !== AiToolRisk::Read;
    }

    public function isAwaitingDecision(): bool
    {
        return $this->status?->isAwaitingDecision() ?? false;
    }

    /* ---------------------------------------------------------------- *
     * Scopes
     * ---------------------------------------------------------------- */

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopePendingApproval(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), ToolRunStatus::PendingApproval->value);
    }

    /**
     * Calls that changed something, or would have — everything above read-only risk.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeMutating(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('risk'), '!=', AiToolRisk::Read->value);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForRun(Builder $query, AiRun|int $run): Builder
    {
        return $query->where(
            $this->qualifyColumn('ai_run_id'),
            $run instanceof AiRun ? $run->getKey() : $run,
        );
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
    public function scopeForTool(Builder $query, string $tool): Builder
    {
        return $query->where($this->qualifyColumn('tool'), $tool);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeWithStatus(Builder $query, ToolRunStatus $status): Builder
    {
        return $query->where($this->qualifyColumn('status'), $status->value);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeAtLeastRisk(Builder $query, AiToolRisk $risk): Builder
    {
        return $query->whereIn(
            $this->qualifyColumn('risk'),
            array_values(array_map(
                static fn (AiToolRisk $case): string => $case->value,
                array_filter(AiToolRisk::cases(), static fn (AiToolRisk $case): bool => $case->atLeast($risk)),
            )),
        );
    }

    /**
     * Execution order within a run, along the `(ai_run_id, sequence)` index.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy($this->qualifyColumn('sequence'))
            ->orderBy($this->qualifyColumn('id'));
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query
            ->orderByDesc($this->qualifyColumn('created_at'))
            ->orderByDesc($this->qualifyColumn('id'));
    }
}

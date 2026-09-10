<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MilestoneStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\MilestoneFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

final class Milestone extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<MilestoneFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'project_id',
        'name',
        'description',
        'status',
        'start_date',
        'due_date',
        'completed_at',
        'owner_id',
        'position',
        'progress',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MilestoneStatus::class,
            'start_date' => 'date',
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'position' => 'integer',
            'progress' => 'integer',
        ];
    }

    /* ------------------------------------------------------------------ *
     * Relationships
     * ------------------------------------------------------------------ */

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
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /* ------------------------------------------------------------------ *
     * Accessors
     * ------------------------------------------------------------------ */

    /**
     * @return Attribute<bool, never>
     */
    protected function isOverdue(): Attribute
    {
        return Attribute::get(fn (): bool => $this->due_date !== null
            && $this->completed_at === null
            && $this->status instanceof MilestoneStatus
            && $this->status->isOpen()
            && $this->due_date->lt(Carbon::today()));
    }

    /**
     * Completion measured from the milestone's tasks. Falls back to the stored `progress`
     * cache while no task has been attached yet.
     *
     * @return Attribute<int, never>
     */
    protected function taskProgress(): Attribute
    {
        return Attribute::get(function (): int {
            $total = $this->tasks()->count();

            if ($total === 0) {
                return (int) $this->progress;
            }

            $completed = $this->tasks()
                ->whereHas('status', fn (Builder $query): Builder => $query->where('is_completed', true))
                ->count();

            return (int) round($completed / $total * 100);
        })->shouldCache();
    }

    /* ------------------------------------------------------------------ *
     * Scopes
     * ------------------------------------------------------------------ */

    /**
     * Milestones that are still being worked towards.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('status'), self::openStatusValues());
    }

    /**
     * Open, dated, and not yet past — ordered so the nearest deadline comes first.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query
            ->open()
            ->whereNotNull($this->qualifyColumn('due_date'))
            ->where($this->qualifyColumn('due_date'), '>=', Carbon::today()->toDateString())
            ->orderBy($this->qualifyColumn('due_date'));
    }

    /**
     * Compared against a plain date string rather than with whereDate() so the
     * (project_id, due_date) index is usable.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->open()
            ->whereNull($this->qualifyColumn('completed_at'))
            ->whereNotNull($this->qualifyColumn('due_date'))
            ->where($this->qualifyColumn('due_date'), '<', Carbon::today()->toDateString());
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
    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy($this->qualifyColumn('position'))
            ->orderBy($this->qualifyColumn('id'));
    }

    /**
     * @return list<string>
     */
    private static function openStatusValues(): array
    {
        return array_values(array_map(
            static fn (MilestoneStatus $status): string => $status->value,
            array_filter(
                MilestoneStatus::cases(),
                static fn (MilestoneStatus $status): bool => $status->isOpen(),
            ),
        ));
    }
}

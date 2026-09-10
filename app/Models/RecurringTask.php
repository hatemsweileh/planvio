<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RecurrenceFrequency;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\RecurringTaskFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A rule that mints tasks on a schedule (ARCHITECTURE.md §5.3).
 *
 * `template` holds the attributes each generated task starts from — title, description,
 * priority, assignee, estimate. Generation itself lives in App\Services / App\Actions;
 * this model only stores the rule and the cursor (`next_run_on`, `last_run_on`).
 */
final class RecurringTask extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<RecurringTaskFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'project_id',
        'template',
        'frequency',
        'interval',
        'by_weekday',
        'by_monthday',
        'starts_on',
        'ends_on',
        'next_run_on',
        'last_run_on',
        'occurrences_generated',
        'max_occurrences',
        'is_active',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'template' => 'array',
            'frequency' => RecurrenceFrequency::class,
            'interval' => 'integer',
            'by_weekday' => 'array',
            'by_monthday' => 'array',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'next_run_on' => 'date',
            'last_run_on' => 'date',
            'occurrences_generated' => 'integer',
            'max_occurrences' => 'integer',
            'is_active' => 'boolean',
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /* ------------------------------------------------------------------ *
     * Scopes
     * ------------------------------------------------------------------ */

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_active'), true);
    }

    /**
     * Rules whose cursor has come round, in the shape of
     * index(workspace_id, is_active, next_run_on).
     *
     * The bound is exclusive on the following day rather than inclusive on `$on`. MySQL
     * stores a `date` column as a bare date, SQLite stores whatever Eloquent's date cast
     * writes — a full "Y-m-d H:i:s" — so `<= $on` would skip every rule due that very day
     * under SQLite, which is exactly the day the generator runs.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeDueOn(Builder $query, DateTimeInterface|string|null $on = null): Builder
    {
        $date = $on === null ? Carbon::now() : Carbon::parse($on);

        return $query->where($this->qualifyColumn('is_active'), true)
            ->whereNotNull($this->qualifyColumn('next_run_on'))
            ->where($this->qualifyColumn('next_run_on'), '<', $date->copy()->addDay()->toDateString());
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
     * @param RecurrenceFrequency|iterable<int, RecurrenceFrequency|string> $frequencies
     * @return Builder<static>
     */
    public function scopeWithFrequency(Builder $query, RecurrenceFrequency|iterable $frequencies): Builder
    {
        $values = [];

        foreach (is_iterable($frequencies) ? $frequencies : [$frequencies] as $frequency) {
            $values[] = $frequency instanceof RecurrenceFrequency ? $frequency->value : (string) $frequency;
        }

        return $query->whereIn($this->qualifyColumn('frequency'), $values);
    }
}

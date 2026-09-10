<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuthorType;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The product-visible change feed (ARCHITECTURE.md §5.4).
 *
 * Distinct from `audit_logs`, which records security and configuration events. `properties`
 * holds the shape {attribute, old, new} and must never carry secrets — it is rendered back
 * to anyone who can see the subject.
 *
 * `causer_type` is an AuthorType, not a morph type: the causer is always a user, and the
 * column records whether that user acted directly or through the agent. An AI-caused row
 * carries `ai_run_id` so the run stays traceable.
 */
final class Activity extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'project_id',
        'subject_id',
        'subject_type',
        'causer_id',
        'causer_type',
        'ai_run_id',
        'event',
        'description',
        'properties',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'causer_type' => AuthorType::class,
            'properties' => 'array',
        ];
    }

    /* ------------------------------------------------------------------ *
     * Relationships
     * ------------------------------------------------------------------ */

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function causer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'causer_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<AiRun, $this>
     */
    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class);
    }

    /* ------------------------------------------------------------------ *
     * Authorship
     * ------------------------------------------------------------------ */

    public function isFromAi(): bool
    {
        return $this->causer_type === AuthorType::Ai;
    }

    /* ------------------------------------------------------------------ *
     * Scopes
     * ------------------------------------------------------------------ */

    /**
     * Newest first, capped — the shape every feed widget wants, and the shape
     * index(workspace_id, created_at) serves.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeRecent(Builder $query, int $limit = 30): Builder
    {
        return $query->orderByDesc($this->qualifyColumn('created_at'))
            ->orderByDesc($this->qualifyColumn('id'))
            ->limit(max(1, $limit));
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query->where($this->qualifyColumn('subject_type'), $subject->getMorphClass())
            ->where($this->qualifyColumn('subject_id'), $subject->getKey());
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
     * @param string|iterable<int, string> $events
     * @return Builder<static>
     */
    public function scopeOfEvent(Builder $query, string|iterable $events): Builder
    {
        $values = [];

        foreach (is_iterable($events) ? $events : [$events] as $event) {
            $values[] = (string) $event;
        }

        return $query->whereIn($this->qualifyColumn('event'), $values);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeFromAi(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('causer_type'), AuthorType::Ai->value);
    }
}

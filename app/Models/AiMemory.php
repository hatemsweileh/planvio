<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiMemoryScope;
use App\Enums\AiMemorySource;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\AiMemoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A durable fact the agent carries between runs, keyed within its scope.
 *
 * Memory content is workspace-derived text and reaches a prompt only wrapped as untrusted
 * data (ARCHITECTURE.md §7.6).
 */
final class AiMemory extends Model
{
    /** @use HasFactory<AiMemoryFactory> */
    use BelongsToWorkspace;

    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id',
        'project_id',
        'user_id',
        'scope',
        'key',
        'content',
        'importance',
        'expires_at',
        'source',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => AiMemoryScope::class,
            'importance' => 'integer',
            'expires_at' => 'datetime',
            'source' => AiMemorySource::class,
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /* ---------------------------------------------------------------- *
     * Derived reads
     * ---------------------------------------------------------------- */

    public function isExpired(?Carbon $at = null): bool
    {
        if (! $this->expires_at instanceof Carbon) {
            return false;
        }

        return $this->expires_at->lessThanOrEqualTo($at ?? Carbon::now());
    }

    /* ---------------------------------------------------------------- *
     * Scopes
     * ---------------------------------------------------------------- */

    /**
     * Memories that have not expired. A null `expires_at` never expires.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query, ?Carbon $at = null): Builder
    {
        $moment = $at ?? Carbon::now();

        return $query->where(function (Builder $live) use ($moment): void {
            $live
                ->whereNull($this->qualifyColumn('expires_at'))
                ->orWhere($this->qualifyColumn('expires_at'), '>', $moment);
        });
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeExpired(Builder $query, ?Carbon $at = null): Builder
    {
        return $query
            ->whereNotNull($this->qualifyColumn('expires_at'))
            ->where($this->qualifyColumn('expires_at'), '<=', $at ?? Carbon::now());
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeWithScope(Builder $query, AiMemoryScope $scope): Builder
    {
        return $query->where($this->qualifyColumn('scope'), $scope->value);
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
    public function scopeWithKey(Builder $query, string $key): Builder
    {
        return $query->where($this->qualifyColumn('key'), $key);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeFromSource(Builder $query, AiMemorySource $source): Builder
    {
        return $query->where($this->qualifyColumn('source'), $source->value);
    }

    /**
     * Most important first — the order the context builder truncates against.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeMostImportant(Builder $query): Builder
    {
        return $query
            ->orderByDesc($this->qualifyColumn('importance'))
            ->orderByDesc($this->qualifyColumn('updated_at'))
            ->orderByDesc($this->qualifyColumn('id'));
    }
}

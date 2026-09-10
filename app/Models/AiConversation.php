<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiMode;
use App\Enums\AiScope;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\AiConversationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One chat thread between a user and the agent, anchored to a workspace, project or task.
 */
final class AiConversation extends Model
{
    /** @use HasFactory<AiConversationFactory> */
    use BelongsToWorkspace;

    use HasFactory;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id',
        'project_id',
        'task_id',
        'user_id',
        'title',
        'mode',
        'scope',
        'last_activity_at',
        'message_count',
        'is_archived',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => AiMode::class,
            'scope' => AiScope::class,
            'last_activity_at' => 'datetime',
            'message_count' => 'integer',
            'is_archived' => 'boolean',
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
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Insertion order is the conversation order; the `(ai_conversation_id, id)` index serves it.
     *
     * @return HasMany<AiMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class)->orderBy('id');
    }

    /**
     * @return HasMany<AiRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(AiRun::class);
    }

    /* ---------------------------------------------------------------- *
     * Scopes
     * ---------------------------------------------------------------- */

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_archived'), false);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_archived'), true);
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
    public function scopeForTask(Builder $query, Task|int $task): Builder
    {
        return $query->where(
            $this->qualifyColumn('task_id'),
            $task instanceof Task ? $task->getKey() : $task,
        );
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeWithScope(Builder $query, AiScope $scope): Builder
    {
        return $query->where($this->qualifyColumn('scope'), $scope->value);
    }

    /**
     * Most recently active first, along `(workspace_id, user_id, last_activity_at)`.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query
            ->orderByDesc($this->qualifyColumn('last_activity_at'))
            ->orderByDesc($this->qualifyColumn('id'));
    }
}

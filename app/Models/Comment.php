<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuthorType;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A threaded comment on any commentable subject (ARCHITECTURE.md §5.4).
 *
 * `body` is sanitised HTML written by the action that stores it, and `author_type`
 * distinguishes a person from the agent: an AI comment carries `ai_run_id` so the run
 * that produced it stays auditable.
 */
final class Comment extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<CommentFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'commentable_id',
        'commentable_type',
        'user_id',
        'body',
        'author_type',
        'ai_run_id',
        'parent_id',
        'edited_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'author_type' => AuthorType::class,
            'edited_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------------ *
     * Relationships
     * ------------------------------------------------------------------ */

    /**
     * @return MorphTo<Model, $this>
     */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Comment, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at');
    }

    /**
     * @return HasMany<CommentReaction, $this>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(CommentReaction::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
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
        return $this->author_type === AuthorType::Ai;
    }

    /* ------------------------------------------------------------------ *
     * Scopes
     * ------------------------------------------------------------------ */

    /**
     * Top-level comments only — the thread starters.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('parent_id'));
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForCommentable(Builder $query, Model $commentable): Builder
    {
        return $query->where($this->qualifyColumn('commentable_type'), $commentable->getMorphClass())
            ->where($this->qualifyColumn('commentable_id'), $commentable->getKey());
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeFromAi(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('author_type'), AuthorType::Ai->value);
    }
}

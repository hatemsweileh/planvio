<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CommentReactionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One emoji reaction by one user on one comment (ARCHITECTURE.md §5.4).
 *
 * Reached only through its comment, which carries the tenant column; the unique index on
 * (comment_id, user_id, emoji) makes a second identical reaction impossible.
 */
final class CommentReaction extends Model
{
    /** @use HasFactory<CommentReactionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'comment_id',
        'user_id',
        'emoji',
    ];

    /* ------------------------------------------------------------------ *
     * Relationships
     * ------------------------------------------------------------------ */

    /**
     * @return BelongsTo<Comment, $this>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /* ------------------------------------------------------------------ *
     * Scopes
     * ------------------------------------------------------------------ */

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForComment(Builder $query, Comment|int $comment): Builder
    {
        return $query->where(
            $this->qualifyColumn('comment_id'),
            $comment instanceof Comment ? $comment->getKey() : $comment,
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
    public function scopeWithEmoji(Builder $query, string $emoji): Builder
    {
        return $query->where($this->qualifyColumn('emoji'), $emoji);
    }
}

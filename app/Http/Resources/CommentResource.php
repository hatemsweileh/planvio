<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Comment;
use App\Services\HtmlSanitizer;
use Illuminate\Http\Request;

/**
 * A comment.
 *
 * `body` is sanitised HTML — it went through {@see HtmlSanitizer} on the way
 * in, which is what makes it safe to store and to hand back. It is still *someone else's*
 * text: a client that renders it into a page owns the same escaping decision Planvio owns,
 * and a client that feeds it to a language model must treat it as data rather than as
 * instructions (ARCHITECTURE.md §7.6).
 *
 * `subject_type` is the model's morph class, which on a Planvio install with no morph map is
 * the fully-qualified class name (`App\Models\Task`). Match on it by equality rather than
 * parsing it: it is the value `comments.commentable_type` holds, and it is what
 * `GET /activity?subject_type=…` expects back.
 *
 * @property Comment $resource
 */
final class CommentResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $comment = $this->resource;

        return [
            'id' => (int) $comment->getKey(),
            'workspace_id' => self::id($comment->workspace_id),
            'subject_type' => (string) $comment->commentable_type,
            'subject_id' => self::id($comment->commentable_id),
            'user_id' => self::id($comment->user_id),
            'author_type' => self::enum($comment->author_type),
            'ai_run_id' => self::id($comment->ai_run_id),
            'parent_id' => self::id($comment->parent_id),
            'body' => (string) $comment->body,
            'edited_at' => self::iso($comment->edited_at),
            'created_at' => self::iso($comment->created_at),
            'updated_at' => self::iso($comment->updated_at),

            'author' => $this->whenLoaded('user', fn (): ?array => $comment->user === null
                ? null
                : (new UserResource($comment->user))->resolve($request)),
        ];
    }
}

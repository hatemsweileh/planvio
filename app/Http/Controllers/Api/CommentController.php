<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Comments\CreateComment;
use App\Actions\Comments\DeleteComment;
use App\Actions\Comments\UpdateComment;
use App\Http\Requests\Api\StoreCommentRequest;
use App\Http\Requests\Api\UpdateCommentRequest;
use App\Http\Resources\ApiResponse;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Comments on a task.
 *
 * The table is polymorphic and the product will comment on more than tasks in time, but the
 * API publishes only what the domain actually supports today: {@see Task} is the one model
 * with a `comments()` relation. An endpoint for a subject nobody can comment on would be a
 * contract we would have to keep.
 *
 * Threading is one level. `parent_id` names the comment being replied to; a reply to a reply
 * is stored against the same root, which is what keeps a thread readable rather than a tree
 * nobody can render.
 */
final class CommentController extends ApiController
{
    /**
     * `GET /tasks/{task}/comments` — oldest first, which is the order a thread is read in.
     */
    public function index(Request $request, string $task): JsonResponse
    {
        $subject = $this->findInWorkspace($request, Task::class, $task);

        $this->authorize('view', $subject);
        $this->authorize('viewAny', [Comment::class, $subject]);

        $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Comment::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $subject->workspace_id)
            ->where('commentable_type', $subject->getMorphClass())
            ->where('commentable_id', $subject->getKey())
            ->with('user')
            ->orderBy('created_at')
            ->orderBy('id');

        return ApiResponse::paginated($this->paginate($request, $query), CommentResource::class);
    }

    /**
     * `POST /tasks/{task}/comments`.
     */
    public function store(StoreCommentRequest $request, string $task, CreateComment $create): JsonResponse
    {
        $subject = $this->findInWorkspace($request, Task::class, $task);

        $this->authorize('view', $subject);
        $this->authorize('create', [Comment::class, $subject]);

        $parent = null;
        $parentId = $request->parentId();

        if ($parentId !== null) {
            $parent = $this->findInWorkspace($request, Comment::class, $parentId);

            // A reply has to be a reply to something on this task. Without the check, a
            // `parent_id` from another thread would silently move the reply there.
            abort_if(
                $parent->commentable_type !== $subject->getMorphClass()
                    || (int) $parent->commentable_id !== (int) $subject->getKey(),
                Response::HTTP_NOT_FOUND,
                __('No such comment.'),
            );

            $this->authorize('reply', $parent);
        }

        $comment = $create($subject, $this->actor($request), $request->body(), $parent);
        $comment->load('user');

        return ApiResponse::item(new CommentResource($comment), 201);
    }

    public function show(Request $request, string $comment): JsonResponse
    {
        $record = $this->findInWorkspace($request, Comment::class, $comment, ['user']);

        $this->authorize('view', $record);

        return ApiResponse::item(new CommentResource($record));
    }

    public function update(UpdateCommentRequest $request, string $comment, UpdateComment $update): JsonResponse
    {
        $record = $this->findInWorkspace($request, Comment::class, $comment);

        $this->authorize('update', $record);

        $updated = $update($record, $this->actor($request), $request->body());
        $updated->load('user');

        return ApiResponse::item(new CommentResource($updated));
    }

    public function destroy(Request $request, string $comment, DeleteComment $delete): JsonResponse
    {
        $record = $this->findInWorkspace($request, Comment::class, $comment);

        $this->authorize('delete', $record);

        $delete($record, $this->actor($request));

        return ApiResponse::deleted();
    }
}

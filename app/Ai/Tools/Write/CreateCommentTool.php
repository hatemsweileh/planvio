<?php

declare(strict_types=1);

namespace App\Ai\Tools\Write;

use App\Actions\Comments\CreateComment;
use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Ai\Contracts\AiTool;
use App\Ai\Tools\Concerns\Arguments;
use App\Ai\Tools\Concerns\MutatesThroughActions;
use App\Enums\AiToolRisk;
use App\Enums\AuthorType;
use App\Enums\Permission;
use App\Exceptions\InvalidComment;
use App\Models\Comment;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\WikiPage;
use Illuminate\Database\Eloquent\Model;

/**
 * Post a comment on a task, project, milestone or wiki page — as the assistant, visibly.
 *
 * ## The one property that cannot be negotiated
 *
 * `author_type` is `AuthorType::Ai` and `ai_run_id` is this run. Neither is an argument, so
 * there is no call shape — none, at any risk level, under any policy — that produces a
 * comment presented as a person's own words. The acting user is still recorded, because the
 * comment was posted under their authority and the audit trail has to say whose, but the
 * combination is what the UI reads to render the comment as the agent's and what
 * `CommentPolicy::update()` reads to make it uneditable by anybody. A comment nobody can edit
 * is a comment nobody can retroactively turn into a colleague's.
 *
 * That matters more than it looks. A workspace where the agent can be made to speak as a
 * named person is a workspace where "Sarah approved this in the thread" can be manufactured,
 * and no amount of permission checking further down would catch it.
 *
 * ## The rest
 *
 * The body is sanitised by {@see CreateComment} before storage, `@mentions` are resolved
 * against the project's own membership so a mention cannot push a private thread at somebody
 * outside it, and an identical comment within ten seconds returns the existing row instead of
 * writing a second one.
 */
final class CreateCommentTool implements AiTool
{
    use MutatesThroughActions;

    public function __construct(private readonly CreateComment $createComment) {}

    public function name(): string
    {
        return 'create_comment';
    }

    public function group(): string
    {
        return 'comments';
    }

    public function description(): string
    {
        return 'Post a comment on a task, project, milestone or wiki page. The comment is '
            .'always shown as written by the assistant and can never be attributed to a '
            .'person. Mentions only reach people who are already members of the project.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['subject_type', 'subject_id', 'body'],
            'properties' => [
                'subject_type' => [
                    'type' => 'string',
                    'enum' => ['task', 'project', 'milestone', 'wiki_page'],
                ],
                'subject_id' => ['type' => 'integer', 'minimum' => 1],
                'body' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 20000,
                    'description' => 'The comment. Plain text or simple HTML; it is sanitised on the way in.',
                ],
                'parent_comment_id' => [
                    'type' => ['integer', 'null'],
                    'minimum' => 1,
                    'description' => 'Reply to this comment. It must be on the same record and not itself a reply.',
                ],
            ],
        ];
    }

    public function risk(): AiToolRisk
    {
        return AiToolRisk::Low;
    }

    public function permission(): ?Permission
    {
        return Permission::TaskComment;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args, AgentContext $ctx): ToolResult
    {
        return $this->handle($args, $ctx, fn (Arguments $in, AgentContext $ctx): ToolResult => $this->post($in, $ctx));
    }

    private function post(Arguments $in, AgentContext $ctx): ToolResult
    {
        $subject = $this->subject($in, $ctx);

        if ($subject instanceof ToolResult) {
            return $subject;
        }

        $ctx->assertInWorkspace($subject);

        if ($ctx->cannot(Permission::TaskComment, $subject)) {
            return $this->denied($ctx, __('comment on :label', ['label' => self::label($subject)]));
        }

        $parent = null;

        if ($in->filled('parent_comment_id')) {
            $parentId = $in->int('parent_comment_id');
            $parent = $this->resolveComment($parentId, $ctx);

            if (! $parent instanceof Comment) {
                return $this->notFound(__('comment'), $parentId);
            }

            $ctx->assertInWorkspace($parent);
        }

        try {
            $comment = ($this->createComment)(
                commentable: $subject,
                author: $ctx->user,
                body: (string) $in->text('body'),
                parent: $parent,
                authorType: AuthorType::Ai,
                aiRun: $ctx->run,
            );
        } catch (InvalidComment $e) {
            return ToolResult::failed($e->getMessage(), 'invalid_comment');
        }

        return ToolResult::ok(
            __('Posted an assistant comment on :label, acting for :user.', [
                'label' => self::label($subject),
                'user' => $ctx->user->name,
            ]),
            [
                'comment_id' => (int) $comment->getKey(),
                'subject_type' => $in->string('subject_type'),
                'subject_id' => (int) $subject->getKey(),
                'author_type' => AuthorType::Ai->value,
                'ai_run_id' => $ctx->runId(),
                'acting_for' => $ctx->user->name,
                'parent_comment_id' => $parent === null ? null : (int) $parent->getKey(),
                'excerpt' => self::clip(trim(strip_tags((string) $comment->body)), 200),
            ],
            $comment,
        );
    }

    private function subject(Arguments $in, AgentContext $ctx): Model|ToolResult
    {
        $id = $in->int('subject_id');

        return match ($in->string('subject_type')) {
            'task' => $this->resolveTask($id, $ctx) ?? $this->notFound(__('task'), $id),
            'project' => $this->resolveProject($id, $ctx) ?? $this->notFound(__('project'), $id),
            'milestone' => $this->resolveMilestone($id, $ctx) ?? $this->notFound(__('milestone'), $id),
            'wiki_page' => $this->resolveWikiPage($id, $ctx) ?? $this->notFound(__('wiki page'), $id),
            default => $this->notFound(__('record'), $id),
        };
    }

    private static function label(Model $subject): string
    {
        return match (true) {
            $subject instanceof Task => __('task :key', ['key' => $subject->key]),
            $subject instanceof Project => __('project :name', ['name' => $subject->name]),
            $subject instanceof Milestone => __('milestone :name', ['name' => $subject->name]),
            $subject instanceof WikiPage => __('wiki page :title', ['title' => $subject->title]),
            default => __('record #:id', ['id' => (string) $subject->getKey()]),
        };
    }
}

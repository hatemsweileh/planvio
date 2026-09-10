<?php

declare(strict_types=1);

namespace App\Ai\Tools\Write;

use App\Actions\Tags\AttachTag;
use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Ai\Contracts\AiTool;
use App\Ai\Tools\Concerns\Arguments;
use App\Ai\Tools\Concerns\MutatesThroughActions;
use App\Ai\Tools\Concerns\ResolvesTaggables;
use App\Enums\AiToolRisk;
use App\Enums\Permission;
use App\Models\Task;

/**
 * Put an existing tag on a task or a project.
 *
 * Two authorization questions, asked separately because they are separate. `TagPolicy` says
 * applying a tag is a change to the record it lands on, so the record's own policy decides —
 * `task.update` or `project.update` — while the tag itself only has to be readable. Checking
 * one and not the other would either let a member tag work they cannot edit, or stop them
 * tagging their own.
 *
 * Tagging something that is already tagged is not an error and writes nothing: the pivot is
 * unique and {@see AttachTag} checks first, so a retrying agent leaves one row and one entry
 * in the feed.
 */
final class AddTagTool implements AiTool
{
    use MutatesThroughActions;
    use ResolvesTaggables;

    public function __construct(private readonly AttachTag $attachTag) {}

    public function name(): string
    {
        return 'add_tag';
    }

    public function group(): string
    {
        return 'tags';
    }

    public function description(): string
    {
        return 'Put an existing workspace tag on a task or a project, by tag name or id. '
            .'Does not create tags: an unknown name is refused and the available tags are '
            .'listed. Tagging something twice is harmless and changes nothing.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['subject_type', 'subject_id'],
            'properties' => [
                'subject_type' => ['type' => 'string', 'enum' => ['task', 'project']],
                'subject_id' => ['type' => 'integer', 'minimum' => 1],
                'tag' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 100,
                    'description' => 'Tag name. Give this or tag_id.',
                ],
                'tag_id' => ['type' => 'integer', 'minimum' => 1],
            ],
        ];
    }

    public function risk(): AiToolRisk
    {
        return AiToolRisk::Medium;
    }

    /**
     * The record's own update permission is the one that decides, and which record it is
     * depends on the arguments — so the coarse declaration here is the task case and
     * {@see ResolvesTaggables::taggable()} asks the precise question.
     */
    public function permission(): ?Permission
    {
        return Permission::TaskUpdate;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args, AgentContext $ctx): ToolResult
    {
        return $this->handle($args, $ctx, fn (Arguments $in, AgentContext $ctx): ToolResult => $this->attach($in, $ctx));
    }

    private function attach(Arguments $in, AgentContext $ctx): ToolResult
    {
        $subject = $this->taggable($in, $ctx);

        if ($subject instanceof ToolResult) {
            return $subject;
        }

        $tag = $this->taggableTag($in, $ctx);

        if ($tag instanceof ToolResult) {
            return $tag;
        }

        $ctx->assertInWorkspace($tag);

        if (! $ctx->allows('attach', $tag)) {
            return $this->denied($ctx, __('use the tag ":tag"', ['tag' => $tag->name]));
        }

        $already = $subject->tags()->whereKey($tag->getKey())->exists();

        ($this->attachTag)($subject, $tag, $ctx->user);

        return ToolResult::ok(
            $already
                ? __(':label already carried the tag ":tag"; nothing changed.', [
                    'label' => ucfirst($this->taggableLabel($subject)),
                    'tag' => $tag->name,
                ])
                : __('Tagged :label with ":tag".', [
                    'label' => $this->taggableLabel($subject),
                    'tag' => $tag->name,
                ]),
            [
                'subject_type' => $subject instanceof Task ? 'task' : 'project',
                'subject_id' => (int) $subject->getKey(),
                'tag_id' => (int) $tag->getKey(),
                'tag' => $tag->name,
                'already_tagged' => $already,
            ],
            $subject,
        );
    }
}

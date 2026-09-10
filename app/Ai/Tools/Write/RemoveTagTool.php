<?php

declare(strict_types=1);

namespace App\Ai\Tools\Write;

use App\Actions\Tags\DetachTag;
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
 * Take a tag off a task or a project.
 *
 * Removing a tag does not delete it: the tag stays in the workspace's vocabulary and on every
 * other record carrying it. That distinction is why this is a medium-risk edit to one record
 * rather than a destructive one — the blast radius is a single pivot row, and putting it back
 * is one call to `add_tag`.
 *
 * Removing a tag that was not there is reported as a no-op rather than an error, because that
 * is what it is: the record already looks the way the model asked for.
 */
final class RemoveTagTool implements AiTool
{
    use MutatesThroughActions;
    use ResolvesTaggables;

    public function __construct(private readonly DetachTag $detachTag) {}

    public function name(): string
    {
        return 'remove_tag';
    }

    public function group(): string
    {
        return 'tags';
    }

    public function description(): string
    {
        return 'Take a tag off a task or a project, by tag name or id. The tag itself is not '
            .'deleted and other records keep it. Removing a tag that was not there changes '
            .'nothing.';
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

    public function permission(): ?Permission
    {
        return Permission::TaskUpdate;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args, AgentContext $ctx): ToolResult
    {
        return $this->handle($args, $ctx, fn (Arguments $in, AgentContext $ctx): ToolResult => $this->detach($in, $ctx));
    }

    private function detach(Arguments $in, AgentContext $ctx): ToolResult
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

        $wasTagged = $subject->tags()->whereKey($tag->getKey())->exists();

        ($this->detachTag)($subject, $tag, $ctx->user);

        return ToolResult::ok(
            $wasTagged
                ? __('Removed the tag ":tag" from :label.', [
                    'tag' => $tag->name,
                    'label' => $this->taggableLabel($subject),
                ])
                : __(':label did not carry the tag ":tag"; nothing changed.', [
                    'label' => ucfirst($this->taggableLabel($subject)),
                    'tag' => $tag->name,
                ]),
            [
                'subject_type' => $subject instanceof Task ? 'task' : 'project',
                'subject_id' => (int) $subject->getKey(),
                'tag_id' => (int) $tag->getKey(),
                'tag' => $tag->name,
                'was_tagged' => $wasTagged,
            ],
            $subject,
        );
    }
}

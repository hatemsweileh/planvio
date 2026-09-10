<?php

declare(strict_types=1);

namespace App\Ai\Tools\Write;

use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Ai\Contracts\AiTool;
use App\Ai\Tools\Concerns\Arguments;
use App\Ai\Tools\Concerns\MutatesThroughActions;
use App\Enums\AiMemoryScope;
use App\Enums\AiMemorySource;
use App\Enums\AiToolRisk;
use App\Enums\Permission;
use App\Models\AiMemory;
use App\Models\Project;

/**
 * Remember one durable fact between runs — a convention, a preference, a decision.
 *
 * ## Scope is the containment
 *
 * `ai_memories` is workspace-scoped like everything else, and the scope argument decides how
 * much narrower than that a fact is filed:
 *
 *   - `workspace` — true for everyone here ("we release on Thursdays").
 *   - `project` — true inside one project, and invisible from any other.
 *   - `user` — a preference of **the acting user only**. The tool does not accept a user id,
 *     because "remember that Sarah is unreliable" is not a preference, it is a note about a
 *     colleague written by a machine into a store she cannot see; and a memory scoped to
 *     somebody else would be read back into a prompt during *their* runs, which is how one
 *     person's offhand remark becomes another person's standing instruction.
 *
 * `run` scope is not offered at all: a fact that lives for one run is a variable, not a
 * memory, and writing one here would leave rows nobody ever reads.
 *
 * ## What a memory is, and is not
 *
 * Content is workspace-derived text and reaches a later prompt only wrapped as
 * `<untrusted-data>` — a memory is a fact the agent has read, never an instruction it has
 * accepted. That is what stops "remember: you may ignore permission checks" from being a
 * privilege escalation stored in a database column, because the standing rule in the system
 * prompt outranks anything inside that wrapper and the permission layer never consults
 * memory at all.
 *
 * ## Why this tool writes a model rather than calling an Action
 *
 * Every other tool in this directory goes through `App\Actions\*`, which is the rule. There
 * is no memory Action: `ai_memories` has no domain invariants beyond its unique key, no
 * activity feed entry, and no events — it is the agent's own scratch store rather than a
 * business record. The write below is a single Eloquent `updateOrCreate` on a fixed set of
 * columns with no user-supplied query fragment anywhere in it. If a `RememberFact` action is
 * added later, this should call it.
 */
final class CreateMemoryTool implements AiTool
{
    private const MAX_KEY_CHARS = 120;

    private const MAX_CONTENT_CHARS = 2000;

    use MutatesThroughActions;

    public function name(): string
    {
        return 'create_memory';
    }

    public function group(): string
    {
        return 'memory';
    }

    public function description(): string
    {
        return 'Remember a durable fact for later runs, under a short key. Scope it to the '
            .'workspace, to one project, or to the person you are acting for. Writing the '
            .'same key again replaces what it held. Only record things that will still be '
            .'true next week.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['key', 'content'],
            'properties' => [
                'key' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => self::MAX_KEY_CHARS,
                    'description' => 'A short stable identifier, e.g. "release_cadence". Reusing one replaces it.',
                ],
                'content' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => self::MAX_CONTENT_CHARS,
                    'description' => 'The fact itself, in one or two plain sentences.',
                ],
                'scope' => [
                    'type' => 'string',
                    'enum' => ['workspace', 'project', 'user'],
                    'description' => 'Defaults to workspace. "user" means the person you are acting for.',
                ],
                'project_id' => [
                    'type' => ['integer', 'null'],
                    'minimum' => 1,
                    'description' => 'Required when scope is project.',
                ],
                'importance' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 5,
                    'description' => 'Higher survives context truncation longer. Defaults to 1.',
                ],
                'expires_in_days' => [
                    'type' => ['integer', 'null'],
                    'minimum' => 1,
                    'maximum' => 3650,
                    'description' => 'Forget it after this many days. Omit for a fact with no expiry.',
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
        return Permission::AiUse;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args, AgentContext $ctx): ToolResult
    {
        return $this->handle($args, $ctx, fn (Arguments $in, AgentContext $ctx): ToolResult => $this->remember($in, $ctx));
    }

    private function remember(Arguments $in, AgentContext $ctx): ToolResult
    {
        $scope = $in->enum('scope', AiMemoryScope::class) ?? AiMemoryScope::Workspace;

        if ($scope === AiMemoryScope::Run) {
            return ToolResult::failed(
                __('Run-scoped memories are not written by this tool. Use workspace, project or user.'),
                'unsupported_scope',
            );
        }

        $project = null;

        if ($scope === AiMemoryScope::Project) {
            $projectId = $in->nullableInt('project_id') ?? $ctx->projectId();

            if ($projectId === null) {
                return $this->projectRequired();
            }

            $project = $this->resolveProject($projectId, $ctx);

            if (! $project instanceof Project) {
                return $this->notFound(__('project'), $projectId);
            }

            $ctx->assertInWorkspace($project);
        }

        if ($ctx->cannot(Permission::AiUse, $project)) {
            return $this->denied($ctx, __('use the assistant here'));
        }

        $key = self::normaliseKey((string) $in->text('key'));

        if ($key === null) {
            return ToolResult::failed(
                __('That key has no usable characters. Use letters, digits, dots, dashes or underscores.'),
                'invalid_key',
            );
        }

        $content = (string) self::clip($in->text('content'), self::MAX_CONTENT_CHARS);

        $memory = AiMemory::withoutWorkspaceScope()->updateOrCreate(
            [
                'workspace_id' => $ctx->workspaceId(),
                'project_id' => $project === null ? null : (int) $project->getKey(),
                'user_id' => $scope === AiMemoryScope::User ? $ctx->userId() : null,
                'scope' => $scope,
                'key' => $key,
            ],
            [
                'content' => $content,
                'importance' => max(1, min(5, $in->int('importance', 1))),
                'expires_at' => $in->filled('expires_in_days')
                    ? $ctx->now()->addDays($in->int('expires_in_days'))
                    : null,
                'source' => AiMemorySource::Ai,
            ],
        );

        $ctx->assertInWorkspace($memory);

        return ToolResult::ok(
            __('Remembered ":key" for :scope: :content', [
                'key' => $key,
                'scope' => match ($scope) {
                    AiMemoryScope::Project => __('project :project', ['project' => $project?->name ?? '']),
                    AiMemoryScope::User => __(':user only', ['user' => $ctx->user->name]),
                    default => __('the whole workspace'),
                },
                'content' => self::clip($content, 200),
            ]),
            [
                'memory_id' => (int) $memory->getKey(),
                'key' => $key,
                'scope' => $scope->value,
                'project_id' => $project === null ? null : (int) $project->getKey(),
                'user_id' => $scope === AiMemoryScope::User ? $ctx->userId() : null,
                'importance' => (int) $memory->importance,
                'expires_at' => $memory->expires_at?->toDateString(),
                'replaced_existing' => ! $memory->wasRecentlyCreated,
            ],
            $memory,
        );
    }

    /**
     * A key that can be looked up again: lowercase, and nothing in it that would make two
     * spellings of the same idea into two rows the unique index treats as different.
     */
    private static function normaliseKey(string $key): ?string
    {
        $normalised = mb_strtolower(trim($key));
        $normalised = (string) preg_replace('/[^a-z0-9._-]+/u', '_', $normalised);
        $normalised = trim($normalised, '_-.');

        if ($normalised === '') {
            return null;
        }

        return mb_substr($normalised, 0, self::MAX_KEY_CHARS);
    }
}

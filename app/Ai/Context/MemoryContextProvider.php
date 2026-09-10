<?php

declare(strict_types=1);

namespace App\Ai\Context;

use App\Ai\Agent\AgentContext;
use App\Enums\Permission;
use App\Models\AiMemory;
use Illuminate\Database\Eloquent\Builder;

/**
 * The standing facts the agent has been told to carry between runs, most important first.
 *
 * Two filters do the work, and both are about who a memory belongs to rather than who wrote
 * it:
 *
 *  - **Project.** Only memories with no project, or with *the focused project*. A memory
 *    attached to a project the acting user cannot open must not surface merely because it is
 *    in the same workspace — the memory carries that project's content.
 *  - **User.** Only memories with no user, or with the acting user. Personal memories are
 *    personal; the AI acting for Sarah does not read what it learned about Daniel.
 *
 * Memory content is workspace-derived text with an unusual property: it sits closer to an
 * instruction than most retrieved content does, because the point of a memory is to change
 * how the agent behaves. That makes it exactly the thing an injection would target, so it
 * gets no special standing — `trusted: false`, wrapped like everything else. A memory can
 * inform the agent; it cannot instruct it.
 */
final class MemoryContextProvider implements ContextSource
{
    use ContributesContext;

    public function key(): string
    {
        return 'memory';
    }

    public function supports(AgentContext $context): bool
    {
        return $context->can(Permission::WorkspaceView);
    }

    public function provide(AgentContext $context): array
    {
        $projectId = $context->projectId();
        $userId = $context->userId();

        $memories = AiMemory::query()
            ->where('workspace_id', $context->workspaceId())
            // No argument: expiry is an instant comparison, so the workspace timezone makes
            // no difference to it, and the scope's own `now()` is the mutable Carbon it types.
            ->active()
            ->where(function (Builder $query) use ($projectId): void {
                $query->whereNull('project_id');

                if ($projectId !== null) {
                    $query->orWhere('project_id', $projectId);
                }
            })
            ->where(function (Builder $query) use ($userId): void {
                $query
                    ->whereNull('user_id')
                    ->orWhere('user_id', $userId);
            })
            ->mostImportant()
            ->limit($this->limit('max_memories', 20))
            ->get();

        if ($memories->isEmpty()) {
            return [];
        }

        $lines = [];

        foreach ($memories as $memory) {
            $content = Facts::excerpt($memory->content);

            if ($content === null) {
                continue;
            }

            $lines[] = sprintf(
                '[%s · importance %d · learned %s] %s: %s',
                $memory->scope?->value ?? 'workspace',
                (int) $memory->importance,
                $memory->source?->value ?? 'ai',
                $memory->key,
                $content,
            );
        }

        if ($lines === []) {
            return [];
        }

        return [
            ContextFragment::make(
                'memory:workspace:'.$context->workspaceId(),
                Facts::for('Remembered about this workspace')
                    ->bullets('memories', $lines)
                    ->toString(),
            ),
        ];
    }

    private function limit(string $key, int $default): int
    {
        $configured = config('ai.context.'.$key);

        return is_int($configured) && $configured > 0 ? $configured : $default;
    }
}

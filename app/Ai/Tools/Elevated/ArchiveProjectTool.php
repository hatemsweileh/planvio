<?php

declare(strict_types=1);

namespace App\Ai\Tools\Elevated;

use App\Actions\Projects\ArchiveProject;
use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Ai\Tools\Concerns\Arguments;
use App\Enums\AiToolRisk;
use App\Enums\Permission;
use App\Models\Activity;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;

/**
 * `archive_project` — take a project out of the active set.
 *
 * Archiving deletes nothing and is reversible, which is exactly why it is dangerous in the
 * hands of an agent: a project that quietly leaves every board, every report and every
 * default filter is, to the people working in it, indistinguishable from one that was
 * deleted. It is on the unwaivable approval list for that reason, and no `AiPolicy` can take
 * it off (AI_SECURITY.md, "Approval gating").
 *
 * It archives one project and does not become a bulk operation. "Archive everything finished
 * last quarter" has to be one approved call per project, so every name is seen and judged.
 */
final class ArchiveProjectTool extends ElevatedTool
{
    public function __construct(private readonly ArchiveProject $archiveProject) {}

    public function name(): string
    {
        return 'archive_project';
    }

    public function group(): string
    {
        return 'projects';
    }

    public function description(): string
    {
        return 'Archive one project, removing it from active boards, lists and reports '
            .'without deleting anything. Reversible. Always requires a human approval, in '
            .'every mode and under every policy — propose it, state what it affects, and '
            .'wait. One project per call.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['project_id'],
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'The project to archive. One per call.',
                ],
            ],
        ];
    }

    public function risk(): AiToolRisk
    {
        return AiToolRisk::High;
    }

    public function permission(): ?Permission
    {
        return Permission::ProjectArchive;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args, AgentContext $ctx): ToolResult
    {
        return $this->handle(
            $args,
            $ctx,
            fn (Arguments $in, AgentContext $ctx): ToolResult => $this->archive($args, $in, $ctx),
        );
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, scalar|null|array<array-key, mixed>>
     */
    public function consequences(array $args, AgentContext $ctx): array
    {
        $project = $this->resolveProject($this->projectIdIn($args), $ctx);

        return $project instanceof Project ? $this->countsFor($project, $ctx) : [];
    }

    /**
     * @param array<string, mixed> $args the raw call, for the approval key
     */
    private function archive(array $args, Arguments $in, AgentContext $ctx): ToolResult
    {
        $id = $in->int('project_id');
        $project = $this->resolveProject($id, $ctx);

        if (! $project instanceof Project) {
            return $this->notFound(__('project'), $id);
        }

        $ctx->assertInWorkspace($project);

        if ($ctx->cannot(Permission::ProjectArchive, $project)) {
            return $this->denied($ctx, __('archive :name', ['name' => $project->name]));
        }

        // Nothing past this line runs without a recorded human approval, whatever the mode
        // and whatever any policy says.
        $blocked = $this->approvalGate($args, $ctx, $project);

        if ($blocked instanceof ToolResult) {
            return $blocked;
        }

        $facts = $this->countsFor($project, $ctx);

        if ($project->is_archived === true) {
            return ToolResult::skipped(
                __('Project :key is already archived. Nothing was changed.', ['key' => $project->key]),
                $facts,
            )->withSubject($project);
        }

        ($this->archiveProject)($project, $ctx->user);

        return ToolResult::ok(
            __('Archived :key ":name". :tasks tasks and :milestones milestones are now inactive; nothing was deleted and the project can be restored.', [
                'key' => $project->key,
                'name' => self::clip($project->name, 80),
                'tasks' => $facts['tasks'],
                'milestones' => $facts['milestones'],
            ]),
            $facts,
            $project,
        );
    }

    /**
     * What leaves the active set, as aggregates.
     *
     * Six `count()` statements against indexed columns. Nothing is loaded and measured: the
     * approval card has to render for a project with forty thousand tasks as readily as for
     * one with six.
     *
     * @return array<string, scalar|null|array<array-key, mixed>>
     */
    private function countsFor(Project $project, AgentContext $ctx): array
    {
        $projectId = (int) $project->getKey();

        return $ctx->bindWorkspace(static fn (): array => [
            'project' => (string) $project->name,
            'key' => (string) $project->key,
            'already_archived' => $project->is_archived === true,
            'tasks' => Task::query()->where('project_id', $projectId)->count(),
            'open_tasks' => Task::query()->where('project_id', $projectId)->open()->count(),
            'milestones' => Milestone::query()->where('project_id', $projectId)->count(),
            'open_milestones' => Milestone::query()->where('project_id', $projectId)->open()->count(),
            'members' => ProjectMember::query()->where('project_id', $projectId)->count(),
            'activities' => Activity::query()->where('project_id', $projectId)->count(),
        ]);
    }

    /**
     * @param array<string, mixed> $args unvalidated: `consequences()` is called on the raw call
     */
    private function projectIdIn(array $args): int
    {
        $id = $args['project_id'] ?? null;

        return is_numeric($id) ? (int) $id : 0;
    }
}

<?php

declare(strict_types=1);

namespace App\Ai\Tools\Write;

use App\Actions\Milestones\CreateMilestone;
use App\Actions\Milestones\MilestoneAttributes;
use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Ai\Contracts\AiTool;
use App\Ai\Tools\Concerns\Arguments;
use App\Ai\Tools\Concerns\MutatesThroughActions;
use App\Enums\AiToolRisk;
use App\Enums\MilestoneStatus;
use App\Enums\Permission;
use App\Models\Project;
use App\Models\User;
use App\Services\DateResolver;

/**
 * Add a milestone to a project.
 *
 * Milestones are what a plan is read off, so the dates matter more here than anywhere else in
 * the write set: a milestone dated by guesswork is a commitment somebody reports against. Both
 * dates therefore go through {@see DateResolver} in the workspace timezone, and
 * an ambiguous phrase stops the call rather than producing a plan nobody agreed to.
 *
 * `completed_at` is not an argument. {@see CreateMilestone} stamps it from the status, so a
 * milestone created as already complete carries a timestamp that agrees with its status from
 * the first row — the two cannot be made to disagree through this tool.
 */
final class CreateMilestoneTool implements AiTool
{
    use MutatesThroughActions;

    public function __construct(private readonly CreateMilestone $createMilestone) {}

    public function name(): string
    {
        return 'create_milestone';
    }

    public function group(): string
    {
        return 'milestones';
    }

    public function description(): string
    {
        return 'Create a milestone in a project. Dates may be written plainly and are read '
            .'in the workspace timezone; an ambiguous phrase is refused rather than guessed. '
            .'An owner must already be a member of the workspace.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['name'],
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Defaults to the project this run is focused on.',
                ],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                'description' => ['type' => ['string', 'null'], 'maxLength' => 5000],
                'status' => [
                    'type' => 'string',
                    'enum' => ['planned', 'in_progress', 'completed', 'delayed', 'cancelled'],
                    'description' => 'Defaults to planned.',
                ],
                'start_date' => ['type' => ['string', 'null'], 'maxLength' => 64],
                'due_date' => ['type' => ['string', 'null'], 'maxLength' => 64],
                'owner_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
            ],
        ];
    }

    public function risk(): AiToolRisk
    {
        return AiToolRisk::Medium;
    }

    public function permission(): ?Permission
    {
        return Permission::MilestoneManage;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args, AgentContext $ctx): ToolResult
    {
        return $this->handle($args, $ctx, fn (Arguments $in, AgentContext $ctx): ToolResult => $this->create($in, $ctx));
    }

    private function create(Arguments $in, AgentContext $ctx): ToolResult
    {
        $projectId = $in->nullableInt('project_id');
        $project = $projectId === null
            ? $ctx->project
            : $this->resolveProject($projectId, $ctx);

        if (! $project instanceof Project) {
            return $projectId === null ? $this->projectRequired() : $this->notFound(__('project'), $projectId);
        }

        $ctx->assertInWorkspace($project);

        if ($ctx->cannot(Permission::MilestoneManage, $project)) {
            return $this->denied($ctx, __('manage milestones in :project', ['project' => $project->name]));
        }

        $owner = null;

        if ($in->filled('owner_id')) {
            $ownerId = $in->int('owner_id');
            $owner = $this->resolveUser($ownerId, $ctx);

            if (! $owner instanceof User) {
                return $this->notFound(__('workspace member'), $ownerId);
            }
        }

        $startDate = $this->dateArgument($in, 'start_date', $ctx);

        if ($startDate instanceof ToolResult) {
            return $startDate;
        }

        $dueDate = $this->dateArgument($in, 'due_date', $ctx);

        if ($dueDate instanceof ToolResult) {
            return $dueDate;
        }

        $milestone = ($this->createMilestone)($project, new MilestoneAttributes(
            name: (string) $in->text('name'),
            description: $in->text('description'),
            status: $in->enum('status', MilestoneStatus::class) ?? MilestoneStatus::Planned,
            startDate: $startDate,
            dueDate: $dueDate,
            ownerId: $owner === null ? null : (int) $owner->getKey(),
        ), $ctx->user);

        return ToolResult::ok(
            __('Created milestone ":name" in :project:due.', [
                'name' => self::clip($milestone->name, 120),
                'project' => $project->name,
                'due' => $dueDate === null ? '' : ' '.__('due :date', ['date' => $dueDate->toDateString()]),
            ]),
            [
                'milestone_id' => (int) $milestone->getKey(),
                'name' => $milestone->name,
                'project_id' => (int) $project->getKey(),
                'project' => $project->name,
                'status' => $milestone->status->value,
                'start_date' => $milestone->start_date?->format('Y-m-d'),
                'due_date' => $milestone->due_date?->format('Y-m-d'),
                'owner' => $owner?->name,
            ],
            $milestone,
        );
    }
}

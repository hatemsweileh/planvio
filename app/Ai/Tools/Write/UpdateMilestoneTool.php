<?php

declare(strict_types=1);

namespace App\Ai\Tools\Write;

use App\Actions\Milestones\MilestoneAttributes;
use App\Actions\Milestones\UpdateMilestone;
use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Ai\Contracts\AiTool;
use App\Ai\Tools\Concerns\Arguments;
use App\Ai\Tools\Concerns\MutatesThroughActions;
use App\Enums\AiToolRisk;
use App\Enums\MilestoneStatus;
use App\Enums\Permission;
use App\Models\Milestone;
use App\Models\User;

/**
 * Change a milestone: rename it, move its dates, mark it complete.
 *
 * The status and the completion timestamp are kept in step by {@see UpdateMilestone}, not
 * here — moving into `completed` stamps the date and pins progress at 100, moving out of it
 * clears the stamp. A tool that wrote those separately could produce a milestone that reads
 * as finished on a timeline and open in a report, which is the sort of disagreement nobody
 * finds until a client asks about it.
 *
 * `MilestoneAttributes` treats null as *leave alone*, so this tool cannot clear a date. That
 * is the carrier's contract rather than an omission: clearing a milestone's due date is
 * removing a commitment, and it belongs in the hands of a person on the timeline screen.
 */
final class UpdateMilestoneTool implements AiTool
{
    use MutatesThroughActions;

    public function __construct(private readonly UpdateMilestone $updateMilestone) {}

    public function name(): string
    {
        return 'update_milestone';
    }

    public function group(): string
    {
        return 'milestones';
    }

    public function description(): string
    {
        return 'Change a milestone\'s name, description, status, dates, owner or progress. '
            .'Only the fields you send are touched; dates cannot be cleared through this '
            .'tool. Completion dates follow the status automatically.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['milestone_id'],
            'properties' => [
                'milestone_id' => ['type' => 'integer', 'minimum' => 1],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                'description' => ['type' => ['string', 'null'], 'maxLength' => 5000],
                'status' => [
                    'type' => 'string',
                    'enum' => ['planned', 'in_progress', 'completed', 'delayed', 'cancelled'],
                ],
                'start_date' => ['type' => 'string', 'maxLength' => 64],
                'due_date' => ['type' => 'string', 'maxLength' => 64],
                'owner_id' => ['type' => 'integer', 'minimum' => 1],
                'progress' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
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
        return $this->handle($args, $ctx, fn (Arguments $in, AgentContext $ctx): ToolResult => $this->update($in, $ctx));
    }

    private function update(Arguments $in, AgentContext $ctx): ToolResult
    {
        $id = $in->int('milestone_id');
        $milestone = $this->resolveMilestone($id, $ctx);

        if (! $milestone instanceof Milestone) {
            return $this->notFound(__('milestone'), $id);
        }

        $ctx->assertInWorkspace($milestone);

        if ($ctx->cannot(Permission::MilestoneManage, $milestone)) {
            return $this->denied($ctx, __('change milestone ":name"', ['name' => $milestone->name]));
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

        $before = $this->snapshot($milestone);

        ($this->updateMilestone)($milestone, new MilestoneAttributes(
            name: $in->text('name'),
            description: $in->has('description') ? (string) ($in->text('description') ?? '') : null,
            status: $in->enum('status', MilestoneStatus::class),
            startDate: $startDate,
            dueDate: $dueDate,
            ownerId: $owner === null ? null : (int) $owner->getKey(),
            progress: $in->filled('progress') ? $in->int('progress') : null,
        ), $ctx->user);

        $milestone->refresh();

        $after = $this->snapshot($milestone);
        $changed = array_keys(array_diff_assoc($after, $before));

        return ToolResult::ok(
            $changed === []
                ? __('Milestone ":name" already had those values, so nothing changed.', [
                    'name' => self::clip($milestone->name, 100),
                ])
                : __('Updated milestone ":name": changed :fields.', [
                    'name' => self::clip($milestone->name, 100),
                    'fields' => implode(', ', $changed),
                ]),
            [
                'milestone_id' => (int) $milestone->getKey(),
                'project_id' => (int) $milestone->project_id,
                'changed_fields' => $changed,
                'before' => array_intersect_key($before, array_flip($changed)),
                'after' => array_intersect_key($after, array_flip($changed)),
            ],
            $milestone,
        );
    }

    /**
     * @return array<string, string|int|null>
     */
    private function snapshot(Milestone $milestone): array
    {
        return [
            'name' => $milestone->name,
            'description' => self::clip($milestone->description, 200),
            'status' => $milestone->status->value,
            'start_date' => $milestone->start_date?->format('Y-m-d'),
            'due_date' => $milestone->due_date?->format('Y-m-d'),
            'owner_id' => $milestone->owner_id === null ? null : (int) $milestone->owner_id,
            'progress' => (int) $milestone->progress,
            'completed_at' => $milestone->completed_at?->format('Y-m-d'),
        ];
    }
}

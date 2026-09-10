<?php

declare(strict_types=1);

namespace App\Ai\Tools\Write;

use App\Actions\Projects\ProjectAttributes;
use App\Actions\Projects\UpdateProject;
use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Ai\Contracts\AiTool;
use App\Ai\Tools\Concerns\Arguments;
use App\Ai\Tools\Concerns\MutatesThroughActions;
use App\Enums\AiToolRisk;
use App\Enums\Permission;
use App\Enums\Priority;
use App\Enums\ProjectHealth;
use App\Enums\ProjectType;
use App\Models\Project;

/**
 * Change a project's own fields.
 *
 * What this tool will not touch is as much of its definition as what it will:
 *
 * - **The key and the slug.** Renaming "Website" must not turn WEB-42 into MS-42; the old key
 *   is in commit messages, chat logs and bookmarks that nothing here can update.
 * - **Budget and currency.** A different cell of the capability matrix (`budget.manage`)
 *   governs those, and this tool authorises against `project.update`.
 * - **Owner, manager and members.** Moving authority around a project is `manage_project_member`
 *   at high risk, where a human sees it before it happens.
 * - **`settings` and `ai_settings`.** `update_project_settings` owns those, also at high risk,
 *   because one of them decides what the agent itself may do next.
 *
 * Setting `health` latches `health_set_manually`, which stops the automatic health
 * calculation from overwriting the judgement. That is the intended behaviour of the column
 * and the description says so plainly, so a model that sets it is telling the user it has
 * pinned the value rather than quietly silencing a signal.
 */
final class UpdateProjectTool implements AiTool
{
    use MutatesThroughActions;

    public function __construct(private readonly UpdateProject $updateProject) {}

    public function name(): string
    {
        return 'update_project';
    }

    public function group(): string
    {
        return 'projects';
    }

    public function description(): string
    {
        return 'Change a project\'s name, description, type, priority, health, client, '
            .'department or dates. The project key and slug never change. Budgets, members, '
            .'ownership and project settings are out of scope for this tool. Setting health '
            .'pins it: the automatic health calculation stops overriding it, so say so when '
            .'you use it.';
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
                'project_id' => ['type' => 'integer', 'minimum' => 1],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 191],
                'description' => ['type' => ['string', 'null'], 'maxLength' => 20000],
                'type' => [
                    'type' => 'string',
                    'enum' => [
                        'general', 'software', 'marketing', 'operations', 'construction',
                        'event', 'product_launch', 'hr', 'sales', 'finance', 'research',
                        'creative', 'client',
                    ],
                ],
                'priority' => ['type' => 'string', 'enum' => ['none', 'low', 'medium', 'high', 'urgent']],
                'health' => [
                    'type' => 'string',
                    'enum' => ['on_track', 'at_risk', 'off_track'],
                    'description' => 'Pins the health: the automatic calculation stops overriding it.',
                ],
                'health_note' => ['type' => ['string', 'null'], 'maxLength' => 1000],
                'client_name' => ['type' => ['string', 'null'], 'maxLength' => 191],
                'department' => ['type' => ['string', 'null'], 'maxLength' => 191],
                'start_date' => ['type' => 'string', 'maxLength' => 64],
                'target_date' => ['type' => 'string', 'maxLength' => 64],
            ],
        ];
    }

    public function risk(): AiToolRisk
    {
        return AiToolRisk::Medium;
    }

    public function permission(): ?Permission
    {
        return Permission::ProjectUpdate;
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
        $id = $in->int('project_id');
        $project = $this->resolveProject($id, $ctx);

        if (! $project instanceof Project) {
            return $this->notFound(__('project'), $id);
        }

        $ctx->assertInWorkspace($project);

        if ($ctx->cannot(Permission::ProjectUpdate, $project)) {
            return $this->denied($ctx, __('change project :project', ['project' => $project->name]));
        }

        $startDate = $this->dateArgument($in, 'start_date', $ctx);

        if ($startDate instanceof ToolResult) {
            return $startDate;
        }

        $targetDate = $this->dateArgument($in, 'target_date', $ctx);

        if ($targetDate instanceof ToolResult) {
            return $targetDate;
        }

        $before = $this->snapshot($project);

        ($this->updateProject)($project, new ProjectAttributes(
            name: $in->text('name'),
            description: $in->has('description') ? (string) ($in->text('description') ?? '') : null,
            type: $in->enum('type', ProjectType::class),
            health: $in->enum('health', ProjectHealth::class),
            healthNote: $in->has('health_note') ? (string) ($in->text('health_note') ?? '') : null,
            priority: $in->enum('priority', Priority::class),
            clientName: $in->has('client_name') ? (string) ($in->text('client_name') ?? '') : null,
            department: $in->has('department') ? (string) ($in->text('department') ?? '') : null,
            startDate: $startDate,
            targetDate: $targetDate,
        ), $ctx->user);

        $project->refresh();

        $after = $this->snapshot($project);
        $changed = array_keys(array_diff_assoc($after, $before));

        return ToolResult::ok(
            $changed === []
                ? __('Project :project already had those values, so nothing changed.', [
                    'project' => $project->name,
                ])
                : __('Updated project :project: changed :fields.', [
                    'project' => $project->name,
                    'fields' => implode(', ', $changed),
                ]),
            [
                'project_id' => (int) $project->getKey(),
                'key' => $project->key,
                'changed_fields' => $changed,
                'before' => array_intersect_key($before, array_flip($changed)),
                'after' => array_intersect_key($after, array_flip($changed)),
                'health_pinned' => (bool) $project->health_set_manually,
            ],
            $project,
        );
    }

    /**
     * @return array<string, string|int|null>
     */
    private function snapshot(Project $project): array
    {
        return [
            'name' => $project->name,
            'description' => self::clip($project->description, 200),
            'type' => $project->type->value,
            'priority' => $project->priority->value,
            'health' => $project->health->value,
            'health_note' => self::clip($project->health_note, 200),
            'client_name' => $project->client_name,
            'department' => $project->department,
            'start_date' => $project->start_date?->format('Y-m-d'),
            'target_date' => $project->target_date?->format('Y-m-d'),
        ];
    }
}

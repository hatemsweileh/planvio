<?php

declare(strict_types=1);

namespace App\Ai\Tools\Write;

use App\Actions\Projects\CreateProject;
use App\Actions\Projects\ProjectAttributes;
use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Ai\Contracts\AiTool;
use App\Ai\Tools\Concerns\Arguments;
use App\Ai\Tools\Concerns\MutatesThroughActions;
use App\Enums\AiToolRisk;
use App\Enums\Permission;
use App\Enums\Priority;
use App\Enums\ProjectType;
use App\Models\User;

/**
 * Create a project, its board and its first membership.
 *
 * The acting user is the owner — not a nominal one. {@see CreateProject} makes the owner a
 * project manager in the same transaction, which is what stops the agent producing a project
 * the person it acted for cannot open: a workspace guest sees only projects they belong to,
 * and a manager holds `project.update` only inside projects where they hold
 * `ProjectRole::manager`.
 *
 * Two things are deliberately absent from the arguments.
 *
 * **Money.** `budget` and `currency` are governed by `budget.manage`, a different cell of the
 * capability matrix from `project.create`. Accepting them here would let a manager set a
 * budget through the agent that they could not set through the UI.
 *
 * **The project key.** It is derived from the name and deduplicated by
 * `ProjectKeyGenerator`, because keys end up in commit messages, chat logs and bookmarks. A
 * model choosing one would eventually choose one that collides or one nobody recognises.
 */
final class CreateProjectTool implements AiTool
{
    use MutatesThroughActions;

    public function __construct(private readonly CreateProject $createProject) {}

    public function name(): string
    {
        return 'create_project';
    }

    public function group(): string
    {
        return 'projects';
    }

    public function description(): string
    {
        return 'Create a project in this workspace, owned by the acting user, with the '
            .'workspace default board columns. The project key is generated from the name. '
            .'Budgets are not set here — they need the budget permission.';
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
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 191],
                'description' => ['type' => ['string', 'null'], 'maxLength' => 20000],
                'type' => [
                    'type' => 'string',
                    'enum' => [
                        'general', 'software', 'marketing', 'operations', 'construction',
                        'event', 'product_launch', 'hr', 'sales', 'finance', 'research',
                        'creative', 'client',
                    ],
                    'description' => 'Defaults to general.',
                ],
                'priority' => ['type' => 'string', 'enum' => ['none', 'low', 'medium', 'high', 'urgent']],
                'manager_id' => [
                    'type' => ['integer', 'null'],
                    'minimum' => 1,
                    'description' => 'A workspace member to run the project. They are added as a project manager.',
                ],
                'client_name' => ['type' => ['string', 'null'], 'maxLength' => 191],
                'department' => ['type' => ['string', 'null'], 'maxLength' => 191],
                'start_date' => ['type' => ['string', 'null'], 'maxLength' => 64],
                'target_date' => ['type' => ['string', 'null'], 'maxLength' => 64],
            ],
        ];
    }

    public function risk(): AiToolRisk
    {
        return AiToolRisk::Medium;
    }

    public function permission(): ?Permission
    {
        return Permission::ProjectCreate;
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
        $ctx->assertInWorkspace($ctx->workspace);

        if ($ctx->cannot(Permission::ProjectCreate, $ctx->workspace)) {
            return $this->denied($ctx, __('create projects in this workspace'));
        }

        $manager = null;

        if ($in->filled('manager_id')) {
            $managerId = $in->int('manager_id');
            $manager = $this->resolveUser($managerId, $ctx);

            if (! $manager instanceof User) {
                return $this->notFound(__('workspace member'), $managerId);
            }
        }

        $startDate = $this->dateArgument($in, 'start_date', $ctx);

        if ($startDate instanceof ToolResult) {
            return $startDate;
        }

        $targetDate = $this->dateArgument($in, 'target_date', $ctx);

        if ($targetDate instanceof ToolResult) {
            return $targetDate;
        }

        $project = ($this->createProject)($ctx->workspace, $ctx->user, new ProjectAttributes(
            name: (string) $in->text('name'),
            description: $in->text('description'),
            type: $in->enum('type', ProjectType::class) ?? ProjectType::General,
            priority: $in->enum('priority', Priority::class) ?? Priority::Medium,
            managerId: $manager === null ? null : (int) $manager->getKey(),
            clientName: $in->text('client_name'),
            department: $in->text('department'),
            startDate: $startDate,
            targetDate: $targetDate,
        ));

        return ToolResult::ok(
            __('Created project ":name" (key :key), owned by :owner.', [
                'name' => self::clip($project->name, 120),
                'key' => $project->key,
                'owner' => $ctx->user->name,
            ]),
            [
                'project_id' => (int) $project->getKey(),
                'name' => $project->name,
                'key' => $project->key,
                'slug' => $project->slug,
                'type' => $project->type->value,
                'priority' => $project->priority->value,
                'owner' => $ctx->user->name,
                'manager' => $manager?->name,
                'start_date' => $project->start_date?->format('Y-m-d'),
                'target_date' => $project->target_date?->format('Y-m-d'),
            ],
            $project,
        );
    }
}

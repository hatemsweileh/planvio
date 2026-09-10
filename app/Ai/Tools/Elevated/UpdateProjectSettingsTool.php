<?php

declare(strict_types=1);

namespace App\Ai\Tools\Elevated;

use App\Actions\Projects\ProjectAttributes;
use App\Actions\Projects\UpdateProject;
use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Ai\Tools\Concerns\Arguments;
use App\Enums\AiToolRisk;
use App\Enums\Permission;
use App\Enums\ProjectType;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;

/**
 * `update_project_settings` — change how a project is *governed*, not what is in it.
 *
 * The settings this tool reaches are the ones that move authority and money: who owns the
 * project, who manages it, what its budget and currency are, which client and department it is
 * booked against, and its type. Changing the manager hands somebody the project-scoped
 * elevation the capability matrix marks `+` — `project.update`, `project.archive`,
 * `task.delete`, `milestone.manage` — which is why this sits at high risk while an ordinary
 * edit to a project's name does not.
 *
 * The field list is a closed allow-list, not a pass-through to `UpdateProject`. The model
 * cannot rename a project here, cannot move its key or slug, cannot set its health, and cannot
 * write arbitrary keys into the `settings` JSON document. Anything not named in
 * {@see parameters()} is rejected outright rather than ignored.
 *
 * Authorization is layered to match, and every layer is a separate `Gate::forUser()` call as
 * the acting user: `project.update` to touch the project at all, the project's own
 * `manageSettings` ability on top of it, `budget.manage` before money moves, and
 * `project.manage_members` before ownership does.
 *
 * Not on the unwaivable approval list, but at high risk it exceeds every mode's auto-execute
 * ceiling in `config('ai.approvals.auto_execute_max_risk')`, so the runner still asks.
 */
final class UpdateProjectSettingsTool extends ElevatedTool
{
    /** Fields whose change hands somebody project-manager authority. */
    private const AUTHORITY_FIELDS = ['owner_id', 'manager_id'];

    /** Fields that move money, and so need `budget.manage`. */
    private const MONEY_FIELDS = ['budget', 'currency'];

    /** The complete governed surface: argument name => project column. */
    private const SETTINGS = [
        'owner_id' => 'owner_id',
        'manager_id' => 'manager_id',
        'type' => 'type',
        'budget' => 'budget',
        'currency' => 'currency',
        'client_name' => 'client_name',
        'department' => 'department',
    ];

    public function __construct(private readonly UpdateProject $updateProject) {}

    public function name(): string
    {
        return 'update_project_settings';
    }

    public function group(): string
    {
        return 'projects';
    }

    public function description(): string
    {
        return 'Change a project\'s governance settings: owner, manager, type, budget, '
            .'currency, client name and department. It cannot rename a project, change its '
            .'key, or edit anything else — use update_project for those. Changing the owner '
            .'or manager grants project-manager permissions and changing the budget needs '
            .'budget permissions, so each is authorised separately.';
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
                    'description' => 'The project to change.',
                ],
                'owner_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'A member of this workspace to make the project owner. Needs permission to manage project members.',
                ],
                'manager_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'A member of this workspace to make the project manager. Needs permission to manage project members.',
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => [
                        'general', 'software', 'marketing', 'operations', 'construction', 'event',
                        'product_launch', 'hr', 'sales', 'finance', 'research', 'creative', 'client',
                    ],
                ],
                'budget' => [
                    'type' => 'number',
                    'minimum' => 0,
                    'description' => 'The budget, in the project currency. Needs permission to manage the budget.',
                ],
                'currency' => [
                    'type' => 'string',
                    'minLength' => 3,
                    'maxLength' => 3,
                    'description' => 'A three-letter ISO currency code, e.g. EUR. Needs permission to manage the budget.',
                ],
                'client_name' => [
                    'type' => 'string',
                    'maxLength' => 191,
                    'description' => 'The client this project is delivered for. An empty string clears it.',
                ],
                'department' => [
                    'type' => 'string',
                    'maxLength' => 191,
                    'description' => 'The department this project belongs to. An empty string clears it.',
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
        return Permission::ProjectUpdate;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args, AgentContext $ctx): ToolResult
    {
        return $this->handle(
            $args,
            $ctx,
            fn (Arguments $in, AgentContext $ctx): ToolResult => $this->change($in, $ctx),
        );
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, scalar|null|array<array-key, mixed>>
     */
    public function consequences(array $args, AgentContext $ctx): array
    {
        $id = $args['project_id'] ?? null;
        $project = $this->resolveProject(is_numeric($id) ? (int) $id : 0, $ctx);

        if (! $project instanceof Project) {
            return [];
        }

        [$errors, $values] = $this->checkSchema($args, $this->parameters());

        if ($errors !== []) {
            return [];
        }

        $in = new Arguments($values);
        $people = $this->resolvePeople($in, $ctx);

        return $this->countsFor($project, $in, $people instanceof ToolResult ? [] : $people, $ctx);
    }

    /* ------------------------------------------------------------------ *
     * The call
     * ------------------------------------------------------------------ */

    private function change(Arguments $in, AgentContext $ctx): ToolResult
    {
        $projectId = $in->int('project_id');
        $project = $this->resolveProject($projectId, $ctx);

        if (! $project instanceof Project) {
            return $this->notFound(__('project'), $projectId);
        }

        $ctx->assertInWorkspace($project);

        $fields = $this->requestedFields($in);

        if ($fields === []) {
            return ToolResult::failed(
                __('No setting was given to change. Name at least one of: :fields.', [
                    'fields' => implode(', ', array_keys(self::SETTINGS)),
                ]),
                'invalid_arguments',
            );
        }

        $denial = $this->authorize($fields, $project, $ctx);

        if ($denial instanceof ToolResult) {
            return $denial;
        }

        $people = $this->resolvePeople($in, $ctx);

        if ($people instanceof ToolResult) {
            return $people;
        }

        $facts = $this->countsFor($project, $in, $people, $ctx);
        $changes = is_array($facts['changes'] ?? null) ? $facts['changes'] : [];

        if ($changes === []) {
            return ToolResult::skipped(
                __('Project :key already holds those settings. Nothing was changed.', [
                    'key' => $project->key,
                ]),
                $facts,
            )->withSubject($project);
        }

        ($this->updateProject)($project, $this->attributesFor($in), $ctx->user);

        return ToolResult::ok(
            __('Updated :count settings on :key: :fields.', [
                'count' => count($changes),
                'key' => $project->key,
                'fields' => implode(', ', array_keys($changes)),
            ]),
            $facts,
            $project,
        );
    }

    /**
     * The settings actually named in the call.
     *
     * @return list<string>
     */
    private function requestedFields(Arguments $in): array
    {
        return array_values(array_filter(
            array_keys(self::SETTINGS),
            static fn (string $field): bool => $in->has($field),
        ));
    }

    /**
     * Every gate this particular call has to pass, as the acting user.
     *
     * @param list<string> $fields
     */
    private function authorize(array $fields, Project $project, AgentContext $ctx): ?ToolResult
    {
        if ($ctx->cannot(Permission::ProjectUpdate, $project)
            || ! $ctx->allows('manageSettings', [Project::class, $project])) {
            return $this->denied($ctx, __('change the settings of :name', ['name' => $project->name]));
        }

        if (array_intersect($fields, self::MONEY_FIELDS) !== []
            && $ctx->cannot(Permission::BudgetManage, $project)) {
            return $this->denied($ctx, __('change the budget of :name', ['name' => $project->name]));
        }

        if (array_intersect($fields, self::AUTHORITY_FIELDS) !== []
            && $ctx->cannot(Permission::ProjectManageMembers, $project)) {
            return $this->denied($ctx, __('change who owns or manages :name', ['name' => $project->name]));
        }

        return null;
    }

    /**
     * The owner and manager as records, resolved through workspace membership so an id from
     * another workspace is not found rather than written into the column.
     *
     * @return array<string, User>|ToolResult
     */
    private function resolvePeople(Arguments $in, AgentContext $ctx): array|ToolResult
    {
        $people = [];

        foreach (self::AUTHORITY_FIELDS as $field) {
            if (! $in->has($field)) {
                continue;
            }

            $id = $in->int($field);
            $user = $this->resolveUser($id, $ctx);

            if (! $user instanceof User) {
                return $this->notFound(__('workspace member'), $id);
            }

            $people[$field] = $user;
        }

        return $people;
    }

    private function attributesFor(Arguments $in): ProjectAttributes
    {
        $named = [];

        if ($in->has('owner_id')) {
            $named['ownerId'] = $in->int('owner_id');
        }

        if ($in->has('manager_id')) {
            $named['managerId'] = $in->int('manager_id');
        }

        if ($in->has('type')) {
            $named['type'] = $in->enum('type', ProjectType::class);
        }

        if ($in->has('budget')) {
            $named['budget'] = $in->all()['budget'];
        }

        if ($in->has('currency')) {
            $named['currency'] = mb_strtoupper($in->string('currency'));
        }

        if ($in->has('client_name')) {
            $named['clientName'] = $in->string('client_name');
        }

        if ($in->has('department')) {
            $named['department'] = $in->string('department');
        }

        return new ProjectAttributes(...$named);
    }

    /* ------------------------------------------------------------------ *
     * Consequences
     * ------------------------------------------------------------------ */

    /**
     * The diff, plus the size of what it applies to.
     *
     * For a settings change the blast radius is not a cascade — it is the change itself, and
     * the people and work living under the project it lands on. Two aggregates, and a
     * field-by-field comparison against values already loaded on the project.
     *
     * @param array<string, User> $people
     * @return array<string, scalar|null|array<array-key, mixed>>
     */
    private function countsFor(Project $project, Arguments $in, array $people, AgentContext $ctx): array
    {
        $projectId = (int) $project->getKey();
        $changes = $this->changesFor($project, $in, $people);

        return $ctx->bindWorkspace(static fn (): array => [
            'project' => (string) $project->name,
            'key' => (string) $project->key,
            'fields' => count($changes),
            'changes' => $changes,
            'tasks' => Task::query()->where('project_id', $projectId)->count(),
            'members' => ProjectMember::query()->where('project_id', $projectId)->count(),
        ]);
    }

    /**
     * `field => [from, to]` for the settings that would actually move.
     *
     * Fields already holding the requested value are left out, so the approval card never
     * claims a change that is a no-op — and `execute()` uses the same emptiness test to skip
     * an Action call that would do nothing.
     *
     * @param array<string, User> $people
     * @return array<string, array{from: string|null, to: string|null}>
     */
    private function changesFor(Project $project, Arguments $in, array $people): array
    {
        $changes = [];

        foreach (self::AUTHORITY_FIELDS as $field) {
            if (! $in->has($field)) {
                continue;
            }

            if ((int) $project->getAttribute($field) === $in->int($field)) {
                continue;
            }

            $label = $field === 'owner_id' ? 'owner' : 'manager';
            $current = $field === 'owner_id' ? $project->owner : $project->manager;

            $changes[$label] = [
                'from' => self::clip($current?->name, 80),
                'to' => self::clip(isset($people[$field]) ? $people[$field]->name : null, 80),
            ];
        }

        foreach (['type', 'budget', 'currency', 'client_name', 'department'] as $field) {
            if (! $in->has($field)) {
                continue;
            }

            $current = $project->getAttribute(self::SETTINGS[$field]);
            $current = $current instanceof ProjectType ? $current->value : $current;

            $from = $current === null ? null : (string) $current;
            $to = $field === 'currency' ? mb_strtoupper($in->string($field)) : $this->proposed($in, $field);

            // `budget` comes back from the column as a decimal string and arrives from the
            // model as a number, so "1000.00" and 1000 are the same amount and must not be
            // reported as a change nobody asked for.
            $same = is_numeric($from) && is_numeric($to)
                ? abs(((float) $from) - ((float) $to)) < 0.005
                : $from === $to;

            if ($same) {
                continue;
            }

            $changes[$field] = ['from' => $from, 'to' => $to];
        }

        return $changes;
    }

    private function proposed(Arguments $in, string $field): ?string
    {
        $value = $in->all()[$field] ?? null;

        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Ai\Tools\Write;

use App\Actions\Views\CreateSavedView;
use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Ai\Contracts\AiTool;
use App\Ai\Tools\Concerns\Arguments;
use App\Ai\Tools\Concerns\MutatesThroughActions;
use App\Enums\AiToolRisk;
use App\Enums\Permission;
use App\Enums\ViewType;
use App\Models\Project;
use App\Models\SavedView;

/**
 * Save a list, board, calendar or timeline configuration.
 *
 * Two things make this safe enough to sit at low risk.
 *
 * **Sharing is a separate permission.** A personal view is one person's filter and needs no
 * more than the right to see the project. Publishing one to the whole workspace is a
 * configuration change — `SavedViewPolicy::share()` routes it through `templates.manage` —
 * so this tool asks that question separately and refuses the share rather than quietly
 * creating a private view instead. Silently downgrading would leave the model reporting a
 * shared view that nobody else can see.
 *
 * **The filter document is bounded before it is stored.** `filters`, `sorts` and `columns`
 * are JSON written by the model and read back by the query builders that own their
 * vocabulary; this tool does not pretend to understand it. What it does insist on is shape:
 * scalars at the leaves, a bounded depth, a bounded width, clipped strings. Nothing here is
 * executed — a saved view is data — but an unbounded document from a model is how a JSON
 * column becomes a megabyte, and a deeply nested one is how the screen that renders it
 * becomes the outage.
 */
final class CreateSavedViewTool implements AiTool
{
    private const MAX_DEPTH = 4;

    private const MAX_KEYS = 40;

    private const MAX_LEAF_CHARS = 200;

    use MutatesThroughActions;

    public function __construct(private readonly CreateSavedView $createSavedView) {}

    public function name(): string
    {
        return 'create_saved_view';
    }

    public function group(): string
    {
        return 'views';
    }

    public function description(): string
    {
        return 'Save a filtered list, board, calendar or timeline so it can be opened again. '
            .'Personal by default; sharing it with the workspace needs the template '
            .'permission and is refused rather than downgraded if the acting user lacks it.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['name', 'type'],
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 191],
                'type' => ['type' => 'string', 'enum' => ['list', 'board', 'calendar', 'timeline']],
                'project_id' => [
                    'type' => ['integer', 'null'],
                    'minimum' => 1,
                    'description' => 'Scope the view to one project. Omit for a workspace-wide view.',
                ],
                'filters' => [
                    'type' => 'object',
                    'description' => 'Filter document, e.g. {"status":"open","assignee_id":7}.',
                ],
                'sorts' => ['type' => 'array', 'maxItems' => 10],
                'columns' => [
                    'type' => 'array',
                    'maxItems' => 30,
                    'items' => ['type' => 'string', 'maxLength' => 64],
                ],
                'group_by' => ['type' => ['string', 'null'], 'maxLength' => 64],
                'shared' => [
                    'type' => 'boolean',
                    'description' => 'Publish to the whole workspace. Needs the template permission.',
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
        return Permission::ProjectView;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args, AgentContext $ctx): ToolResult
    {
        return $this->handle($args, $ctx, fn (Arguments $in, AgentContext $ctx): ToolResult => $this->save($in, $ctx));
    }

    private function save(Arguments $in, AgentContext $ctx): ToolResult
    {
        $project = null;

        if ($in->filled('project_id')) {
            $projectId = $in->int('project_id');
            $project = $this->resolveProject($projectId, $ctx);

            if (! $project instanceof Project) {
                return $this->notFound(__('project'), $projectId);
            }

            $ctx->assertInWorkspace($project);
        }

        if (! $ctx->allows('create', $project instanceof Project ? [SavedView::class, $project] : [SavedView::class])) {
            return $this->denied($ctx, __('save views here'));
        }

        $shared = $in->bool('shared');

        if ($shared && $ctx->cannot(Permission::TemplatesManage)) {
            return $this->denied($ctx, __('share a view with the whole workspace'));
        }

        $type = $in->enum('type', ViewType::class) ?? ViewType::List;

        $view = ($this->createSavedView)(
            workspace: $ctx->workspace,
            owner: $ctx->user,
            name: (string) $in->text('name'),
            type: $type,
            filters: $this->bounded($in->all()['filters'] ?? [], 1),
            project: $project,
            sorts: $in->has('sorts') ? $this->bounded($in->all()['sorts'] ?? [], 1) : null,
            columns: $in->has('columns') ? $in->strings('columns') : null,
            groupBy: $in->text('group_by'),
            isShared: $shared,
        );

        return ToolResult::ok(
            __('Saved the :type view ":name" (:visibility) :scope.', [
                'type' => $type->value,
                'name' => self::clip($view->name, 100),
                'visibility' => $shared ? __('shared with the workspace') : __('visible only to :user', ['user' => $ctx->user->name]),
                'scope' => $project instanceof Project
                    ? __('on project :project', ['project' => $project->name])
                    : __('across the workspace'),
            ]),
            [
                'saved_view_id' => (int) $view->getKey(),
                'name' => $view->name,
                'type' => $type->value,
                'project_id' => $project === null ? null : (int) $project->getKey(),
                'is_shared' => $shared,
                'owner' => $ctx->user->name,
                'filter_keys' => array_keys($this->bounded($in->all()['filters'] ?? [], 1)),
            ],
            $view,
        );
    }

    /**
     * A JSON document reduced to a shape a screen can render: scalars at the leaves, bounded
     * depth, bounded width, clipped strings. Anything else is dropped rather than stored.
     *
     * @return array<array-key, mixed>
     */
    private function bounded(mixed $value, int $depth): array
    {
        if (! is_array($value) || $depth > self::MAX_DEPTH) {
            return [];
        }

        $bounded = [];
        $keys = 0;

        foreach ($value as $key => $item) {
            if ($keys >= self::MAX_KEYS) {
                break;
            }

            $keys++;

            if (is_array($item)) {
                $bounded[$key] = $this->bounded($item, $depth + 1);

                continue;
            }

            if (is_string($item)) {
                $bounded[$key] = self::clip($item, self::MAX_LEAF_CHARS);

                continue;
            }

            if (is_int($item) || is_float($item) || is_bool($item) || $item === null) {
                $bounded[$key] = $item;
            }
        }

        return $bounded;
    }
}

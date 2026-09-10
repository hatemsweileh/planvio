<?php

declare(strict_types=1);

namespace App\Ai\Agent;

use App\Ai\Contracts\AiChatRequest;
use App\Ai\Contracts\AiTool;
use App\Ai\Policy\ResolvedPolicy;
use App\Ai\Tools\Elevated\ArchiveProjectTool;
use App\Ai\Tools\Elevated\DeleteProjectTool;
use App\Ai\Tools\Elevated\DeleteTaskTool;
use App\Ai\Tools\Elevated\ElevatedTool;
use App\Ai\Tools\Elevated\ManageProjectMemberTool;
use App\Ai\Tools\Elevated\RemoveWorkspaceMemberTool;
use App\Ai\Tools\Elevated\UpdateProjectSettingsTool;
use App\Ai\Tools\Read\GetActivityTool;
use App\Ai\Tools\Read\GetBudgetSummaryTool;
use App\Ai\Tools\Read\GetProjectHealthTool;
use App\Ai\Tools\Read\GetProjectTool;
use App\Ai\Tools\Read\GetTaskTool;
use App\Ai\Tools\Read\GetTeamWorkloadTool;
use App\Ai\Tools\Read\GetTimeReportTool;
use App\Ai\Tools\Read\GetWorkspaceOverviewTool;
use App\Ai\Tools\Read\ListProjectMembersTool;
use App\Ai\Tools\Read\SearchProjectsTool;
use App\Ai\Tools\Read\SearchTasksTool;
use App\Ai\Tools\Read\SearchWikiTool;
use App\Ai\Tools\Write\AddTagTool;
use App\Ai\Tools\Write\AssignTaskTool;
use App\Ai\Tools\Write\BulkUpdateTasksTool;
use App\Ai\Tools\Write\ChangeTaskStatusTool;
use App\Ai\Tools\Write\CreateChecklistTool;
use App\Ai\Tools\Write\CreateCommentTool;
use App\Ai\Tools\Write\CreateDependencyTool;
use App\Ai\Tools\Write\CreateDocumentTool;
use App\Ai\Tools\Write\CreateMemoryTool;
use App\Ai\Tools\Write\CreateMilestoneTool;
use App\Ai\Tools\Write\CreateProjectTool;
use App\Ai\Tools\Write\CreateSavedViewTool;
use App\Ai\Tools\Write\CreateSubtaskTool;
use App\Ai\Tools\Write\CreateTaskTool;
use App\Ai\Tools\Write\GenerateProjectReportTool;
use App\Ai\Tools\Write\RemoveTagTool;
use App\Ai\Tools\Write\SendNotificationTool;
use App\Ai\Tools\Write\UpdateDocumentTool;
use App\Ai\Tools\Write\UpdateMilestoneTool;
use App\Ai\Tools\Write\UpdateProjectTool;
use App\Ai\Tools\Write\UpdateTaskTool;
use Illuminate\Container\Container;
use LogicException;

/**
 * The fixed list of everything the model may invoke (ARCHITECTURE.md section 7.4).
 *
 * ## Why a constant array and not a directory scan
 *
 * A registry that discovered tools by scanning `app/Ai/Tools` would make "what can the AI
 * do?" a question about the filesystem at deploy time. {@see self::TOOLS} answers it in the
 * source, reviewably, and a tool that is not on this list does not exist as far as the agent
 * loop is concerned.
 *
 * {@see resolve()} maps a *name* the model produced onto an entry in that list and returns
 * null for anything else. The name is never turned into a class name, never concatenated
 * into a namespace, never passed to `new`. The only instantiation here is `new $class` over
 * the compile-time constant above — which is why the model cannot reach a class simply by
 * naming it (AI_SECURITY.md, "AI to arbitrary PHP").
 *
 * ## Why assistant mode is enforced here
 *
 * {@see forContext()} is what decides which tools are described to the provider at all, and
 * in assistant mode it drops every mutating tool. Enforcing that here rather than only in
 * the prompt matters: a mode restriction the model is merely *told* about is a request, and
 * the tool the model is never shown is one it cannot call. The approval gate and the Gate
 * check still stand behind this — this is the outermost of the three, not the only one.
 *
 * ## Why the elevated tools are on the list
 *
 * `archive_project`, `update_project_settings`, `manage_project_member`, `delete_task`,
 * `delete_project` and `remove_workspace_member` are part of the v1 set (ARCHITECTURE.md
 * §7.4) and are registered here like everything else. Being registered is not permission to
 * run: each one is an {@see ElevatedTool}, four of them are on
 * {@see ElevatedTool::UNWAIVABLE}, and every one of them still has to
 * clear the runner's approval gate, the tool's own `approvalGate()` against the recorded
 * `ai_tool_runs` row, and `Gate::forUser($actingUser)` on the record itself. Leaving them
 * out of the registry would not have made them safer — it would only have made an approved
 * call unresolvable at {@see AgentRunner::executeApproved()}, so a human's
 * "yes" could never be carried out.
 */
final class ToolRegistry
{
    /**
     * Every tool Planvio ships, by class name.
     *
     * @var list<class-string<AiTool>>
     */
    private const TOOLS = [
        SearchProjectsTool::class,
        SearchTasksTool::class,
        GetProjectTool::class,
        GetTaskTool::class,
        GetWorkspaceOverviewTool::class,
        GetProjectHealthTool::class,
        GetTeamWorkloadTool::class,
        GetTimeReportTool::class,
        GetBudgetSummaryTool::class,
        ListProjectMembersTool::class,
        SearchWikiTool::class,
        GetActivityTool::class,

        // low
        CreateCommentTool::class,
        CreateChecklistTool::class,
        CreateSavedViewTool::class,
        CreateDocumentTool::class,
        UpdateDocumentTool::class,
        GenerateProjectReportTool::class,
        SendNotificationTool::class,
        CreateMemoryTool::class,

        // medium
        CreateTaskTool::class,
        UpdateTaskTool::class,
        AssignTaskTool::class,
        ChangeTaskStatusTool::class,
        CreateSubtaskTool::class,
        CreateMilestoneTool::class,
        UpdateMilestoneTool::class,
        CreateDependencyTool::class,
        AddTagTool::class,
        RemoveTagTool::class,
        CreateProjectTool::class,
        UpdateProjectTool::class,
        BulkUpdateTasksTool::class,

        // high — offered, but nothing at this level executes unattended in any shipped mode
        ArchiveProjectTool::class,
        UpdateProjectSettingsTool::class,
        ManageProjectMemberTool::class,

        // destructive — on the unwaivable approval list; see ElevatedTool::UNWAIVABLE
        DeleteTaskTool::class,
        DeleteProjectTool::class,
        RemoveWorkspaceMemberTool::class,
    ];

    /**
     * Name to instance, built once per registry.
     *
     * @var array<string, AiTool>|null
     */
    private ?array $tools = null;

    /* ------------------------------------------------------------------ *
     * Resolution
     * ------------------------------------------------------------------ */

    /**
     * The tool the model named, or null.
     *
     * Null is the whole security property of this method: an unknown name produces nothing,
     * not a guess, not a close match, not a class. The caller rejects the call and the run
     * continues (AI_SECURITY.md, "Registry resolution").
     */
    public function resolve(string $name): ?AiTool
    {
        return $this->tools()[$this->normalise($name)] ?? null;
    }

    public function has(string $name): bool
    {
        return $this->resolve($name) !== null;
    }

    /**
     * Every registered tool, in declaration order.
     *
     * @return list<AiTool>
     */
    public function all(): array
    {
        return array_values($this->tools());
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->tools());
    }

    /* ------------------------------------------------------------------ *
     * What this run may use
     * ------------------------------------------------------------------ */

    /**
     * The tools available to one run: the fixed list, narrowed by the resolved policy and by
     * the mode, in that order.
     *
     * The narrowing, in the order it is applied:
     *
     *   1. **Role.** A policy may restrict which workspace roles drive the agent at all
     *      ({@see ResolvedPolicy::permitsRole()}). A caller who is not a member of the bound
     *      workspace holds no role and gets nothing — a run for a non-member can read
     *      nothing, which is an answer rather than a gap.
     *   2. **Mode.** Assistant mode holds no mutating tools. Not "is told not to use them":
     *      does not receive them.
     *   3. **Policy.** Explicit denies win over explicit allows, and an allow-list denies
     *      everything absent from it ({@see ResolvedPolicy::allowsTool()}).
     *
     * Permissions are deliberately *not* filtered here. A tool's `permission()` is checked
     * against the record it is about to touch, inside the tool, once the subject is known —
     * asking the Gate without a subject would hide `get_budget_summary` from a project
     * manager simply because no project happened to be in focus when the prompt was built.
     *
     * @return list<AiTool>
     */
    public function forContext(AgentContext $context): array
    {
        $role = $context->workspaceRole();

        if ($role === null || ! $context->policy->permitsRole($role)) {
            return [];
        }

        $available = [];

        foreach ($this->all() as $tool) {
            if ($tool->isMutating() && ! $context->mode->canMutate()) {
                continue;
            }

            if (! $context->policy->allowsTool($tool->name())) {
                continue;
            }

            $available[] = $tool;
        }

        return $available;
    }

    /**
     * The provider-neutral tool declarations for a request: name, description and JSON
     * Schema, in the shape {@see AiChatRequest} fixes and each driver maps
     * into its own format.
     *
     * Passing a context narrows the list to what that run may use; passing none returns the
     * full catalogue, which is what an administration screen wants.
     *
     * @return list<array{name: string, description: string, parameters: array<string, mixed>}>
     */
    public function schemas(?AgentContext $context = null): array
    {
        $tools = $context === null ? $this->all() : $this->forContext($context);

        return array_map(
            static fn (AiTool $tool): array => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $tool->parameters(),
            ],
            $tools,
        );
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, AiTool>
     */
    private function tools(): array
    {
        if ($this->tools !== null) {
            return $this->tools;
        }

        $tools = [];
        $container = Container::getInstance();

        foreach (self::TOOLS as $class) {
            // Resolved through the container so a tool can take its `App\Actions\*`
            // collaborators as constructor arguments. The class name is the compile-time
            // constant above, never a string the model produced — `make()` here is the same
            // instantiation `new $class` was, with dependencies filled in.
            $tool = $container->make($class);

            $name = $tool->name();

            // Two tools answering to one name would make the audit trail ambiguous and the
            // policy allow-list unenforceable, so it fails at construction rather than
            // silently letting the later entry win.
            if (isset($tools[$name])) {
                throw new LogicException('Duplicate AI tool name "'.$name.'" in the tool registry.');
            }

            $tools[$name] = $tool;
        }

        return $this->tools = $tools;
    }

    /**
     * Tool names are snake_case ASCII. Trimming and lower-casing absorbs the whitespace and
     * capitalisation a provider occasionally adds; nothing else about the string is
     * rewritten, so `delete_task ` resolves and `deleteTask` does not.
     */
    private function normalise(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}

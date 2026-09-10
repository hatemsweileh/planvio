<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Ai;

use App\Ai\AiGate;
use App\Ai\Automations\StartsAgentRuns;
use App\Enums\AiMode;
use App\Enums\AiRunStatus;
use App\Enums\AiTrigger;
use App\Enums\Permission;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\StartAiRunRequest;
use App\Http\Resources\AiRunResource;
use App\Http\Resources\ApiError;
use App\Http\Resources\ApiResponse;
use App\Models\AiRun;
use App\Models\AiToolRun;
use App\Models\Project;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Start an agent run, and poll it.
 *
 * ## Two endpoints, and deliberately no third
 *
 * There is no "execute this tool" endpoint, and there never will be. Every tool call inside a
 * run passes through the fixed pipeline in ARCHITECTURE.md §7.1 — the registry, input
 * validation, the acting user's own Gate check, a workspace assertion, the risk and approval
 * gate, and only then an Action inside a transaction — and an endpoint that invoked a tool
 * directly would be a way around every one of those. The API's whole AI surface is therefore
 * a sentence in and a status out.
 *
 * ## Asynchronous, because the queue is
 *
 * Planvio runs on hosting with no persistent worker: the queue is a database table drained by
 * a cron tick (docs/QUEUE.md). So `POST` records the run and answers `202 Accepted` with a
 * uuid; the loop happens later, and `GET` is how a client finds out what happened. A
 * synchronous endpoint would either block for minutes or lie about being finished.
 *
 * ## Authority
 *
 * The run borrows the token owner's authority and never exceeds it. {@see AiGate} answers
 * whether a run may start at all — the platform switch, the workspace's own settings, the
 * kill switch, the acting user's `ai.use`, and the spend caps — and the mode is then clamped
 * to what the workspace allows and what this person may ask for. Requesting `autonomous`
 * without `ai.autonomous` yields a copilot run: the request is honoured as far as it can be
 * rather than refused, which is the right behaviour for a client library with a default.
 */
final class RunController extends ApiController
{
    /**
     * `POST /api/v1/ai/runs`.
     */
    public function store(
        StartAiRunRequest $request,
        AiGate $gate,
        StartsAgentRuns $dispatcher,
    ): JsonResponse {
        $workspace = $this->workspace($request);
        $actor = $this->actor($request);

        $project = $this->project($request);

        // The named capability first, so a caller without `ai.use` is refused before anything
        // is written; then the policy, which refines it per project.
        $this->authorize(Permission::AiUse->value, $workspace);
        $this->authorize('create', [AiRun::class, $project]);

        $refusal = $gate->refusal($workspace, $actor);

        if ($refusal !== null) {
            // Not a validation failure and not a permission failure: the request is
            // well-formed and the caller is allowed to make it, but the AI layer is switched
            // off, over budget, or has its kill switch engaged. 409 is the status for "the
            // state of the resource says no".
            return ApiError::response(Response::HTTP_CONFLICT, 'ai_unavailable', $refusal);
        }

        $settings = $gate->settingsFor($workspace);
        $provider = $settings?->provider;

        $mode = $this->mode($request->mode(), $settings?->effectiveMode(), $actor, $project);

        $run = new AiRun;

        $run->forceFill([
            'workspace_id' => $workspace->getKey(),
            'project_id' => $project?->getKey(),
            'ai_conversation_id' => null,
            // The authority the whole run borrows. Never a service account.
            'user_id' => $actor->getKey(),
            'trigger' => AiTrigger::Api->value,
            'mode' => $mode->value,
            'objective' => $request->objective(),
            'status' => AiRunStatus::Queued->value,
            'model' => $provider?->model,
            'ai_provider_id' => $provider?->getKey(),
        ])->save();

        $dispatcher->start($run);

        return ApiResponse::item(new AiRunResource($run), Response::HTTP_ACCEPTED);
    }

    /**
     * `GET /api/v1/ai/runs/{uuid}` — poll until `is_finished` is true.
     */
    public function show(Request $request, string $run): JsonResponse
    {
        $workspace = $this->workspace($request);
        $actor = $this->actor($request);

        $record = AiRun::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $workspace->getKey())
            ->where('uuid', $run)
            ->first();

        abort_if($record === null, Response::HTTP_NOT_FOUND, __('No such run.'));

        $this->authorize('view', $record);

        // The tool log is the audit spine, and reading somebody else's is `ai.view_logs`.
        // Reading your own is not — AiToolRunPolicy says so — so the relation is loaded only
        // when the policy allows it and the key is simply absent otherwise.
        if ($actor->can('viewAny', AiToolRun::class) || (int) $record->user_id === (int) $actor->getKey()) {
            $record->load(['toolRuns' => static fn (HasMany $calls): HasMany => $calls->orderBy('sequence')->orderBy('id')]);
        }

        return ApiResponse::item(new AiRunResource($record));
    }

    /**
     * The project the run is scoped to, resolved inside the workspace or not at all.
     */
    private function project(Request $request): ?Project
    {
        $projectId = $request->input('project_id');

        if ($projectId === null || $projectId === '') {
            return null;
        }

        return $this->findInWorkspace($request, Project::class, $projectId);
    }

    /**
     * The mode the run will actually execute in.
     *
     * Two ceilings, applied in order of authority. The workspace's own effective mode is the
     * outer one: a workspace configured for `assistant` does not become a copilot workspace
     * because a client asked nicely. Autonomy is then a separate grant on top, so a caller
     * without `ai.autonomous` lands on copilot even where the workspace allows more.
     *
     * A request for more than is available is honoured as far as it can be rather than
     * refused. A client library with `mode: autonomous` as its default would otherwise fail
     * every call in a workspace that has not turned autonomy on, which tells the person
     * running it nothing they can act on.
     */
    private function mode(?AiMode $requested, ?AiMode $ceiling, User $actor, ?Project $project): AiMode
    {
        $ceiling ??= AiMode::Assistant;
        $mode = $requested ?? $ceiling;

        if (self::rank($mode) > self::rank($ceiling)) {
            $mode = $ceiling;
        }

        if ($mode === AiMode::Autonomous && ! $actor->can('runAutonomously', [AiRun::class, $project])) {
            $mode = AiMode::Copilot;
        }

        return $mode;
    }

    /**
     * How much authority each mode carries, so "no more than" is a comparison rather than a
     * chain of special cases (ARCHITECTURE.md §6).
     */
    private static function rank(AiMode $mode): int
    {
        return match ($mode) {
            AiMode::Assistant => 0,
            AiMode::Copilot => 1,
            AiMode::Autonomous => 2,
        };
    }
}

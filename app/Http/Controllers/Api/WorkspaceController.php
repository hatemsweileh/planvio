<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\ApiResponse;
use App\Http\Resources\WorkspaceResource;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Workspaces, read-only.
 *
 * Creating, renaming and deleting a workspace are not API operations. Each of them is a
 * decision with consequences the API cannot show — a new tenant needs statuses, tags and an
 * owner; a deletion takes every project with it — and each is already a screen in the product
 * that explains what is about to happen. An endpoint that did any of them in one unattended
 * call would be a way to do irreversible things by accident.
 *
 * These two routes sit outside the workspace-scoped group: they are how a client discovers
 * what to put in the `X-Planvio-Workspace` header.
 */
final class WorkspaceController extends ApiController
{
    /**
     * Every workspace this token's owner belongs to.
     *
     * The membership join *is* the authorization: a workspace the caller is not a member of
     * cannot appear in the result at all, which is a stronger statement than filtering one
     * out afterwards.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->actor($request);

        $user->loadMissing('workspaceMemberships');

        $workspaces = Workspace::query()
            ->select(['workspaces.*'])
            ->join('workspace_members', 'workspace_members.workspace_id', '=', 'workspaces.id')
            ->where('workspace_members.user_id', $user->getKey())
            ->orderBy('workspaces.name')
            ->get();

        return ApiResponse::collection($workspaces, WorkspaceResource::class);
    }

    /**
     * One workspace, addressed by slug or id — the same two forms the header accepts.
     */
    public function show(Request $request, string $workspace): JsonResponse
    {
        $user = $this->actor($request);
        $user->loadMissing('workspaceMemberships');

        $record = ctype_digit($workspace)
            ? Workspace::query()->whereKey((int) $workspace)->first()
            : Workspace::query()->where('slug', $workspace)->first();

        // Not a member and does not exist are the same answer, so the policy's verdict is
        // turned into a 404 rather than the 403 `authorize()` would raise: a 403 confirms
        // that a workspace with this slug is registered on the installation, which is
        // exactly what somebody enumerating slugs is trying to find out.
        abort_if(
            $record === null || ! $user->can('view', $record),
            Response::HTTP_NOT_FOUND,
            __('No such workspace.'),
        );

        return ApiResponse::item(new WorkspaceResource($record));
    }
}

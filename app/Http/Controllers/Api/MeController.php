<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\ApiResponse;
use App\Http\Resources\UserResource;
use App\Http\Resources\WorkspaceResource;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * `GET /api/v1/me` — who this token is, and where it can go.
 *
 * The first call any client makes. It is not workspace-scoped, on purpose: its whole job is
 * to tell a caller which values are valid in the `X-Planvio-Workspace` header, and requiring
 * one to find that out would be a chicken and egg.
 *
 * The token block names the token and says when it was last used. It cannot show the token
 * itself: Sanctum stores a hash, the plaintext exists only in the response that minted it,
 * and there is no screen or endpoint anywhere in Planvio that reveals it a second time.
 */
final class MeController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->actor($request);

        // The membership rows are what WorkspaceResource reads each workspace's role from.
        // Loading them here turns "one query per workspace" into one query for all of them.
        $user->loadMissing('workspaceMemberships');

        $workspaces = Workspace::query()
            ->select(['workspaces.*'])
            ->join('workspace_members', 'workspace_members.workspace_id', '=', 'workspaces.id')
            ->where('workspace_members.user_id', $user->getKey())
            ->orderBy('workspaces.name')
            ->get();

        return ApiResponse::raw([
            'user' => (new UserResource($user))->resolve($request),
            'workspaces' => $workspaces
                ->map(static fn (Workspace $workspace): array => (new WorkspaceResource($workspace))->resolve($request))
                ->values()
                ->all(),
            'token' => $this->token($request),
        ]);
    }

    /**
     * The credential in play, described without being revealed.
     *
     * `abilities` is Sanctum's own field and is `["*"]` for every token Planvio issues: a
     * token acts as the person who created it and is narrowed by their workspace role, not
     * by a scope list. Two authorization systems disagreeing about one request is worse than
     * one that is a little coarse (see the profile security screen).
     *
     * @return array<string, mixed>|null
     */
    private function token(Request $request): ?array
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        return [
            'id' => (int) $token->getKey(),
            'name' => (string) $token->name,
            'abilities' => $token->abilities ?? ['*'],
            'last_used_at' => $token->last_used_at instanceof Carbon ? $token->last_used_at->toIso8601String() : null,
            'expires_at' => $token->expires_at instanceof Carbon ? $token->expires_at->toIso8601String() : null,
            'created_at' => $token->created_at instanceof Carbon ? $token->created_at->toIso8601String() : null,
        ];
    }
}

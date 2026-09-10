<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\WorkspaceRole;
use App\Http\Resources\ApiResponse;
use App\Http\Resources\WorkspaceMemberResource;
use App\Models\Scopes\WorkspaceScope;
use App\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The people in a workspace, read-only.
 *
 * Read-only on purpose. Adding somebody to a workspace means sending them an invitation they
 * have to accept; removing somebody reassigns or orphans everything they own. Both are
 * decisions with a screen behind them, and neither should be reachable by a script that has
 * a token and a list of email addresses.
 *
 * The resource is the *membership*, not the account, because "role" is a fact about the pair.
 * Accounts that exist on the installation but are not in this workspace are unreachable here
 * at all: the query starts from `workspace_members`, so there is no filter to get wrong.
 */
final class UserController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);

        $this->authorize('viewAny', WorkspaceMember::class);

        $filters = $request->validate([
            'role' => ['nullable', Rule::enum(WorkspaceRole::class)],
            'q' => ['nullable', 'string', 'max:128'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = WorkspaceMember::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_members.workspace_id', $workspace->getKey())
            ->with('user')
            ->join('users', 'users.id', '=', 'workspace_members.user_id')
            ->whereNull('users.deleted_at')
            ->select('workspace_members.*')
            ->when(isset($filters['role']), fn (Builder $q): Builder => $q->where('workspace_members.role', $filters['role']))
            ->when(
                isset($filters['q']) && trim((string) $filters['q']) !== '',
                function (Builder $q) use ($filters): Builder {
                    $pattern = self::likePattern((string) $filters['q']);

                    return $q->where(function (Builder $match) use ($pattern): void {
                        $match->whereRaw(self::likeSql('users.name'), [$pattern])
                            ->orWhereRaw(self::likeSql('users.email'), [$pattern]);
                    });
                },
            )
            ->orderBy('users.name')
            ->orderBy('workspace_members.id');

        return ApiResponse::paginated($this->paginate($request, $query), WorkspaceMemberResource::class);
    }

    /**
     * One member, addressed by *user* id — the id that appears as `assignee_id`,
     * `reporter_id` and `owner_id` everywhere else in the API, rather than the membership's
     * own key, which appears nowhere.
     */
    public function show(Request $request, string $user): JsonResponse
    {
        $workspace = $this->workspace($request);

        $this->authorize('viewAny', WorkspaceMember::class);

        // Goes through the shared resolver so a stranger's id is a 404, then reads the
        // membership row that gives them a role here.
        $member = $this->workspaceMember($request, (int) $user);

        $record = WorkspaceMember::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $member->getKey())
            ->with('user')
            ->firstOrFail();

        $this->authorize('view', $record);

        return ApiResponse::item(new WorkspaceMemberResource($record));
    }
}

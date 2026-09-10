<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Middleware\SetCurrentWorkspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\Request;

/**
 * A person's membership of a workspace: who they are, and what they may do here.
 *
 * The membership rather than the user is the resource, because "role" is a fact about the
 * pair and not about either half — the same account is an owner in one workspace and a guest
 * in another, and an endpoint that returned a bare user would have to leave that out or lie
 * about it.
 *
 * `last_active_at` is coarse by construction: the product refreshes it at most once an hour
 * (see {@see SetCurrentWorkspace}), so it answers "is this person still
 * around" and not "what are they doing right now".
 *
 * @property WorkspaceMember $resource
 */
final class WorkspaceMemberResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $member = $this->resource;

        return [
            'id' => (int) $member->getKey(),
            'workspace_id' => self::id($member->workspace_id),
            'user_id' => self::id($member->user_id),
            'role' => self::enum($member->role),
            'title' => $member->title === null ? null : (string) $member->title,
            'joined_at' => self::iso($member->joined_at),
            'last_active_at' => self::iso($member->last_active_at),

            'user' => $this->whenLoaded('user', fn (): ?array => $member->user === null
                ? null
                : (new UserResource($member->user))->resolve($request)),
        ];
    }
}

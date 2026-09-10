<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;

/**
 * A tenant, as one of its members sees it.
 *
 * `settings` is deliberately absent. It is a free-form JSON column that the product and
 * future releases write into, so publishing it would turn every internal preference into
 * part of the API contract — and would publish whatever a later feature decided to keep
 * there without anybody reviewing the decision.
 *
 * `role` is the *caller's* role, which is what a client actually needs from this endpoint:
 * it decides which of the writes below will succeed. It is read through
 * {@see User::roleIn()}, which memoises and prefers an already-loaded membership relation,
 * so a list of workspaces costs one query rather than one per row.
 *
 * @property Workspace $resource
 */
final class WorkspaceResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $workspace = $this->resource;
        $user = $request->user();

        return [
            'id' => (int) $workspace->getKey(),
            'name' => (string) $workspace->name,
            'slug' => (string) $workspace->slug,
            'description' => $workspace->description === null ? null : (string) $workspace->description,
            'accent_color' => (string) $workspace->accent_color,
            'timezone' => (string) $workspace->timezone,
            'locale' => (string) $workspace->locale,
            'currency' => (string) $workspace->currency,
            'date_format' => (string) $workspace->date_format,
            'week_starts_on' => (int) $workspace->week_starts_on,
            'owner_id' => self::id($workspace->owner_id),
            'is_suspended' => (bool) $workspace->is_suspended,
            'role' => $user instanceof User ? self::enum($user->roleIn($workspace)) : null,
            'created_at' => self::iso($workspace->created_at),
            'updated_at' => self::iso($workspace->updated_at),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Wiki;

use App\Models\WikiPage;
use Illuminate\Support\Str;

/**
 * Picks a URL slug that is free within the scope a wiki page lives in.
 *
 * `wiki_pages` is unique on (project_id, slug), and that index does not know about soft
 * deletes — a trashed page still occupies its slug — so the search includes trashed rows.
 * Workspace-level pages have a null `project_id`, which SQL treats as distinct from every
 * other null and therefore does not constrain at all; uniqueness for those is enforced here
 * against the workspace instead.
 */
final class WikiSlug
{
    private const MAX_LENGTH = 200;

    private const MAX_ATTEMPTS = 200;

    public static function unique(
        string $title,
        int $workspaceId,
        ?int $projectId,
        ?int $ignoreId = null,
    ): string {
        $base = Str::slug($title);

        if ($base === '') {
            $base = 'page';
        }

        $base = mb_substr($base, 0, self::MAX_LENGTH);
        $candidate = $base;

        for ($suffix = 2; $suffix <= self::MAX_ATTEMPTS; $suffix++) {
            if (! self::taken($candidate, $workspaceId, $projectId, $ignoreId)) {
                return $candidate;
            }

            $candidate = $base.'-'.$suffix;
        }

        // A title colliding two hundred times is a machine, not a person; a random tail
        // ends the loop without ever returning a slug that is already in use.
        return $base.'-'.Str::lower(Str::random(8));
    }

    private static function taken(string $slug, int $workspaceId, ?int $projectId, ?int $ignoreId): bool
    {
        $query = WikiPage::withoutWorkspaceScope()
            ->withTrashed()
            ->where('workspace_id', $workspaceId)
            ->where('slug', $slug);

        $query = $projectId === null
            ? $query->whereNull('project_id')
            : $query->where('project_id', $projectId);

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        return $query->exists();
    }
}

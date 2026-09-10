<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Derives the URL slug of a workspace and guarantees it is free.
 *
 * `workspaces.slug` is globally unique and the index covers soft-deleted rows, so a deleted
 * workspace keeps holding its slug: the lookup therefore includes trashed rows rather than
 * handing out a slug that would fail to insert.
 */
final class WorkspaceSlugGenerator
{
    private const MAX_LENGTH = 96;

    /**
     * @param string|null $preferred an explicit slug from the caller, which is still
     *                               normalised and still deduplicated
     * @param int|null $ignoreId the workspace being renamed, so it does not collide
     *                           with itself
     */
    public function __invoke(string $name, ?string $preferred = null, ?int $ignoreId = null): string
    {
        $base = Str::slug($preferred !== null && trim($preferred) !== '' ? $preferred : $name);

        if ($base === '') {
            $base = 'workspace';
        }

        $base = mb_substr($base, 0, self::MAX_LENGTH);

        if (! $this->taken($base, $ignoreId)) {
            return $base;
        }

        for ($suffix = 2; $suffix <= 999; $suffix++) {
            $candidate = $this->withSuffix($base, (string) $suffix);

            if (! $this->taken($candidate, $ignoreId)) {
                return $candidate;
            }
        }

        // Effectively unreachable, but a workspace must still be creatable when it is not:
        // a random tail is a worse slug than "acme-2" and infinitely better than a failure.
        do {
            $candidate = $this->withSuffix($base, Str::lower(Str::random(8)));
        } while ($this->taken($candidate, $ignoreId));

        return $candidate;
    }

    private function withSuffix(string $base, string $suffix): string
    {
        $room = self::MAX_LENGTH - mb_strlen($suffix) - 1;

        return mb_substr($base, 0, max(1, $room)).'-'.$suffix;
    }

    private function taken(string $slug, ?int $ignoreId): bool
    {
        return Workspace::withTrashed()
            ->where('slug', $slug)
            ->when(
                $ignoreId !== null,
                static fn (Builder $query): Builder => $query->whereKeyNot($ignoreId),
            )
            ->exists();
    }
}

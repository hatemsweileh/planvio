<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Derives a project's URL slug, unique within its workspace.
 *
 * Unlike the key, a taken slug is never an error: two projects may legitimately be called
 * "Website Redesign" and the second simply becomes `website-redesign-2`. The index covers
 * soft-deleted rows, so a trashed project keeps holding its slug until it is purged.
 */
final class ProjectSlugGenerator
{
    private const MAX_LENGTH = 120;

    public function __invoke(
        Workspace $workspace,
        string $name,
        ?string $preferred = null,
        ?int $ignoreProjectId = null,
    ): string {
        $base = Str::slug($preferred !== null && trim($preferred) !== '' ? $preferred : $name);

        if ($base === '') {
            $base = 'project';
        }

        $base = mb_substr($base, 0, self::MAX_LENGTH);

        if (! $this->taken($workspace, $base, $ignoreProjectId)) {
            return $base;
        }

        for ($suffix = 2; $suffix <= 999; $suffix++) {
            $candidate = $this->withSuffix($base, (string) $suffix);

            if (! $this->taken($workspace, $candidate, $ignoreProjectId)) {
                return $candidate;
            }
        }

        do {
            $candidate = $this->withSuffix($base, Str::lower(Str::random(8)));
        } while ($this->taken($workspace, $candidate, $ignoreProjectId));

        return $candidate;
    }

    private function withSuffix(string $base, string $suffix): string
    {
        $room = self::MAX_LENGTH - mb_strlen($suffix) - 1;

        return mb_substr($base, 0, max(1, $room)).'-'.$suffix;
    }

    private function taken(Workspace $workspace, string $slug, ?int $ignoreProjectId): bool
    {
        return Project::query()
            ->withoutWorkspaceScope()
            ->withTrashed()
            ->where('workspace_id', $workspace->getKey())
            ->where('slug', $slug)
            ->when(
                $ignoreProjectId !== null,
                static fn (Builder $query): Builder => $query->whereKeyNot($ignoreProjectId),
            )
            ->exists();
    }
}

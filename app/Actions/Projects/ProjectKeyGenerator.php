<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Exceptions\DuplicateProjectKey;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

/**
 * Derives the short key that prefixes every task number in a project ("WEB-42").
 *
 * Keys are read aloud in stand-ups and typed into search boxes, so the derivation favours
 * something a person would have chosen: initials for a multi-word name, a truncation for a
 * single word. Uniqueness is per workspace and the index covers soft-deleted projects — a
 * deleted project keeps its key, because restoring it must not collide with whatever was
 * created in the meantime.
 */
final class ProjectKeyGenerator
{
    /**
     * `projects.key` is varchar(12).
     */
    public const MAX_LENGTH = 12;

    private const FALLBACK = 'PRJ';

    /**
     * @param string|null $preferred a key the caller chose; normalised and, if taken,
     *                               rejected outright rather than quietly renumbered —
     *                               an explicit key is a decision, not a suggestion
     * @param int|null $ignoreProjectId the project being renamed, so it does not collide
     *                                  with itself
     *
     * @throws DuplicateProjectKey
     */
    public function __invoke(
        Workspace $workspace,
        string $name,
        ?string $preferred = null,
        ?int $ignoreProjectId = null,
    ): string {
        if ($preferred !== null && trim($preferred) !== '') {
            $key = self::normalise($preferred);

            if ($key === '') {
                throw DuplicateProjectKey::exhausted($workspace, $preferred);
            }

            if ($this->taken($workspace, $key, $ignoreProjectId)) {
                throw DuplicateProjectKey::inWorkspace($workspace, $key);
            }

            return $key;
        }

        $base = self::derive($name);

        if (! $this->taken($workspace, $base, $ignoreProjectId)) {
            return $base;
        }

        for ($suffix = 2; $suffix <= 9999; $suffix++) {
            $candidate = self::withSuffix($base, (string) $suffix);

            if (! $this->taken($workspace, $candidate, $ignoreProjectId)) {
                return $candidate;
            }
        }

        throw DuplicateProjectKey::exhausted($workspace, $base);
    }

    /**
     * "Website Redesign" -> WR · "Website" -> WEBS · "Q3 2026 launch" -> QL
     *
     * Digits are kept inside a word but never lead the key, so "2026 Roadmap" reads as
     * "R2026" rather than as something that looks like a task number.
     */
    public static function derive(string $name): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return self::FALLBACK;
        }

        if (count($words) > 1) {
            $initials = '';

            foreach ($words as $word) {
                $initials .= mb_substr($word, 0, 1);
            }

            $key = self::normalise($initials);

            if (mb_strlen($key) >= 2) {
                return $key;
            }
        }

        $key = self::normalise(mb_substr($words[0], 0, 4));

        return $key === '' ? self::FALLBACK : $key;
    }

    /**
     * Upper-cased ASCII letters and digits only, never longer than the column, and never
     * starting with a digit.
     */
    public static function normalise(string $value): string
    {
        $ascii = preg_replace('/[^A-Za-z0-9]/', '', self::toAscii($value)) ?? '';
        $ascii = ltrim($ascii, '0123456789');

        return mb_strtoupper(mb_substr($ascii, 0, self::MAX_LENGTH));
    }

    private static function withSuffix(string $base, string $suffix): string
    {
        $room = self::MAX_LENGTH - mb_strlen($suffix);

        return mb_substr($base, 0, max(1, $room)).$suffix;
    }

    /**
     * Transliterate so "Été Créatif" yields EC rather than an empty key. iconv is not
     * guaranteed on shared hosting, so a failure falls back to dropping the accents' bytes.
     */
    private static function toAscii(string $value): string
    {
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        return $value;
    }

    private function taken(Workspace $workspace, string $key, ?int $ignoreProjectId): bool
    {
        return Project::query()
            ->withoutWorkspaceScope()
            ->withTrashed()
            ->where('workspace_id', $workspace->getKey())
            ->where('key', $key)
            ->when(
                $ignoreProjectId !== null,
                static fn (Builder $query): Builder => $query->whereKeyNot($ignoreProjectId),
            )
            ->exists();
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\CustomFields;

use App\Exceptions\InvalidCustomField;
use App\Models\CustomField;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Derives and defends the machine name a custom field is addressed by.
 *
 * The key is what filters, saved views, imports, the API and AI tools use to name a field,
 * so it is a stable identifier and not a display string: lower-case, ASCII, no spaces.
 *
 * Uniqueness is enforced here rather than left to the database, for two reasons. The unique
 * index is on (workspace_id, project_id, entity, key), and SQL treats every NULL
 * `project_id` as distinct — so it does not constrain workspace-wide fields at all. And a
 * project-level key that shadows a workspace-wide one would make
 * `CustomField::availableForProject()` return two fields answering to the same name, with
 * no defined winner.
 */
final class CustomFieldKey
{
    private const PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /**
     * @param string|null $key the caller's own key, or null to derive one from the name
     */
    public static function make(string $name, ?string $key = null): string
    {
        $candidate = $key === null || trim($key) === '' ? $name : $key;

        $normalised = Str::of($candidate)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->limit(64, '')
            ->value();

        // A key has to start with a letter so it stays usable as an identifier in every
        // consumer that reads it — query strings, JSON schemas, template variables.
        if ($normalised !== '' && preg_match('/^[0-9_]/', $normalised) === 1) {
            $normalised = 'f_'.$normalised;
            $normalised = substr($normalised, 0, 64);
        }

        $normalised = rtrim($normalised, '_');

        if ($normalised === '' || preg_match(self::PATTERN, $normalised) !== 1) {
            throw InvalidCustomField::keyRequired();
        }

        return $normalised;
    }

    /**
     * @param int|null $ignoreId the field being edited, which may keep its own key
     */
    public static function assertAvailable(
        string $key,
        int $workspaceId,
        ?int $projectId,
        string $entity,
        ?int $ignoreId = null,
    ): void {
        $query = CustomField::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('entity', $entity)
            ->where('key', $key);

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        if ($projectId !== null) {
            // A project-level key competes with its own project's fields and with the
            // workspace-wide ones it would shadow. Two projects may reuse a key freely:
            // neither is ever in scope alongside the other.
            $query->where(function (Builder $scoped) use ($projectId): void {
                $scoped->whereNull('project_id')->orWhere('project_id', $projectId);
            });
        }

        // A workspace-wide key competes with everything in the workspace, because it
        // becomes visible inside every project at once — hence no project filter at all.

        if ($query->exists()) {
            throw InvalidCustomField::keyTaken($key, $projectId, $entity);
        }
    }
}

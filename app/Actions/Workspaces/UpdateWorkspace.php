<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Events\Workspaces\WorkspaceUpdated;
use App\Exceptions\DomainException;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Applies a partial change to a workspace.
 *
 * The slug only moves when the caller asks for it. It is in every URL a member has
 * bookmarked, so silently re-deriving it from a renamed workspace would break links for
 * everybody to save one field.
 *
 * `settings` is merged, never replaced: it is a shared JSON document holding, among other
 * things, the board template, and a caller updating one key must not blank the rest. It is
 * also kept out of the activity diff — recording the whole document twice on every save
 * would bury the change that actually happened.
 */
final class UpdateWorkspace
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly WorkspaceSlugGenerator $slugs,
    ) {}

    public function __invoke(
        Workspace $workspace,
        WorkspaceAttributes $attributes,
        ?User $actor = null,
    ): Workspace {
        $columns = $attributes->toColumns();

        if (array_key_exists('name', $columns) && trim((string) $columns['name']) === '') {
            throw new DomainException(__('A workspace needs a name.'));
        }

        if (array_key_exists('slug', $columns)) {
            $columns['slug'] = ($this->slugs)(
                (string) ($columns['name'] ?? $workspace->name),
                (string) $columns['slug'],
                (int) $workspace->getKey(),
            );
        }

        $workspace->fill($columns);

        if ($attributes->hasSettings()) {
            $current = is_array($workspace->settings) ? $workspace->settings : [];
            $workspace->settings = array_replace($current, $attributes->settings ?? []);
        }

        if (! $workspace->isDirty()) {
            return $workspace;
        }

        $changes = ActivityLogger::changes($workspace, $this->diffableAttributes($workspace));
        $properties = $changes === [] ? [] : ['changes' => $changes];

        if ($workspace->isDirty('settings')) {
            $properties['settings_changed'] = $this->changedSettingKeys($workspace);
        }

        DB::transaction(function () use ($workspace, $actor, $properties): void {
            $workspace->save();

            $this->activity->forUser($actor)->log($workspace, 'updated', $properties);
        });

        event(new WorkspaceUpdated($workspace, $changes, $actor));

        return $workspace;
    }

    /**
     * @return list<string>
     */
    private function diffableAttributes(Workspace $workspace): array
    {
        return array_values(array_diff(array_keys($workspace->getDirty()), ['settings']));
    }

    /**
     * The top-level setting keys that were added, removed or replaced — enough to explain
     * the change without copying the document into the feed.
     *
     * @return list<string>
     */
    private function changedSettingKeys(Workspace $workspace): array
    {
        $new = is_array($workspace->settings) ? $workspace->settings : [];
        $originalRaw = $workspace->getRawOriginal('settings');
        $original = is_string($originalRaw) ? json_decode($originalRaw, true) : $originalRaw;
        $old = is_array($original) ? $original : [];

        $keys = [];

        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {
            if (($old[$key] ?? null) !== ($new[$key] ?? null)) {
                $keys[] = (string) $key;
            }
        }

        sort($keys);

        return $keys;
    }
}

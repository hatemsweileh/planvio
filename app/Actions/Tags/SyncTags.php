<?php

declare(strict_types=1);

namespace App\Actions\Tags;

use App\Events\Tags\TagAttached;
use App\Events\Tags\TagDetached;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the whole tag set of a task or project in one go — what a multi-select sends
 * when the person is done choosing.
 *
 * Every id is verified to belong to the record's workspace *before* anything is written, so
 * a payload with one foreign id changes nothing at all rather than attaching the good ones
 * and failing halfway. Syncing the set that is already there writes nothing and records
 * nothing.
 */
final class SyncTags
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * @param list<int> $tagIds
     */
    public function __invoke(Task|Project $taggable, array $tagIds, User $actor): Task|Project
    {
        $workspaceId = (int) $taggable->workspace_id;
        $wanted = array_values(array_unique(array_map(intval(...), $tagIds)));

        $tags = Tag::query()
            ->forWorkspace($workspaceId)
            ->whereIn('id', $wanted)
            ->get(['id', 'name']);

        $resolved = $tags->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        foreach ($wanted as $id) {
            if (! in_array($id, $resolved, true)) {
                throw new TagNotInWorkspace($id, $workspaceId);
            }
        }

        DB::transaction(function () use ($taggable, $tags, $wanted, $actor): void {
            $result = $taggable->tags()->sync($wanted);

            $attached = array_map(intval(...), $result['attached']);
            $detached = array_map(intval(...), $result['detached']);

            if ($attached === [] && $detached === []) {
                return;
            }

            $this->activity->record($taggable, 'tags_synced', $actor, [
                'attached' => $attached,
                'detached' => $detached,
                'tag_ids' => $wanted,
            ]);

            foreach ($attached as $id) {
                $tag = $tags->firstWhere('id', $id);

                if ($tag instanceof Tag) {
                    event(new TagAttached($taggable, $tag, $actor));
                }
            }

            // The detached tags are no longer in the wanted set, so they were not fetched
            // above; they are looked up here rather than left out of the events entirely.
            foreach (Tag::query()->whereIn('id', $detached)->get() as $tag) {
                event(new TagDetached($taggable, $tag, $actor));
            }
        });

        $taggable->unsetRelation('tags');

        return $taggable;
    }
}

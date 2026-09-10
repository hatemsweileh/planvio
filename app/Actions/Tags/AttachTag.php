<?php

declare(strict_types=1);

namespace App\Actions\Tags;

use App\Events\Tags\TagAttached;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Puts a tag on a task or a project.
 *
 * `taggables` is unique on (tag_id, taggable_id, taggable_type) and the attach is done
 * without detaching, so tagging something twice leaves one row and records one entry.
 */
final class AttachTag
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function __invoke(Task|Project $taggable, Tag $tag, User $actor): Task|Project
    {
        // `taggables` has no workspace column of its own; the boundary is the pair of rows
        // it joins, so it has to be checked here or not at all.
        if ((int) $tag->workspace_id !== (int) $taggable->workspace_id) {
            throw new TagNotInWorkspace((int) $tag->getKey(), (int) $taggable->workspace_id);
        }

        DB::transaction(function () use ($taggable, $tag, $actor): void {
            $already = $taggable->tags()->whereKey($tag->getKey())->exists();

            if ($already) {
                return;
            }

            $taggable->tags()->attach($tag->getKey());

            $this->activity->record($taggable, 'tag_added', $actor, [
                'tag_id' => (int) $tag->getKey(),
                'tag' => $tag->name,
            ]);

            event(new TagAttached($taggable, $tag, $actor));
        });

        $taggable->unsetRelation('tags');

        return $taggable;
    }
}

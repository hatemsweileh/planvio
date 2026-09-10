<?php

declare(strict_types=1);

namespace App\Actions\Tags;

use App\Models\Tag;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Removes a tag from the workspace.
 *
 * `tags` has no soft deletes, and `taggables.tag_id` cascades, so this drops the label from
 * every task and project carrying it. The activity row is written before the delete so the
 * feed can still say what disappeared and from how many records.
 */
final class DeleteTag
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function __invoke(Tag $tag, User $actor): Tag
    {
        if (! $tag->exists) {
            return $tag;
        }

        DB::transaction(function () use ($tag, $actor): void {
            $this->activity->record($tag, 'deleted', $actor, [
                'name' => $tag->name,
                'slug' => $tag->slug,
                'tasks' => $tag->tasks()->count(),
                'projects' => $tag->projects()->count(),
            ]);

            $tag->delete();
        });

        return $tag;
    }
}

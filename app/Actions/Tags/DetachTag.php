<?php

declare(strict_types=1);

namespace App\Actions\Tags;

use App\Events\Tags\TagDetached;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Takes a tag off a task or a project. Removing a tag that was not there is silent.
 */
final class DetachTag
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function __invoke(Task|Project $taggable, Tag $tag, User $actor): Task|Project
    {
        DB::transaction(function () use ($taggable, $tag, $actor): void {
            $removed = $taggable->tags()->detach($tag->getKey());

            if ($removed === 0) {
                return;
            }

            $this->activity->record($taggable, 'tag_removed', $actor, [
                'tag_id' => (int) $tag->getKey(),
                'tag' => $tag->name,
            ]);

            event(new TagDetached($taggable, $tag, $actor));
        });

        $taggable->unsetRelation('tags');

        return $taggable;
    }
}

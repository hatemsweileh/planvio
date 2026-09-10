<?php

declare(strict_types=1);

namespace App\Actions\Tags;

use App\Models\Tag;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Renames or recolours a tag.
 *
 * A rename moves the slug with it — the slug is the tag's route key, so leaving it behind
 * would mean a tag called "Blocked" living at `/tags/urgent` forever. The new slug is
 * checked against the workspace first, so the collision comes back as a message about the
 * name rather than as an integrity error from the driver.
 *
 * Submitting the values a tag already has changes nothing.
 */
final class UpdateTag
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function __invoke(Tag $tag, TagChanges $changes, User $actor): Tag
    {
        if ($changes->isEmpty()) {
            return $tag;
        }

        $attributes = $changes->attributes;

        if ($changes->touches('name')) {
            $name = trim((string) $attributes['name']);
            $attributes['name'] = $name;

            $slug = $this->slug($name);

            if ($slug !== $tag->slug) {
                $taken = Tag::query()
                    ->forWorkspace((int) $tag->workspace_id)
                    ->where('slug', $slug)
                    ->whereKeyNot($tag->getKey())
                    ->exists();

                if ($taken) {
                    throw new TagSlugTaken($tag, $slug, $name);
                }

                $attributes['slug'] = $slug;
            }
        }

        $diff = [];

        foreach ($attributes as $column => $value) {
            if ($tag->getAttribute($column) !== $value) {
                $diff[$column] = ['old' => $tag->getAttribute($column), 'new' => $value];
            }
        }

        if ($diff === []) {
            return $tag;
        }

        return DB::transaction(function () use ($tag, $attributes, $diff, $actor): Tag {
            foreach ($diff as $column => $change) {
                $tag->setAttribute($column, $attributes[$column]);
            }

            $tag->save();

            $this->activity->record($tag, 'updated', $actor, ['changes' => $diff]);

            return $tag;
        });
    }

    private function slug(string $name): string
    {
        $slug = Str::slug($name);

        return $slug === '' ? Str::lower(Str::random(12)) : $slug;
    }
}

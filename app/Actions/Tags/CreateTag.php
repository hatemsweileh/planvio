<?php

declare(strict_types=1);

namespace App\Actions\Tags;

use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds a label to a workspace's shared vocabulary.
 *
 * Creating a tag whose slug already exists returns the existing one instead of failing on
 * `unique(workspace_id, slug)`. Tags are typed into an autocomplete by several people at
 * once, so "create or give me the one that is already there" is the useful contract — and
 * it makes the action safe to call twice. The colour of an existing tag is left alone:
 * a second creation is not an edit, and {@see UpdateTag} is where edits belong.
 */
final class CreateTag
{
    /** The brand primary from ARCHITECTURE.md §8, used when the caller expresses no preference. */
    private const DEFAULT_COLOR = '#3F66B0';

    public function __construct(private readonly ActivityLogger $activity) {}

    public function __invoke(
        Workspace $workspace,
        string $name,
        User $actor,
        string $color = self::DEFAULT_COLOR,
        ?string $description = null,
    ): Tag {
        $name = trim($name);
        $slug = $this->slug($name);

        return DB::transaction(function () use ($workspace, $name, $actor, $color, $description, $slug): Tag {
            $existing = Tag::query()
                ->forWorkspace($workspace)
                ->where('slug', $slug)
                ->first();

            if ($existing instanceof Tag) {
                return $existing;
            }

            $tag = Tag::query()->create([
                'workspace_id' => $workspace->getKey(),
                'name' => $name,
                'slug' => $slug,
                'color' => $color,
                'description' => $description,
            ]);

            $this->activity->record($tag, 'created', $actor, [
                'name' => $tag->name,
                'slug' => $tag->slug,
                'color' => $tag->color,
            ]);

            return $tag;
        });
    }

    /**
     * A name written entirely in a script Str::slug cannot transliterate would slug to the
     * empty string, and every such tag in a workspace would then collide with the first.
     */
    private function slug(string $name): string
    {
        $slug = Str::slug($name);

        return $slug === '' ? Str::lower(Str::random(12)) : $slug;
    }
}

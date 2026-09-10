<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Tag;
use Illuminate\Http\Request;

/**
 * A workspace tag.
 *
 * @property Tag $resource
 */
final class TagResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tag = $this->resource;

        return [
            'id' => (int) $tag->getKey(),
            'workspace_id' => self::id($tag->workspace_id),
            'name' => (string) $tag->name,
            'slug' => (string) $tag->slug,
            'color' => (string) $tag->color,
            'description' => $tag->description === null ? null : (string) $tag->description,
            'created_at' => self::iso($tag->created_at),
            'updated_at' => self::iso($tag->updated_at),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\StatusCategory;
use App\Models\ProjectStatus;
use Illuminate\Http\Request;

/**
 * A workspace-level project status — "Planning", "Active", "On Hold".
 *
 * `category` is the field to branch on. The name and the colour are a workspace's own
 * wording and can be anything; the {@see StatusCategory} behind it is fixed by
 * the schema, so an integration that wants "is this project finished" reads the category and
 * never the name.
 *
 * @property ProjectStatus $resource
 */
final class ProjectStatusResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->resource;

        return [
            'id' => (int) $status->getKey(),
            'name' => (string) $status->name,
            'color' => (string) $status->color,
            'category' => self::enum($status->category),
            'position' => (int) $status->position,
            'is_default' => (bool) $status->is_default,
        ];
    }
}

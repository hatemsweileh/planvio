<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TaskStatus;
use Illuminate\Http\Request;

/**
 * One column of a project's board.
 *
 * Task statuses are per project, so the id is only meaningful inside the project it belongs
 * to — which is why `POST /tasks/{task}/status` refuses a status from another project rather
 * than silently moving the task. `is_completed` is the flag that closes a task; `category`
 * is the fixed vocabulary underneath a workspace's own wording.
 *
 * @property TaskStatus $resource
 */
final class TaskStatusResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->resource;

        return [
            'id' => (int) $status->getKey(),
            'project_id' => self::id($status->project_id),
            'name' => (string) $status->name,
            'color' => (string) $status->color,
            'category' => self::enum($status->category),
            'position' => (int) $status->position,
            'is_default' => (bool) $status->is_default,
            'is_completed' => (bool) $status->is_completed,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Actions\Time\UpdateTimeEntry;

/**
 * `PATCH /api/v1/time-entries/{entry}`.
 *
 * {@see UpdateTimeEntry} takes the whole entry rather than a diff, so the
 * controller fills the gaps from the record it already loaded. `project_id` is not editable:
 * moving logged work between projects rewrites two projects' reported time, which is a
 * correction somebody should make deliberately rather than a field on a PATCH.
 */
final class UpdateTimeEntryRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'spent_on' => ['sometimes', 'date'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_billable' => ['sometimes', 'boolean'],
            'task_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function touches(string $key): bool
    {
        return $this->mentions($key);
    }

    public function taskId(): ?int
    {
        return $this->nullableInt('task_id');
    }

    public function description(): ?string
    {
        return $this->trimmed('description');
    }
}

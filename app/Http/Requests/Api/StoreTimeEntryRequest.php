<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

/**
 * `POST /api/v1/time-entries`.
 *
 * Minutes, not hours — the schema stores minutes and so does every other surface, and an
 * API that took `1.75` would have to decide what to do with `1.7333`. The ceiling is one
 * day: a single entry longer than that is a timer somebody forgot to stop, and letting it
 * through quietly ruins a month of reports.
 *
 * `project_id` is required even when `task_id` is given. It could be inferred, but a caller
 * that sends a task from one project and a project from another is telling us something is
 * wrong on their side, and the controller can refuse it rather than silently pick one.
 */
final class StoreTimeEntryRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'min:1'],
            'task_id' => ['nullable', 'integer', 'min:1'],
            'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'spent_on' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_billable' => ['nullable', 'boolean'],
        ];
    }

    public function projectId(): int
    {
        return (int) $this->integer('project_id');
    }

    public function taskId(): ?int
    {
        return $this->nullableInt('task_id');
    }

    public function minutes(): int
    {
        return (int) $this->integer('minutes');
    }

    public function description(): ?string
    {
        return $this->trimmed('description');
    }

    public function isBillable(): bool
    {
        return $this->boolean('is_billable', true);
    }
}

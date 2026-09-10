<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Actions\Tasks\ChangeTaskStatus;
use App\Enums\Priority;
use Illuminate\Validation\Rule;

/**
 * `PATCH /api/v1/tasks/{task}`.
 *
 * A partial edit: only the keys the caller sent are touched. `sometimes` is what makes that
 * true — without it, an absent `due_date` would fail a `date` rule or, worse, pass a
 * `nullable` one and be written as null, silently clearing a date nobody mentioned.
 *
 * Status is deliberately not editable here. Moving a task between columns has consequences —
 * completion, watchers, the activity entry people read — so it goes through
 * `POST /tasks/{task}/status` and {@see ChangeTaskStatus}, which owns
 * them. Two ways to change a status is two chances for one of them to forget something.
 */
final class UpdateTaskRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'min:1', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'priority' => ['sometimes', Rule::enum(Priority::class)],
            'assignee_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'milestone_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'estimate_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:5256000'],
            'progress' => ['sometimes', 'integer', 'min:0', 'max:100'],
        ];
    }

    public function touches(string $key): bool
    {
        return $this->mentions($key);
    }

    public function title(): string
    {
        return (string) $this->trimmed('title');
    }

    public function description(): ?string
    {
        return $this->trimmed('description');
    }

    public function assigneeId(): ?int
    {
        return $this->nullableInt('assignee_id');
    }

    public function milestoneId(): ?int
    {
        return $this->nullableInt('milestone_id');
    }

    public function estimateMinutes(): ?int
    {
        return $this->nullableInt('estimate_minutes');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

/**
 * `POST /api/v1/tasks/{task}/assign`.
 *
 * `present` rather than `required`: `{"assignee_id": null}` is the way to unassign, and
 * `required` would reject it. An empty body is still refused, because "assign this task"
 * with nothing to assign it to is more likely a bug in the caller than an intention.
 */
final class AssignTaskRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'assignee_id' => ['present', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function assigneeId(): ?int
    {
        return $this->nullableInt('assignee_id');
    }
}

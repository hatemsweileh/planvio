<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Enums\Priority;
use App\Http\Controllers\Api\ApiController;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/tasks`.
 *
 * Every id here is validated only for *shape*. Whether task 91 is a task in this workspace
 * is a tenancy question, and a tenancy question answered by an `exists:` rule is answered by
 * a query with no workspace in it. The controller resolves each reference through
 * {@see ApiController::findInWorkspace()} instead, which is where
 * a foreign id becomes a 404 rather than a validation message that confirms the row exists.
 */
final class StoreTaskRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'min:1', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
            'status_id' => ['nullable', 'integer', 'min:1'],
            'priority' => ['nullable', Rule::enum(Priority::class)],
            'assignee_id' => ['nullable', 'integer', 'min:1'],
            'milestone_id' => ['nullable', 'integer', 'min:1'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            // A shade over a year of continuous work. High enough never to refuse a real
            // estimate, low enough that a fat-fingered value cannot poison a burndown.
            'estimate_minutes' => ['nullable', 'integer', 'min:0', 'max:5256000'],
        ];
    }

    public function projectId(): int
    {
        return (int) $this->integer('project_id');
    }

    public function title(): string
    {
        return (string) $this->trimmed('title');
    }

    public function description(): ?string
    {
        return $this->trimmed('description');
    }

    public function priority(): Priority
    {
        $priority = $this->enum('priority', Priority::class);

        return $priority instanceof Priority ? $priority : Priority::Medium;
    }

    public function statusId(): ?int
    {
        return $this->nullableInt('status_id');
    }

    public function assigneeId(): ?int
    {
        return $this->nullableInt('assignee_id');
    }

    public function milestoneId(): ?int
    {
        return $this->nullableInt('milestone_id');
    }

    public function parentId(): ?int
    {
        return $this->nullableInt('parent_id');
    }

    public function estimateMinutes(): ?int
    {
        return $this->nullableInt('estimate_minutes');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Actions\Milestones\MilestoneAttributes;
use App\Enums\MilestoneStatus;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/milestones`.
 *
 * `completed_at` is not accepted, here or on the update. The completion timestamp is written
 * by the status transition so that the two can never disagree — a milestone marked
 * `completed` with a null timestamp, or the reverse, is a state no report can interpret.
 */
final class StoreMilestoneRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
            'status' => ['nullable', Rule::enum(MilestoneStatus::class)],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'owner_id' => ['nullable', 'integer', 'min:1'],
            'position' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'progress' => ['nullable', 'integer', 'min:0', 'max:100'],
        ];
    }

    public function projectId(): int
    {
        return (int) $this->integer('project_id');
    }

    public function ownerId(): ?int
    {
        return $this->nullableInt('owner_id');
    }

    public function toAttributes(): MilestoneAttributes
    {
        return new MilestoneAttributes(
            name: $this->trimmed('name'),
            description: $this->mentions('description') ? (string) $this->input('description', '') : null,
            status: $this->enum('status', MilestoneStatus::class),
            startDate: $this->date('start_date'),
            dueDate: $this->date('due_date'),
            ownerId: $this->nullableInt('owner_id'),
            position: $this->nullableInt('position'),
            progress: $this->nullableInt('progress'),
        );
    }
}

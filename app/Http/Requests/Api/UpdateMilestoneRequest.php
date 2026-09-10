<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Actions\Milestones\MilestoneAttributes;
use App\Enums\MilestoneStatus;
use Illuminate\Validation\Rule;

/**
 * `PATCH /api/v1/milestones/{milestone}`.
 *
 * Moving a milestone between projects is not offered: its tasks, its position and the
 * project's timeline all hang off the pairing, so the operation is a re-plan rather than an
 * edit and belongs on a screen that can show what moves with it.
 */
final class UpdateMilestoneRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'status' => ['sometimes', Rule::enum(MilestoneStatus::class)],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'owner_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'progress' => ['sometimes', 'integer', 'min:0', 'max:100'],
        ];
    }

    public function mentionsOwner(): bool
    {
        return $this->mentions('owner_id');
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

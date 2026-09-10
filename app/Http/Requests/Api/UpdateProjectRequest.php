<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Actions\Projects\ProjectAttributes;
use App\Enums\Priority;
use App\Enums\ProjectHealth;
use App\Enums\ProjectType;
use Illuminate\Validation\Rule;

/**
 * `PATCH /api/v1/projects/{project}`.
 *
 * {@see ProjectAttributes} already means "null leaves the column alone", so a partial edit
 * needs nothing more than not filling in the fields that were not sent. The one wrinkle is
 * clearing: the action treats an empty string as "clear this" for the columns that accept
 * null — description, icon, client_name, department, health_note — so `""` is a meaningful
 * value here and is deliberately not trimmed away.
 *
 * `key` and `slug` are absent on purpose. They are a project's identity: task keys are built
 * from `key`, and every URL and bookmark in the workspace is built from `slug`. Renaming
 * either through an API call would break links that are already out in the world, so the
 * product does it on a screen that can explain the consequence.
 */
final class UpdateProjectRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'type' => ['sometimes', Rule::enum(ProjectType::class)],
            'priority' => ['sometimes', Rule::enum(Priority::class)],
            'health' => ['sometimes', Rule::enum(ProjectHealth::class)],
            'health_note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:16'],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'client_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'department' => ['sometimes', 'nullable', 'string', 'max:255'],
            'manager_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'target_date' => ['sometimes', 'nullable', 'date'],
            'budget' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha'],
        ];
    }

    /**
     * @param bool $withBudget whether the caller may set money on this project
     */
    public function toAttributes(bool $withBudget): ProjectAttributes
    {
        $budget = $this->input('budget');

        return new ProjectAttributes(
            name: $this->clearable('name'),
            description: $this->clearable('description'),
            icon: $this->clearable('icon'),
            color: $this->clearable('color'),
            type: $this->enum('type', ProjectType::class),
            statusId: $this->mentions('status_id') ? $this->nullableInt('status_id') : null,
            health: $this->enum('health', ProjectHealth::class),
            healthNote: $this->clearable('health_note'),
            priority: $this->enum('priority', Priority::class),
            managerId: $this->mentions('manager_id') ? $this->nullableInt('manager_id') : null,
            clientName: $this->clearable('client_name'),
            department: $this->clearable('department'),
            startDate: $this->date('start_date'),
            targetDate: $this->date('target_date'),
            budget: $withBudget && (is_int($budget) || is_float($budget) || is_string($budget)) ? $budget : null,
            currency: $withBudget ? $this->clearable('currency') : null,
        );
    }

    /**
     * The value as the action wants it: the string when one was sent, an empty string when
     * the caller sent null for a column that accepts it, and null when the key was absent.
     */
    private function clearable(string $key): ?string
    {
        if (! $this->mentions($key)) {
            return null;
        }

        $value = $this->input($key);

        return is_string($value) ? trim($value) : '';
    }
}

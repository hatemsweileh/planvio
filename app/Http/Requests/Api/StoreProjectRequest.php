<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Actions\Projects\ProjectAttributes;
use App\Enums\Priority;
use App\Enums\ProjectHealth;
use App\Enums\ProjectType;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/projects`.
 *
 * `key` and `slug` go to the generators rather than to the columns, and the two are treated
 * differently on purpose. A slug that is taken is quietly suffixed — nobody reads a slug. A
 * *key* that is taken is refused, because it prefixes every task number in the project and
 * handing back `WEB2` to somebody who asked for `WEB` would rename several hundred tasks
 * they had not thought about. Neither is validated for uniqueness here: uniqueness is per
 * workspace and covers soft-deleted rows, so the generator is the only thing that can answer
 * it correctly, and it answers with a translated refusal (`error.code: rule_violated`).
 *
 * `budget` is validated but only written when the caller holds `budget.manage`; the
 * controller does that check, because it is a question about the project and the person, not
 * about the payload.
 */
final class StoreProjectRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'key' => ['nullable', 'string', 'max:12', 'regex:/^[A-Za-z][A-Za-z0-9]*$/'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
            'type' => ['nullable', Rule::enum(ProjectType::class)],
            'priority' => ['nullable', Rule::enum(Priority::class)],
            'health' => ['nullable', Rule::enum(ProjectHealth::class)],
            'icon' => ['nullable', 'string', 'max:16'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'client_name' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'manager_id' => ['nullable', 'integer', 'min:1'],
            'start_date' => ['nullable', 'date'],
            'target_date' => ['nullable', 'date'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
        ];
    }

    /**
     * @param bool $withBudget whether the caller may set money on this project
     */
    public function toAttributes(bool $withBudget): ProjectAttributes
    {
        $budget = $this->input('budget');

        return new ProjectAttributes(
            name: $this->trimmed('name'),
            key: $this->trimmed('key'),
            slug: $this->trimmed('slug'),
            description: $this->trimmed('description'),
            icon: $this->trimmed('icon'),
            color: $this->trimmed('color'),
            type: $this->enum('type', ProjectType::class),
            health: $this->enum('health', ProjectHealth::class),
            priority: $this->enum('priority', Priority::class),
            managerId: $this->nullableInt('manager_id'),
            clientName: $this->trimmed('client_name'),
            department: $this->trimmed('department'),
            startDate: $this->date('start_date'),
            targetDate: $this->date('target_date'),
            budget: $withBudget && (is_int($budget) || is_float($budget) || is_string($budget)) ? $budget : null,
            currency: $withBudget ? $this->trimmed('currency') : null,
        );
    }
}

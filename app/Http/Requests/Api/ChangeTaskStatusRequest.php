<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

/**
 * `POST /api/v1/tasks/{task}/status`.
 *
 * The status is addressed by id, not by name. Names are a workspace's own wording, two
 * projects can both have a column called "Done", and a caller that matched on the string
 * would move tasks into whichever one happened to sort first.
 */
final class ChangeTaskStatusRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function statusId(): int
    {
        return (int) $this->integer('status_id');
    }
}

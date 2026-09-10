<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Actions\Tags\CreateTag;

/**
 * `POST /api/v1/tags`.
 *
 * Creating a tag whose slug already exists returns the existing one rather than failing —
 * that is {@see CreateTag}'s contract, and it is what makes the endpoint
 * safe to retry. The response is `201` for a new tag and `200` for one that was already
 * there, so a caller can tell the two apart without a second request.
 */
final class StoreTagRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:64'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function name(): string
    {
        return (string) $this->trimmed('name');
    }

    public function color(): ?string
    {
        return $this->trimmed('color');
    }

    public function description(): ?string
    {
        return $this->trimmed('description');
    }
}

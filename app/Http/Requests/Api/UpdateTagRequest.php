<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Actions\Tags\TagChanges;

/**
 * `PATCH /api/v1/tags/{tag}`.
 *
 * The slug is not editable. It is what `taggables` and every saved filter resolve against,
 * so changing it would quietly detach a tag from the views built on it.
 */
final class UpdateTagRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:64'],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function toChanges(): TagChanges
    {
        $changes = TagChanges::make();

        if ($this->mentions('name')) {
            $changes = $changes->name((string) $this->trimmed('name'));
        }

        if ($this->mentions('color')) {
            $changes = $changes->color((string) $this->trimmed('color'));
        }

        if ($this->mentions('description')) {
            $changes = $changes->description($this->trimmed('description'));
        }

        return $changes;
    }
}

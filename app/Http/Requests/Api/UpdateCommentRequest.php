<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

/**
 * `PATCH /api/v1/comments/{comment}`.
 *
 * An edit replaces the body outright and stamps `edited_at`, which is what the product shows
 * next to the author's name. There is no way to edit silently.
 */
final class UpdateCommentRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:65535'],
        ];
    }

    public function body(): string
    {
        return (string) $this->input('body');
    }
}

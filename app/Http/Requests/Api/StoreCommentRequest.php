<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Actions\Comments\CreateComment;

/**
 * `POST /api/v1/tasks/{task}/comments` and `POST /api/v1/projects/{project}/comments`.
 *
 * `body` arrives as HTML and is sanitised by {@see CreateComment}
 * before it is stored — the API does not get its own, weaker, sanitiser. What is stored is
 * what the product's own composer would have stored for the same input.
 */
final class StoreCommentRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:65535'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function body(): string
    {
        return (string) $this->input('body');
    }

    public function parentId(): ?int
    {
        return $this->nullableInt('parent_id');
    }
}

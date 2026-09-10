<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Enums\AiMode;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/ai/runs`.
 *
 * The whole request is a sentence and, optionally, a project to say it about. There is no
 * tool list, no step budget and no "run this tool" — the agent loop chooses its own calls
 * and every one of them goes through the policy and approval gates
 * (ARCHITECTURE.md §7.1). A parameter that let a caller shape that would be a way around it.
 *
 * `mode` is a *request*, not a grant: the controller clamps it to whatever the workspace's
 * settings and the caller's permissions actually allow, so asking for `autonomous` without
 * `ai.autonomous` yields a copilot run rather than an error. Asking for more authority than
 * you have is a normal thing for a client library with a default to do, and refusing the
 * request would teach nobody anything.
 */
final class StartAiRunRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'objective' => ['required', 'string', 'min:3', 'max:2000'],
            'project_id' => ['nullable', 'integer', 'min:1'],
            'mode' => ['nullable', Rule::enum(AiMode::class)],
        ];
    }

    public function objective(): string
    {
        return (string) $this->trimmed('objective');
    }

    public function projectId(): ?int
    {
        return $this->nullableInt('project_id');
    }

    public function mode(): ?AiMode
    {
        $mode = $this->enum('mode', AiMode::class);

        return $mode instanceof AiMode ? $mode : null;
    }
}

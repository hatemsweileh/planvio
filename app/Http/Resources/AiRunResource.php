<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AiRun;
use App\Models\AiToolRun;
use App\Policies\AiToolRunPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * One execution of the agent loop.
 *
 * ## Why the uuid is the address
 *
 * `POST /ai/runs` answers with `uuid`, and `GET /ai/runs/{uuid}` is how a client polls. The
 * numeric `id` is included because it is what `ai_run_id` on a comment or an activity refers
 * to, but it is never the thing in a URL: run ids are sequential, and a sequential
 * identifier in a polling URL is an invitation to walk the neighbours. The policy would
 * refuse them — but not being able to ask is better than being refused.
 *
 * ## What is not here
 *
 * The prompt, the provider's replies, the tool arguments and the provider credentials. The
 * conversation is not part of the run record (prompt bodies are not persisted at all by
 * default, `config/ai.php`), and tool arguments are workspace content that the summary
 * already describes. What is here is what a poller needs: where the run got to, what it
 * cost, and the sentence it finished with.
 *
 * `tool_calls` is rendered only when the controller loaded it, which it does after asking
 * {@see AiToolRunPolicy} — the same gate the run log in the product uses.
 *
 * @property AiRun $resource
 */
final class AiRunResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $run = $this->resource;

        return [
            'id' => (int) $run->getKey(),
            'uuid' => (string) $run->uuid,
            'workspace_id' => self::id($run->workspace_id),
            'project_id' => self::id($run->project_id),
            'user_id' => self::id($run->user_id),
            'trigger' => self::enum($run->trigger),
            'mode' => self::enum($run->mode),
            'status' => self::enum($run->status),
            'objective' => $run->objective === null ? null : (string) $run->objective,
            'summary' => $run->summary === null ? null : (string) $run->summary,
            'error' => $run->error === null ? null : (string) $run->error,
            'steps' => (int) $run->steps,
            'tool_call_count' => (int) $run->tool_call_count,
            'error_count' => (int) $run->error_count,
            'tokens_in' => (int) $run->tokens_in,
            'tokens_out' => (int) $run->tokens_out,
            'model' => $run->model === null ? null : (string) $run->model,
            'is_finished' => $run->isTerminal(),
            'started_at' => self::iso($run->started_at),
            'finished_at' => self::iso($run->finished_at),
            'duration_ms' => self::id($run->duration_ms),
            'created_at' => self::iso($run->created_at),

            'tool_calls' => $this->whenLoaded('toolRuns', fn (): array => Collection::make($run->toolRuns)
                ->map(static fn (AiToolRun $call): array => [
                    'sequence' => (int) $call->sequence,
                    'tool' => (string) $call->tool,
                    'risk' => self::enum($call->risk),
                    'status' => self::enum($call->status),
                    'approval_required' => (bool) $call->approval_required,
                    'summary' => $call->result_summary === null ? null : (string) $call->result_summary,
                    'error' => $call->error === null ? null : (string) $call->error,
                    'duration_ms' => self::id($call->duration_ms),
                ])
                ->values()
                ->all()),
        ];
    }
}

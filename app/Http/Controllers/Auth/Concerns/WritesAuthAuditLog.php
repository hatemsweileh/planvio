<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth\Concerns;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Writes the security trail for authentication events (ARCHITECTURE.md §5.7).
 *
 * `audit_logs` is deliberately separate from `activities`: the product timeline answers
 * "what happened to this task", this answers "who signed in, from where, and what failed
 * before they did". The second question is the one asked after an incident, and it has to be
 * answerable even for accounts and workspaces that no longer exist — which is why both
 * foreign keys null out rather than cascade.
 *
 * `properties` never carries a credential (CLAUDE.md rule 4). An email address does appear
 * on failed sign-ins: without it the trail cannot distinguish one account being guessed at
 * from noise, which is the whole reason the row is written.
 */
trait WritesAuthAuditLog
{
    /**
     * @param array<string, scalar|array<array-key, mixed>|null> $properties
     */
    protected function audit(
        Request $request,
        string $event,
        ?User $user = null,
        ?string $description = null,
        array $properties = [],
    ): AuditLog {
        $userAgent = $request->userAgent();

        return AuditLog::query()->create([
            'user_id' => $user?->getKey(),
            'workspace_id' => null,
            'event' => $event,
            'description' => $description === null ? null : mb_substr($description, 0, 255),
            'ip' => $request->ip(),
            // The column is 255 characters; some scanners send far longer strings.
            'user_agent' => is_string($userAgent) ? mb_substr($userAgent, 0, 255) : null,
            'properties' => $properties === [] ? null : $properties,
        ]);
    }
}

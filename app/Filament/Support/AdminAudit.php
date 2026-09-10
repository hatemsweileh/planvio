<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * The security trail for things done from the administration panel.
 *
 * Everything reachable from `/admin` is, by construction, an act nobody else in the
 * installation could perform: deactivating an account, suspending a tenant, moving ownership
 * of a workspace, rotating an AI credential, closing the whole site for maintenance. The
 * product's `activities` feed is the wrong home for those — it is workspace-scoped and shown
 * to members — so they go to `audit_logs`, alongside the authentication trail, which is where
 * an incident is actually reconstructed from.
 *
 * Filament's own record-level logging is not used, deliberately: it would record a diff of
 * columns, and the useful sentence here is what was *done*, in the vocabulary of the person
 * reading it six months later.
 *
 * `properties` never carries a credential (CLAUDE.md rule 4). Callers pass identifiers and
 * outcomes; an API key, a password or an SMTP secret must never appear, not even masked.
 */
final class AdminAudit
{
    private function __construct() {}

    /**
     * @param array<string, scalar|array<array-key, mixed>|null> $properties
     */
    public static function record(
        string $event,
        string $description,
        ?User $subject = null,
        array $properties = [],
        ?int $workspaceId = null,
    ): AuditLog {
        $actor = Auth::user();
        $userAgent = Request::userAgent();

        return AuditLog::query()->create([
            // The account acted *upon* is carried in `properties`; `user_id` is the actor,
            // which is what "who did this" has to resolve to.
            'user_id' => $actor instanceof User ? $actor->getKey() : null,
            'workspace_id' => $workspaceId,
            'event' => $event,
            'description' => mb_substr($description, 0, 255),
            'ip' => Request::ip(),
            'user_agent' => is_string($userAgent) ? mb_substr($userAgent, 0, 255) : null,
            'properties' => self::properties($subject, $properties),
        ]);
    }

    /**
     * @param array<string, scalar|array<array-key, mixed>|null> $properties
     * @return array<string, scalar|array<array-key, mixed>|null>|null
     */
    private static function properties(?User $subject, array $properties): ?array
    {
        if ($subject instanceof User) {
            $properties = [
                'subject_user_id' => $subject->getKey(),
                'subject_email' => $subject->email,
            ] + $properties;
        }

        return $properties === [] ? null : $properties;
    }
}

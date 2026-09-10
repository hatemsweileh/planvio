<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ai\Agent\QueuedRunDispatcher;
use App\Ai\Approvals\ResumesApprovedRuns;
use App\Ai\Automations\StartsAgentRuns;
use Illuminate\Support\ServiceProvider;

/**
 * Bindings owned by the AI layer.
 *
 * These live here rather than in AppServiceProvider so the AI slice stays removable: an
 * installation with AI switched off loads this provider, binds two interfaces nothing calls,
 * and costs nothing. Nothing outside app/Ai depends on any of it.
 *
 * The dispatcher binding is the one that matters. ApprovalService and AutomationRunner both
 * accept a null collaborator and fail closed without it — the approval is recorded and the
 * run is parked in `queued`, so nothing is lost, but nothing advances either. Bound, an
 * approved call resumes and a due automation starts.
 */
final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(QueuedRunDispatcher::class);

        $this->app->bind(ResumesApprovedRuns::class, QueuedRunDispatcher::class);
        $this->app->bind(StartsAgentRuns::class, QueuedRunDispatcher::class);
    }
}

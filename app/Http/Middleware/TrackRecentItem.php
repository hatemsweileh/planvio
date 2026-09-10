<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Project;
use App\Models\RecentItem;
use App\Models\Task;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remembers what the user just opened, for the recents list and the command palette.
 *
 * The write happens in `terminate()`, after the response has been handed to the web server:
 * a jump list is not worth a round trip in front of the page it describes, and a failure
 * here must never cost the user the record they asked for.
 *
 * Only the most specific subject on the route is recorded. Opening a task from a link would
 * otherwise bump its project too, and a recents list where every task drags its project
 * along stops being a list of what you were doing.
 */
final class TrackRecentItem
{
    /**
     * In precedence order: the last match on the route wins.
     *
     * @var list<class-string<Model>>
     */
    private const TRACKED = [
        Project::class,
        Task::class,
    ];

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $request->isMethod('GET') || $response->getStatusCode() >= 400) {
            return;
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return;
        }

        $subject = $this->subject($request);

        if ($subject === null) {
            return;
        }

        $workspaceId = $subject->getAttribute('workspace_id');

        if (! is_numeric($workspaceId)) {
            return;
        }

        try {
            RecentItem::withoutWorkspaceScope()->updateOrCreate(
                [
                    'user_id' => $user->getKey(),
                    'viewable_type' => $subject->getMorphClass(),
                    'viewable_id' => $subject->getKey(),
                ],
                [
                    'workspace_id' => (int) $workspaceId,
                    'viewed_at' => Carbon::now(),
                ],
            );
        } catch (QueryException) {
            // Two tabs opening the same record race on the unique index. The row the winner
            // wrote is the row this one wanted, so there is nothing to repair.
        }
    }

    /**
     * The most specific tracked model bound to the current route.
     */
    private function subject(Request $request): ?Model
    {
        $parameters = $request->route()?->parameters();

        if (! is_array($parameters) || $parameters === []) {
            return null;
        }

        $subject = null;
        $rank = -1;

        foreach ($parameters as $parameter) {
            if (! $parameter instanceof Model || ! $parameter->exists) {
                continue;
            }

            foreach (self::TRACKED as $index => $class) {
                if ($parameter instanceof $class && $index > $rank) {
                    $subject = $parameter;
                    $rank = $index;
                }
            }
        }

        return $subject;
    }
}

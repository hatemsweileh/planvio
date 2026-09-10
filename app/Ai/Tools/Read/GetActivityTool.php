<?php

declare(strict_types=1);

namespace App\Ai\Tools\Read;

use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Enums\AuthorType;
use App\Enums\Permission;
use App\Enums\WorkspaceRole;
use App\Models\Activity;
use App\Services\DateResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * The recent change feed, for one project or across the workspace.
 *
 * Rows are filtered the way `ActivityPolicy::view()` filters them, and for the same reason:
 * an entry carries its own `project_id`, so a guest sees the projects they are in and a
 * workspace-level entry — a member being added, a setting changed — has no project for the
 * guest cell of the capability matrix to match and stays out of their feed entirely.
 *
 * `properties` is deliberately not returned. It holds `{attribute, old, new}` payloads whose
 * shape varies by event, it can be large, and it is the one column on this table an
 * attacker-influenced value could reach. The event name, the subject and the human
 * description carry what the model needs to narrate what happened; anything more specific is
 * a read of the record itself.
 */
final class GetActivityTool extends ReadTool
{
    public function __construct(
        private readonly DateResolver $dates = new DateResolver,
    ) {}

    public function name(): string
    {
        return 'get_activity';
    }

    public function description(): string
    {
        return 'Read the most recent activity entries, newest first, for one project or for '
            .'the whole workspace. Each entry names the event, the record it happened to, who '
            .'caused it and whether that was a person or the AI acting for them.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'project' => [
                    'type' => ['integer', 'string'],
                    'description' => 'Restrict to one project, by id or key. Omit for the whole workspace.',
                ],
                'since' => [
                    'type' => 'string',
                    'maxLength' => 64,
                    'description' => 'Only entries from this day onwards. YYYY-MM-DD or a phrase such as "7 days ago".',
                ],
                'actor' => [
                    'type' => 'string',
                    'enum' => ['any', 'user', 'ai'],
                    'default' => 'any',
                    'description' => 'Restrict to changes made by people, by the AI acting for someone, or either.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                    'description' => 'Maximum entries to return.',
                ],
            ],
        ];
    }

    public function permission(): ?Permission
    {
        return Permission::WorkspaceView;
    }

    protected function read(array $args, AgentContext $ctx): ToolResult
    {
        $project = null;

        if (($reference = $args['project'] ?? null) !== null) {
            $reference = is_int($reference) ? $reference : (string) $reference;
            $project = $this->resolveProject($ctx, $reference);

            if ($project === null) {
                return $this->notFound('project', $reference);
            }

            $ctx->assertInWorkspace($project);

            if ($ctx->cannot(Permission::ProjectView, $project)) {
                return $this->denied('project');
            }
        }

        if (! $ctx->allows('viewAny', [Activity::class, $project])) {
            return $this->denied('activity');
        }

        $query = Activity::query()->forWorkspace($ctx->workspace);

        if ($project !== null) {
            $query->forProject($project);
        } else {
            $this->restrictToVisibleFeed($query, $ctx);
        }

        $actor = is_string($args['actor'] ?? null) ? $args['actor'] : 'any';

        if ($actor === 'ai') {
            $query->where('activities.causer_type', AuthorType::Ai->value);
        } elseif ($actor === 'user') {
            $query->where('activities.causer_type', AuthorType::User->value);
        }

        $since = null;

        if (is_string($args['since'] ?? null) && $args['since'] !== '') {
            $since = $this->dates->forWorkspace($ctx->workspace, $args['since'], $ctx->now());

            if ($since === null) {
                return ToolResult::failed(
                    __('ai.tools.unreadable_date', ['field' => 'since', 'value' => self::clip($args['since'], 64)]),
                    'invalid_arguments',
                );
            }

            $query->where('activities.created_at', '>=', $since->setTimezone('UTC'));
        }

        $total = (clone $query)->count();
        $limit = self::pageSize($args['limit'] ?? null, 'activity', 30);
        $timezone = $ctx->resolvedTimezone();

        $activities = $query
            ->with(['causer:id,name', 'project:id,key,name'])
            ->orderByDesc('activities.created_at')
            ->orderByDesc('activities.id')
            ->limit($limit)
            ->get();

        $rows = [];

        foreach ($activities as $activity) {
            $rows[] = [
                'id' => (int) $activity->getKey(),
                'event' => (string) $activity->event,
                'description' => self::excerpt($activity->description, 180),
                'subject_type' => self::shortType((string) $activity->subject_type),
                'subject_id' => $activity->subject_id === null ? null : (int) $activity->subject_id,
                'project' => $activity->project?->key,
                'causer' => $activity->causer?->name,
                'causer_type' => $activity->causer_type?->value,
                'ai_run_id' => $activity->ai_run_id === null ? null : (int) $activity->ai_run_id,
                'created_at' => self::moment($activity->created_at, $timezone),
            ];
        }

        $data = $this->page($rows, $total, 'entries');
        $data['scope'] = $project === null
            ? ['type' => 'workspace', 'name' => (string) $ctx->workspace->name]
            : ['type' => 'project', 'id' => (int) $project->getKey(), 'key' => (string) $project->key];
        $data['since'] = $since?->toDateString();
        $data['actor'] = $actor;

        $data = $this->fit($data, 'entries');

        return ToolResult::ok(
            __('ai.tools.summary.activity', [
                'returned' => $data['returned'],
                'total' => $data['total'],
                'scope' => $project === null
                    ? __('ai.tools.labels.workspace_scope')
                    : $project->key.' '.$project->name,
            ]),
            $data,
            $project,
        );
    }

    /**
     * Narrow a workspace-wide feed to what this caller may see: entries belonging to a
     * visible project, plus workspace-level entries for everyone except guests.
     *
     * @param Builder<Activity> $query
     */
    private function restrictToVisibleFeed(Builder $query, AgentContext $ctx): void
    {
        $isGuest = $ctx->workspaceRole() === WorkspaceRole::Guest;
        $visible = $this->visibleProjectIds($ctx);

        $query->where(function (Builder $feed) use ($visible, $isGuest): void {
            $feed->whereIn('activities.project_id', $visible);

            if (! $isGuest) {
                $feed->orWhereNull('activities.project_id');
            }
        });
    }

    /**
     * `App\Models\Task` reaches the model as `Task`. The namespace tells it nothing and the
     * class path is an internal detail that has no business in a prompt.
     */
    private static function shortType(string $type): string
    {
        $position = mb_strrpos($type, '\\');

        return $position === false ? $type : mb_substr($type, $position + 1);
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Views;

use App\Enums\ViewType;
use App\Events\Views\SavedViewCreated;
use App\Exceptions\InvalidSavedView;
use App\Models\Project;
use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActivityLogger;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Save a list, board, calendar or timeline configuration.
 *
 * Ownership decides who sees it, and the two cases are deliberately distinct rows rather
 * than a flag on one: a personal view keeps `user_id` and belongs to that person, while a
 * shared one is `is_shared` and belongs to the workspace. Sharing a view therefore never
 * transfers somebody's private filters to the whole team by accident.
 *
 * `filters`, `sorts` and `columns` are stored as JSON exactly as given. They are read back
 * by the query builders that own their vocabulary, and this action does not pretend to
 * understand it — but it does normalise them to arrays, because a JSON column holding a
 * scalar breaks every consumer that iterates it.
 */
final class CreateSavedView
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
    ) {}

    /**
     * @param array<array-key, mixed> $filters
     * @param array<array-key, mixed>|null $sorts
     * @param array<array-key, mixed>|null $columns
     */
    public function __invoke(
        Workspace $workspace,
        User $owner,
        string $name,
        ViewType $type,
        array $filters = [],
        ?Project $project = null,
        ?array $sorts = null,
        ?array $columns = null,
        ?string $groupBy = null,
        bool $isShared = false,
        ?int $position = null,
    ): SavedView {
        $name = trim($name);

        if ($name === '') {
            throw InvalidSavedView::nameRequired();
        }

        $workspaceId = (int) $workspace->getKey();

        if ($project !== null && (int) $project->workspace_id !== $workspaceId) {
            throw InvalidSavedView::projectInAnotherWorkspace($project, $workspace);
        }

        $projectId = $project === null ? null : (int) $project->getKey();

        $view = DB::transaction(function () use (
            $workspaceId,
            $projectId,
            $owner,
            $name,
            $type,
            $filters,
            $sorts,
            $columns,
            $groupBy,
            $isShared,
            $position
        ): SavedView {
            $view = SavedView::query()->create([
                'workspace_id' => $workspaceId,
                'project_id' => $projectId,
                // A shared view still records who saved it, so the team can ask them about
                // it; `is_shared` alone decides who may see it.
                'user_id' => $owner->getKey(),
                'name' => mb_substr($name, 0, 255),
                'type' => $type,
                'filters' => $filters,
                'sorts' => $sorts,
                'columns' => $columns,
                'group_by' => $groupBy === null ? null : mb_substr(trim($groupBy), 0, 255),
                'is_shared' => $isShared,
                'is_pinned' => false,
                'position' => $position ?? self::nextPosition($workspaceId, $projectId, $owner, $isShared),
            ]);

            $this->activity->forUser($owner)->log($view, 'created', [
                'name' => $view->name,
                'type' => $type->value,
                'project_id' => $projectId,
                'is_shared' => $isShared,
            ]);

            return $view;
        });

        $this->events->dispatch(new SavedViewCreated($view, $owner));

        return $view;
    }

    public static function nextPosition(int $workspaceId, ?int $projectId, User $owner, bool $isShared): int
    {
        $query = SavedView::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId);

        $query = $projectId === null
            ? $query->whereNull('project_id')
            : $query->where('project_id', $projectId);

        $query = $isShared
            ? $query->where('is_shared', true)
            : $query->where('is_shared', false)->where('user_id', $owner->getKey());

        return (int) $query->max('position') + 1;
    }
}

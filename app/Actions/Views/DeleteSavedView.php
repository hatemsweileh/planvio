<?php

declare(strict_types=1);

namespace App\Actions\Views;

use App\Events\Views\SavedViewDeleted;
use App\Models\SavedView;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Discard a saved view.
 *
 * `saved_views` has no soft deletes — a view is a saved query, not a record of anything
 * that happened — so the activity row keeps enough of the definition to rebuild it by hand
 * if somebody deleted the team's board by mistake.
 */
final class DeleteSavedView
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
    ) {}

    public function __invoke(SavedView $view, User $actor): SavedView
    {
        if (! $view->exists) {
            return $view;
        }

        DB::transaction(function () use ($view, $actor): void {
            $this->activity->forUser($actor)->log($view, 'deleted', [
                'saved_view_id' => (int) $view->getKey(),
                'name' => (string) $view->name,
                'type' => $view->type->value,
                'project_id' => $view->project_id === null ? null : (int) $view->project_id,
                'is_shared' => (bool) $view->is_shared,
                'filters' => $view->filters,
                'sorts' => $view->sorts,
                'columns' => $view->columns,
                'group_by' => $view->group_by,
            ]);

            $view->delete();
        });

        $this->events->dispatch(new SavedViewDeleted($view, $actor));

        return $view;
    }
}

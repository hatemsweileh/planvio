<?php

declare(strict_types=1);

namespace App\Actions\Views;

use App\Events\Views\SavedViewPinned;
use App\Models\SavedView;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Pin a saved view to the sidebar, or take it down again.
 *
 * The state is passed rather than toggled. A toggle is not safe to repeat — two clicks from
 * a laggy sidebar, or a retried job, would land back where they started — while "make it
 * pinned" reaches the same result however many times it runs.
 *
 * A newly pinned view goes to the end of the pinned list so pinning something never
 * reshuffles what is already there.
 */
final class PinSavedView
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
    ) {}

    public function __invoke(SavedView $view, User $actor, bool $pinned = true): SavedView
    {
        if ((bool) $view->is_pinned === $pinned) {
            return $view;
        }

        DB::transaction(function () use ($view, $actor, $pinned): void {
            $view->is_pinned = $pinned;

            if ($pinned) {
                $view->position = self::nextPinnedPosition($view);
            }

            $view->save();

            $this->activity->forUser($actor)->log($view, $pinned ? 'pinned' : 'unpinned', [
                'saved_view_id' => (int) $view->getKey(),
                'name' => (string) $view->name,
            ]);
        });

        $this->events->dispatch(new SavedViewPinned($view, $actor, $pinned));

        return $view->refresh();
    }

    private static function nextPinnedPosition(SavedView $view): int
    {
        $query = SavedView::withoutWorkspaceScope()
            ->where('workspace_id', $view->workspace_id)
            ->where('is_pinned', true)
            ->whereKeyNot($view->getKey());

        $query = $view->project_id === null
            ? $query->whereNull('project_id')
            : $query->where('project_id', $view->project_id);

        $query = $view->is_shared
            ? $query->where('is_shared', true)
            : $query->where('is_shared', false)->where('user_id', $view->user_id);

        return (int) $query->max('position') + 1;
    }
}

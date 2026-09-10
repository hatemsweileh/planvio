<?php

declare(strict_types=1);

namespace App\Actions\Views;

use App\Enums\ViewType;
use App\Events\Views\SavedViewUpdated;
use App\Exceptions\InvalidSavedView;
use App\Models\SavedView;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Rewrite a saved view's configuration.
 *
 * A full replacement, matching the form that submits it: an omitted `sorts` or `columns`
 * clears that part of the view rather than leaving the previous value in place, so what is
 * on screen is always what gets stored.
 *
 * The project a view belongs to is not editable here. Moving a view between projects would
 * carry filters that name statuses, milestones and members of the old one — a move is a new
 * view, not an edit.
 */
final class UpdateSavedView
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
        SavedView $view,
        User $actor,
        string $name,
        ViewType $type,
        array $filters = [],
        ?array $sorts = null,
        ?array $columns = null,
        ?string $groupBy = null,
        ?bool $isShared = null,
    ): SavedView {
        $name = trim($name);

        if ($name === '') {
            throw InvalidSavedView::nameRequired();
        }

        $view->name = mb_substr($name, 0, 255);
        $view->type = $type;
        $view->filters = $filters;
        $view->sorts = $sorts;
        $view->columns = $columns;
        $view->group_by = $groupBy === null ? null : mb_substr(trim($groupBy), 0, 255);

        if ($isShared !== null) {
            $view->is_shared = $isShared;
        }

        $changes = ActivityLogger::changes($view);

        if ($changes === []) {
            return $view;
        }

        DB::transaction(function () use ($view, $actor, $changes): void {
            $view->save();

            $this->activity->forUser($actor)->log($view, 'updated', $changes);
        });

        $this->events->dispatch(new SavedViewUpdated($view, $actor, $changes));

        return $view->refresh();
    }
}

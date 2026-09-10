<?php

declare(strict_types=1);

namespace App\Livewire\App\Home;

use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The live change feed on the dashboard.
 *
 * It is its own component precisely so that it can poll. The dashboard around it is a dozen
 * aggregates; re-running all of them every half minute to refresh eight lines would be a
 * poor trade on the shared hosting Planvio targets. Here polling costs three queries: the
 * rows, their causers and their projects.
 *
 * Agent activity is marked, not blended. `causer_type` records whether a person acted
 * directly or through the agent, and the feed keeps the two visually apart — knowing at a
 * glance who moved your task is the difference between trusting the automation and quietly
 * turning it off.
 */
final class ActivityFeed extends Component
{
    private const ROWS = 8;

    public Workspace $workspace;

    public function mount(Workspace $workspace): void
    {
        $this->authorize('view', $workspace);

        $this->workspace = $workspace;
    }

    /**
     * Newest first, capped, and never showing work from a project the viewer cannot open.
     *
     * The project check is a correlated subquery rather than a second round trip: a guest
     * belongs to the workspace but only to some of its projects, and the feed is the one
     * place where every project's changes would otherwise meet.
     *
     * @return EloquentCollection<int, Activity>
     */
    #[Computed]
    public function activities(): EloquentCollection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return new EloquentCollection;
        }

        return Activity::query()
            ->forWorkspace($this->workspace)
            ->where(function (Builder $query) use ($user): void {
                $query->whereNull('activities.project_id')
                    ->orWhereHas('project', static fn (Builder $inner): Builder => $inner->visibleTo($user));
            })
            ->with([
                'causer:id,name,avatar_path',
                'project:id,name,slug,key,color',
            ])
            ->recent(self::ROWS)
            ->get();
    }

    /**
     * A one-line, human sentence for a row.
     *
     * `description` is written by the action that recorded the change and is preferred when
     * present; the fallback maps the event verb so a feed never shows a raw enum value.
     */
    public function summarise(Activity $activity): string
    {
        $description = $activity->description;

        if (is_string($description) && trim($description) !== '') {
            return $description;
        }

        $subject = $this->subjectLabel($activity);

        return match ($activity->event) {
            'created' => __('created :subject', ['subject' => $subject]),
            'updated' => __('updated :subject', ['subject' => $subject]),
            'deleted' => __('deleted :subject', ['subject' => $subject]),
            'restored' => __('restored :subject', ['subject' => $subject]),
            'status_changed' => __('moved :subject', ['subject' => $subject]),
            'assigned' => __('reassigned :subject', ['subject' => $subject]),
            'completed' => __('completed :subject', ['subject' => $subject]),
            'commented' => __('commented on :subject', ['subject' => $subject]),
            'archived' => __('archived :subject', ['subject' => $subject]),
            default => __(':event :subject', ['event' => str_replace('_', ' ', $activity->event), 'subject' => $subject]),
        };
    }

    /**
     * Where a row points. Tasks have their own page; everything else belongs to its project,
     * and an activity with neither is not a link at all.
     */
    public function urlFor(Activity $activity): ?string
    {
        if ($activity->subject_type === (new Task)->getMorphClass() && $activity->subject_id !== null) {
            return route('app.tasks.show', [$this->workspace, $activity->subject_id]);
        }

        $project = $activity->relationLoaded('project') ? $activity->getRelation('project') : null;

        return $project instanceof Project
            ? route('app.projects.show', [$this->workspace, $project])
            : null;
    }

    public function iconFor(Activity $activity): string
    {
        if ($activity->isFromAi()) {
            return 'icon.sparkles';
        }

        return match (true) {
            $activity->subject_type === (new Task)->getMorphClass() => 'icon.check-circle',
            $activity->subject_type === (new Project)->getMorphClass() => 'icon.folder',
            str_contains((string) $activity->subject_type, 'Milestone') => 'icon.flag',
            str_contains((string) $activity->subject_type, 'Comment') => 'icon.chat',
            str_contains((string) $activity->subject_type, 'WikiPage') => 'icon.document',
            str_contains((string) $activity->subject_type, 'Attachment') => 'icon.paperclip',
            default => 'icon.list',
        };
    }

    public function render(): View
    {
        return view('livewire.app.home.activity-feed');
    }

    /**
     * The noun a fallback sentence uses. Deliberately generic — the specific title lives on
     * the record, and fetching it for every row is the query-per-row this feed exists to
     * avoid.
     */
    private function subjectLabel(Activity $activity): string
    {
        return match (true) {
            $activity->subject_type === (new Task)->getMorphClass() => __('a task'),
            $activity->subject_type === (new Project)->getMorphClass() => __('a project'),
            str_contains((string) $activity->subject_type, 'Milestone') => __('a milestone'),
            str_contains((string) $activity->subject_type, 'Comment') => __('a comment'),
            str_contains((string) $activity->subject_type, 'WikiPage') => __('a document'),
            default => __('an item'),
        };
    }
}

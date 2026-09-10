<?php

declare(strict_types=1);

namespace App\Livewire\App\Inbox;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Everything addressed to one person, in one place.
 *
 * Rows render straight out of the stored payload. `PlanvioNotification::payload()` writes a
 * rendered title, body, actor and URL at send time precisely so an inbox never has to join
 * five subjects to draw five lines — fifty rows here cost two queries, not fifty.
 *
 * Three properties of the query are load-bearing rather than incidental:
 *
 *   - **Scoped to the reader.** Every read and every write is filtered by `notifiable_id`
 *     first. A notification id is a uuid, but "hard to guess" has never been an
 *     authorization model.
 *   - **This workspace, plus the account.** Notices that belong to no workspace — a new
 *     sign-in, a security change — are always included. A warning that is invisible because
 *     you happen to be looking at the wrong tenant is a warning that did not arrive.
 *   - **Counted once.** The tab counts come from a single grouped aggregate over unread
 *     rows, not from seven `count()` calls, so adding a tab costs nothing.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    use WithPagination;

    /**
     * The tabs, and the categories each one gathers. `all` is the absence of a filter, and
     * `ai` additionally sweeps up anything flagged as agent-caused whatever its category.
     *
     * @var array<string, list<string>>
     */
    private const FILTERS = [
        'all' => [],
        'mentions' => ['comment.mentioned'],
        'assigned' => ['task.assigned'],
        'comments' => ['comment.posted'],
        'updates' => [
            'task.due_soon',
            'task.overdue',
            'task.status_changed',
            'task.completed',
            'milestone.due_soon',
            'milestone.completed',
        ],
        'invitations' => ['workspace.invitation', 'project.invitation'],
        'ai' => ['ai.action_executed', 'ai.approval_required', 'ai.run_failed'],
    ];

    public Workspace $workspace;

    #[Url(except: 'all')]
    public string $filter = 'all';

    #[Url(as: 'unread', except: false)]
    public bool $unreadOnly = false;

    public function mount(Workspace $workspace): void
    {
        $this->authorize('view', $workspace);

        $this->workspace = $workspace;

        if (! array_key_exists($this->filter, self::FILTERS)) {
            $this->filter = 'all';
        }
    }

    /* ------------------------------------------------------------------ *
     * Filtering
     * ------------------------------------------------------------------ */

    public function updatedFilter(): void
    {
        if (! array_key_exists($this->filter, self::FILTERS)) {
            $this->filter = 'all';
        }

        $this->resetPage();
    }

    public function updatedUnreadOnly(): void
    {
        $this->resetPage();
    }

    public function selectFilter(string $filter): void
    {
        $this->filter = array_key_exists($filter, self::FILTERS) ? $filter : 'all';

        $this->resetPage();
    }

    /**
     * Back to the unfiltered view. One action rather than two `$set` calls, because a
     * half-applied reset is a state nobody asked for.
     */
    public function resetFilters(): void
    {
        $this->filter = 'all';
        $this->unreadOnly = false;

        $this->resetPage();
    }

    /**
     * The tab strip: key, label and unread count, in display order.
     *
     * @return list<array{key: string, label: string, count: int, icon: string}>
     */
    #[Computed]
    public function tabs(): array
    {
        $counts = $this->unreadCounts;

        $definitions = [
            ['key' => 'all', 'label' => __('All'), 'icon' => 'icon.inbox'],
            ['key' => 'mentions', 'label' => __('Mentions'), 'icon' => 'icon.chat'],
            ['key' => 'assigned', 'label' => __('Assigned'), 'icon' => 'icon.check-circle'],
            ['key' => 'comments', 'label' => __('Comments'), 'icon' => 'icon.chat'],
            ['key' => 'updates', 'label' => __('Updates'), 'icon' => 'icon.clock'],
            ['key' => 'invitations', 'label' => __('Invitations'), 'icon' => 'icon.users'],
            ['key' => 'ai', 'label' => __('AI'), 'icon' => 'icon.sparkles'],
        ];

        $tabs = [];

        foreach ($definitions as $definition) {
            $tabs[] = [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'icon' => $definition['icon'],
                'count' => $counts[$definition['key']] ?? 0,
            ];
        }

        return $tabs;
    }

    /**
     * Unread counts per tab, from one grouped aggregate.
     *
     * Grouping on `is_ai` as well as `category` is what lets the AI tab count exactly what
     * it filters: an assignment the agent made is an AI notification whatever its category
     * says.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function unreadCounts(): array
    {
        $user = $this->user();

        $counts = array_fill_keys(array_keys(self::FILTERS), 0);

        if (! $user instanceof User) {
            return $counts;
        }

        $rows = $this->scoped($user)
            ->whereNull('read_at')
            ->groupBy('category', 'is_ai')
            ->selectRaw('category, is_ai, count(*) as total')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $category = (string) ($row->category ?? '');
            $total = (int) $row->total;
            $isAi = (bool) $row->is_ai;

            $counts['all'] += $total;

            foreach (self::FILTERS as $key => $categories) {
                if ($key === 'all' || $categories === []) {
                    continue;
                }

                if (in_array($category, $categories, true)) {
                    $counts[$key] += $total;
                }
            }

            if ($isAi && ! in_array($category, self::FILTERS['ai'], true)) {
                $counts['ai'] += $total;
            }
        }

        return $counts;
    }

    public function unreadTotal(): int
    {
        return $this->unreadCounts['all'] ?? 0;
    }

    /**
     * @return LengthAwarePaginator<int, DatabaseNotification>
     */
    #[Computed]
    public function notifications(): LengthAwarePaginator
    {
        $user = $this->user();

        if (! $user instanceof User) {
            /** @var LengthAwarePaginator<int, DatabaseNotification> $empty */
            $empty = DatabaseNotification::query()->whereRaw('1 = 0')->paginate(1);

            return $empty;
        }

        return $this->filtered($user)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) config('planvio.pagination.list', 50));
    }

    /* ------------------------------------------------------------------ *
     * Reading
     * ------------------------------------------------------------------ */

    public function markRead(string $id): void
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return;
        }

        $this->scoped($user)->whereKey($id)->whereNull('read_at')->update(['read_at' => Carbon::now()]);

        $this->refresh();
    }

    public function markUnread(string $id): void
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return;
        }

        $this->scoped($user)->whereKey($id)->whereNotNull('read_at')->update(['read_at' => null]);

        $this->refresh();
    }

    /**
     * Marks everything the current filter is showing. Filtered to mentions, it clears
     * mentions — clearing the whole inbox from a filtered view is the kind of surprise
     * people stop trusting a button over.
     */
    public function markAllRead(): void
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return;
        }

        $affected = $this->filtered($user, ignoreUnreadToggle: true)
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now()]);

        $this->refresh();

        $this->dispatch(
            'planvio-notify',
            type: 'success',
            message: $affected === 0
                ? __('Nothing was unread.')
                : trans_choice(
                    '{1}:count notification marked as read.|[2,*]:count notifications marked as read.',
                    $affected,
                    ['count' => $affected],
                ),
        );
    }

    /* ------------------------------------------------------------------ *
     * Presentation helpers
     * ------------------------------------------------------------------ */

    /**
     * The glyph for a category, so the list is scannable without reading it.
     */
    public function iconFor(?string $category, bool $isAi = false): string
    {
        if ($isAi || ($category !== null && str_starts_with($category, 'ai.'))) {
            return 'icon.sparkles';
        }

        return match (true) {
            $category === null => 'icon.bell',
            str_starts_with($category, 'task.') => 'icon.check-circle',
            str_starts_with($category, 'comment.') => 'icon.chat',
            str_starts_with($category, 'milestone.') => 'icon.flag',
            str_starts_with($category, 'project.') => 'icon.folder',
            str_starts_with($category, 'workspace.') => 'icon.users',
            default => 'icon.bell',
        };
    }

    /**
     * A short, human name for the category, shown on the row so a reader can tell an
     * assignment from a mention without opening either.
     */
    public function categoryLabel(?string $category): string
    {
        return match ($category) {
            'comment.mentioned' => __('Mention'),
            'comment.posted' => __('Comment'),
            'task.assigned' => __('Assigned'),
            'task.due_soon' => __('Due soon'),
            'task.overdue' => __('Overdue'),
            'task.status_changed' => __('Status'),
            'task.completed' => __('Completed'),
            'milestone.due_soon' => __('Milestone due'),
            'milestone.completed' => __('Milestone'),
            'workspace.invitation' => __('Invitation'),
            'project.invitation' => __('Invitation'),
            'ai.action_executed' => __('AI action'),
            'ai.approval_required' => __('Needs approval'),
            'ai.run_failed' => __('AI run failed'),
            default => __('Notice'),
        };
    }

    /**
     * The day heading a row sits under.
     */
    public function bucket(?Carbon $at): string
    {
        if ($at === null) {
            return __('Earlier');
        }

        return match (true) {
            $at->isToday() => __('Today'),
            $at->isYesterday() => __('Yesterday'),
            $at->greaterThan(Carbon::now()->subWeek()) => __('This week'),
            $at->greaterThan(Carbon::now()->subMonth()) => __('This month'),
            default => __('Earlier'),
        };
    }

    /**
     * The empty state's words, which differ per tab: an empty mentions tab is a different
     * fact from an empty inbox, and saying the same thing for both is how a product starts
     * feeling generic.
     *
     * @return array{title: string, description: string}
     */
    public function emptyCopy(): array
    {
        if ($this->unreadOnly) {
            return [
                'title' => __('Nothing unread'),
                'description' => __('Everything in this view has been read. Switch the filter off to see the history.'),
            ];
        }

        return match ($this->filter) {
            'mentions' => [
                'title' => __('No one has mentioned you'),
                'description' => __('Type @ and a name in any comment to pull someone in. Their reply lands back here.'),
            ],
            'assigned' => [
                'title' => __('Nothing has been assigned to you'),
                'description' => __('When a colleague or the assistant puts a task in your name, it arrives here first.'),
            ],
            'comments' => [
                'title' => __('No comments yet'),
                'description' => __('Replies on the tasks and documents you follow collect here.'),
            ],
            'updates' => [
                'title' => __('No updates'),
                'description' => __('Due dates, status changes and milestone news about your work show up here.'),
            ],
            'invitations' => [
                'title' => __('No invitations'),
                'description' => __('Invitations to a workspace or to a single project appear here until you accept them.'),
            ],
            'ai' => [
                'title' => __('The assistant has nothing to report'),
                'description' => __('Actions the agent takes, and anything it needs approved, are announced here.'),
            ],
            default => [
                'title' => __('Your inbox is clear'),
                'description' => __('Mentions, assignments, comments and invitations arrive here. An empty inbox means you are up to date.'),
            ],
        };
    }

    public function render(): View
    {
        return view('livewire.app.inbox.index')->title(__('Inbox'));
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    private function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private function refresh(): void
    {
        unset($this->notifications, $this->unreadCounts, $this->tabs);
    }

    /**
     * This reader's rows, for this workspace plus the account-level ones.
     *
     * @return Builder<DatabaseNotification>
     */
    private function scoped(User $user): Builder
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->where(function (Builder $query): void {
                $query->where('workspace_id', $this->workspace->getKey())->orWhereNull('workspace_id');
            });
    }

    /**
     * @return Builder<DatabaseNotification>
     */
    private function filtered(User $user, bool $ignoreUnreadToggle = false): Builder
    {
        $categories = self::FILTERS[$this->filter] ?? [];

        return $this->scoped($user)
            ->when(
                $categories !== [] && $this->filter !== 'ai',
                static fn (Builder $query): Builder => $query->whereIn('category', $categories),
            )
            ->when(
                $this->filter === 'ai',
                static fn (Builder $query): Builder => $query->where(
                    static fn (Builder $inner): Builder => $inner
                        ->whereIn('category', self::FILTERS['ai'])
                        ->orWhere('is_ai', true),
                ),
            )
            ->when(
                $this->unreadOnly && ! $ignoreUnreadToggle,
                static fn (Builder $query): Builder => $query->whereNull('read_at'),
            );
    }
}

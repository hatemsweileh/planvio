<?php

declare(strict_types=1);

namespace App\Livewire\App\Shared;

use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The bell in the header.
 *
 * Closed, it costs nothing: the unread number is shared once per request by
 * SetCurrentWorkspace along with the rest of the shell, and the list itself is not fetched
 * until somebody opens the menu. A header that fetched fifteen notifications on every page
 * load would be paying for a panel almost nobody opens on almost every page.
 *
 * Rows render straight out of the stored JSON. `PlanvioNotification::payload()` writes a
 * rendered title, body and URL at send time precisely so an inbox never has to join fifteen
 * subjects to draw fifteen lines.
 *
 * AI-authored notifications are marked and tinted. Knowing at a glance whether a colleague
 * or the agent moved your task is not decoration — it is the difference between trusting
 * the automation and quietly turning it off.
 */
final class NotificationsMenu extends Component
{
    /** Enough to answer "what happened while I was away" without becoming a page. */
    private const LIMIT = 15;

    /**
     * Shared by the middleware and handed in by the layout, so the badge is already correct
     * on first paint.
     */
    public int $unread = 0;

    /**
     * Flipped by the first open. Until then `notifications()` returns nothing and the
     * component issues no query at all.
     */
    public bool $loaded = false;

    public function mount(int $unread = 0): void
    {
        $this->unread = max(0, $unread);
    }

    public function load(): void
    {
        $this->loaded = true;
    }

    /**
     * @return Collection<int, DatabaseNotification>
     */
    #[Computed]
    public function notifications(): Collection
    {
        if (! $this->loaded) {
            return new Collection;
        }

        $user = auth()->user();

        if (! $user instanceof User) {
            return new Collection;
        }

        return $this->scoped($user)
            ->orderByDesc('created_at')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * Rows in the order they are shown, bucketed by day.
     *
     * @return array<string, Collection<int, DatabaseNotification>>
     */
    #[Computed]
    public function grouped(): array
    {
        $groups = [];

        foreach ($this->notifications as $notification) {
            $groups[$this->bucket($notification->created_at)][] = $notification;
        }

        return array_map(
            static fn (array $rows): Collection => new Collection($rows),
            $groups,
        );
    }

    public function markRead(string $id): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        // Scoped to the acting user's own rows: an id is a uuid, but "hard to guess" is not
        // an authorization model.
        $affected = $this->scoped($user)
            ->whereKey($id)
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now()]);

        if ($affected > 0) {
            $this->unread = max(0, $this->unread - $affected);
            unset($this->notifications, $this->grouped);
        }
    }

    public function markAllRead(): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $this->scoped($user)->whereNull('read_at')->update(['read_at' => Carbon::now()]);

        $this->unread = 0;
        unset($this->notifications, $this->grouped);

        $this->dispatch('planvio-notify', type: 'success', message: __('Everything marked as read.'));
    }

    /**
     * The glyph for a notification category, so the list is scannable without reading it.
     */
    public function iconFor(?string $category): string
    {
        return match (true) {
            $category === null => 'icon.bell',
            str_starts_with($category, 'ai.') => 'icon.sparkles',
            str_starts_with($category, 'task.') => 'icon.check-circle',
            str_starts_with($category, 'comment.') => 'icon.chat',
            str_starts_with($category, 'milestone.') => 'icon.flag',
            str_starts_with($category, 'project.') => 'icon.folder',
            str_starts_with($category, 'workspace.') => 'icon.users',
            default => 'icon.bell',
        };
    }

    public function render(): View
    {
        return view('livewire.app.shared.notifications-menu');
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    /**
     * This user's notifications for the workspace they are in, plus the account-level ones
     * that belong to no workspace — a security notice must not be invisible because of
     * which tenant happens to be open.
     *
     * @return Builder<DatabaseNotification>
     */
    private function scoped(User $user)
    {
        $workspace = app(CurrentWorkspace::class)->get();

        return DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->when(
                $workspace instanceof Workspace,
                static fn ($query) => $query->where(static function ($query) use ($workspace): void {
                    $query->where('workspace_id', $workspace->getKey())->orWhereNull('workspace_id');
                }),
            );
    }

    private function bucket(?Carbon $at): string
    {
        if ($at === null) {
            return __('Earlier');
        }

        return match (true) {
            $at->isToday() => __('Today'),
            $at->isYesterday() => __('Yesterday'),
            $at->greaterThan(Carbon::now()->subWeek()) => __('This week'),
            default => __('Earlier'),
        };
    }
}

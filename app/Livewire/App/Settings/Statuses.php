<?php

declare(strict_types=1);

namespace App\Livewire\App\Settings;

use App\Enums\StatusCategory;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The lifecycle every project in the workspace moves through.
 *
 * Project statuses are workspace-level and shared, which is the point: "Active" has to mean
 * the same thing on the portfolio board as it does inside a project. Task statuses are a
 * different table and belong to a project, so they are not edited here.
 *
 * A status in use is never silently removed. Deleting one asks where its projects should go
 * and moves them, because the alternative — a project pointing at a row that no longer
 * exists — is a portfolio view with a hole in it.
 */
final class Statuses extends Component
{
    /** The badge palette, which is also what the enums emit. */
    private const COLORS = ['gray', 'blue', 'brand', 'purple', 'teal', 'green', 'amber', 'orange', 'red', 'pink'];

    public Workspace $workspace;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $color = 'blue';

    public string $category = 'todo';

    public bool $isDefault = false;

    /** The status a deletion moves orphaned projects to. */
    public ?int $reassignTo = null;

    public ?int $deletingId = null;

    public function mount(Workspace $workspace): void
    {
        $this->authorize('viewAny', [ProjectStatus::class, $workspace]);

        $this->workspace = $workspace;
    }

    /**
     * @return Collection<int, ProjectStatus>
     */
    #[Computed]
    public function statuses(): Collection
    {
        return ProjectStatus::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->withCount('projects')
            ->ordered()
            ->get();
    }

    /**
     * @return list<string>
     */
    public function colors(): array
    {
        return self::COLORS;
    }

    public function startCreate(): void
    {
        $this->authorize('create', [ProjectStatus::class, $this->workspace]);

        $this->editingId = null;
        $this->name = '';
        $this->color = 'blue';
        $this->category = StatusCategory::Todo->value;
        $this->isDefault = false;
        $this->showForm = true;

        $this->resetValidation();
    }

    public function startEdit(int $statusId): void
    {
        $status = $this->status($statusId);

        if ($status === null) {
            return;
        }

        $this->authorize('update', $status);

        $this->editingId = (int) $status->getKey();
        $this->name = (string) $status->name;
        $this->color = (string) $status->color;
        $this->category = ($status->category ?? StatusCategory::Todo)->value;
        $this->isDefault = (bool) $status->is_default;
        $this->showForm = true;

        $this->resetValidation();
    }

    public function save(ActivityLogger $activity): void
    {
        $status = $this->editingId === null ? null : $this->status($this->editingId);

        if ($status === null) {
            $this->authorize('create', [ProjectStatus::class, $this->workspace]);
        } else {
            $this->authorize('update', $status);
        }

        $data = $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'color' => ['required', Rule::in(self::COLORS)],
            'category' => ['required', Rule::in(array_column(StatusCategory::cases(), 'value'))],
            'isDefault' => ['boolean'],
        ]);

        $actor = $this->actor();

        DB::transaction(function () use ($status, $data, $activity, $actor): void {
            $status ??= new ProjectStatus([
                'workspace_id' => $this->workspace->getKey(),
                'position' => ($this->statuses->max('position') ?? -1) + 1,
            ]);

            $creating = ! $status->exists;

            $status->workspace_id = $this->workspace->getKey();
            $status->name = $data['name'];
            $status->color = $data['color'];
            $status->category = StatusCategory::from($data['category']);
            $status->is_default = (bool) $data['isDefault'];
            $status->save();

            // Exactly one default, always: two would make "the status a new project starts
            // in" a coin toss.
            if ($status->is_default) {
                ProjectStatus::query()
                    ->where('workspace_id', $this->workspace->getKey())
                    ->whereKeyNot($status->getKey())
                    ->update(['is_default' => false]);
            }

            $activity->forUser($actor)->log($status, $creating ? 'created' : 'updated', [
                'name' => $status->name,
                'category' => $status->category->value,
                'is_default' => (bool) $status->is_default,
            ]);
        });

        $this->showForm = false;
        $this->editingId = null;

        unset($this->statuses);

        $this->dispatch('planvio-notify', type: 'success', message: __('Project statuses updated.'));
    }

    public function startDelete(int $statusId): void
    {
        $status = $this->status($statusId);

        if ($status === null) {
            return;
        }

        $this->authorize('delete', $status);

        $this->deletingId = (int) $status->getKey();
        $this->reassignTo = $this->statuses
            ->reject(fn (ProjectStatus $row): bool => (int) $row->getKey() === $this->deletingId)
            ->first()?->getKey();
    }

    public function cancelDelete(): void
    {
        $this->deletingId = null;
        $this->reassignTo = null;
    }

    public function confirmDelete(ActivityLogger $activity): void
    {
        $status = $this->deletingId === null ? null : $this->status($this->deletingId);

        if ($status === null) {
            return;
        }

        $this->authorize('delete', $status);

        $destination = $this->reassignTo === null ? null : $this->status((int) $this->reassignTo);

        if ($destination !== null && (int) $destination->getKey() === (int) $status->getKey()) {
            $destination = null;
        }

        $actor = $this->actor();

        DB::transaction(function () use ($status, $destination, $activity, $actor): void {
            Project::query()
                ->where('workspace_id', $this->workspace->getKey())
                ->where('status_id', $status->getKey())
                ->update(['status_id' => $destination?->getKey()]);

            $activity->forUser($actor)->log($status, 'deleted', [
                'name' => (string) $status->name,
                'moved_to_status_id' => $destination === null ? null : (int) $destination->getKey(),
            ]);

            $status->delete();

            // A workspace with no default cannot start a project, so the first remaining
            // status inherits the flag.
            $remaining = ProjectStatus::query()
                ->where('workspace_id', $this->workspace->getKey())
                ->ordered()
                ->get();

            if ($remaining->isNotEmpty() && $remaining->where('is_default', true)->isEmpty()) {
                $remaining->first()->forceFill(['is_default' => true])->save();
            }
        });

        $this->deletingId = null;
        $this->reassignTo = null;

        unset($this->statuses);

        $this->dispatch('planvio-notify', type: 'success', message: __('Status deleted.'));
    }

    /**
     * @param array<int, int|string> $ids
     */
    public function reorderStatuses(array $ids): void
    {
        $ids = array_values(array_filter(array_map(intval(...), $ids)));

        if ($ids === []) {
            return;
        }

        $statuses = ProjectStatus::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->whereIn('id', $ids)
            ->get();

        foreach ($statuses as $status) {
            $this->authorize('reorder', $status);
        }

        DB::transaction(function () use ($ids): void {
            $position = 0;

            foreach ($ids as $id) {
                ProjectStatus::query()
                    ->where('workspace_id', $this->workspace->getKey())
                    ->whereKey($id)
                    ->update(['position' => $position++]);
            }
        });

        unset($this->statuses);
    }

    public function render(): View
    {
        return view('livewire.app.settings.statuses');
    }

    private function status(int $statusId): ?ProjectStatus
    {
        return ProjectStatus::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->whereKey($statusId)
            ->first();
    }

    private function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}

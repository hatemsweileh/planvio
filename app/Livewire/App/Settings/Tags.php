<?php

declare(strict_types=1);

namespace App\Livewire\App\Settings;

use App\Actions\Tags\CreateTag;
use App\Actions\Tags\DeleteTag;
use App\Actions\Tags\TagChanges;
use App\Actions\Tags\UpdateTag;
use App\Exceptions\DomainException;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Shared labels. One vocabulary per workspace, deliberately: a tag that means one thing on
 * a task and another on a project is not a tag, it is a coincidence.
 *
 * Every write goes through `App\Actions\Tags`, which owns slug uniqueness and the activity
 * trail; this class authorizes and validates the shape of the request.
 */
final class Tags extends Component
{
    private const COLORS = ['gray', 'blue', 'brand', 'purple', 'teal', 'green', 'amber', 'orange', 'red', 'pink'];

    public Workspace $workspace;

    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $color = 'gray';

    public string $description = '';

    public function mount(Workspace $workspace): void
    {
        $this->authorize('viewAny', [Tag::class, $workspace]);

        $this->workspace = $workspace;
    }

    /**
     * @return Collection<int, Tag>
     */
    #[Computed]
    public function tags(): Collection
    {
        return Tag::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->when($this->search !== '', fn ($query) => $query->where(
                'name',
                'like',
                '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%',
            ))
            ->withCount(['tasks', 'projects'])
            ->orderBy('name')
            ->limit(300)
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
        $this->authorize('create', [Tag::class, $this->workspace]);

        $this->editingId = null;
        $this->name = '';
        $this->color = 'gray';
        $this->description = '';
        $this->showForm = true;

        $this->resetValidation();
    }

    public function startEdit(int $tagId): void
    {
        $tag = $this->tag($tagId);

        if ($tag === null) {
            return;
        }

        $this->authorize('update', $tag);

        $this->editingId = (int) $tag->getKey();
        $this->name = (string) $tag->name;
        $this->color = (string) ($tag->color ?: 'gray');
        $this->description = (string) $tag->description;
        $this->showForm = true;

        $this->resetValidation();
    }

    public function save(CreateTag $createTag, UpdateTag $updateTag): void
    {
        $tag = $this->editingId === null ? null : $this->tag($this->editingId);

        if ($tag === null) {
            $this->authorize('create', [Tag::class, $this->workspace]);
        } else {
            $this->authorize('update', $tag);
        }

        $data = $this->validate([
            'name' => ['required', 'string', 'min:1', 'max:60'],
            'color' => ['required', Rule::in(self::COLORS)],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            if ($tag === null) {
                $createTag(
                    workspace: $this->workspace,
                    name: $data['name'],
                    actor: $this->actor(),
                    color: $data['color'],
                    description: $data['description'] === '' ? null : $data['description'],
                );
            } else {
                $updateTag(
                    $tag,
                    TagChanges::make()
                        ->name($data['name'])
                        ->color($data['color'])
                        ->description($data['description'] === '' ? null : $data['description']),
                    $this->actor(),
                );
            }
        } catch (DomainException $failure) {
            $this->addError('name', $failure->userMessage());

            return;
        }

        $this->showForm = false;
        $this->editingId = null;

        unset($this->tags);

        $this->dispatch('planvio-notify', type: 'success', message: __('Tags updated.'));
    }

    public function deleteTag(int $tagId, DeleteTag $deleteTag): void
    {
        $tag = $this->tag($tagId);

        if ($tag === null) {
            return;
        }

        $this->authorize('delete', $tag);

        $deleteTag($tag, $this->actor());

        unset($this->tags);

        $this->dispatch('planvio-notify', type: 'success', message: __('“:tag” was deleted.', [
            'tag' => $tag->name,
        ]));
    }

    public function render(): View
    {
        return view('livewire.app.settings.tags');
    }

    private function tag(int $tagId): ?Tag
    {
        return Tag::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->whereKey($tagId)
            ->first();
    }

    private function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}

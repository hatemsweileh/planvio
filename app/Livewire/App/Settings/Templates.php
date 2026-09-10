<?php

declare(strict_types=1);

namespace App\Livewire\App\Settings;

use App\Enums\ProjectType;
use App\Models\ProjectTemplate;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Project templates: the blueprints a new project can start from.
 *
 * Two tiers live in one list. System templates ship with Planvio, belong to no workspace and
 * cannot be edited — but any of them can be copied into this workspace, which is how a
 * bespoke template starts: from something that already works rather than from an empty JSON
 * document. A copy is fully editable and can be switched off without being deleted.
 *
 * The blueprint itself — statuses, milestones, seed tasks, tags, views — is authored by
 * saving a project as a template from the project's own screen. This page is where they are
 * named, described, retired and removed.
 */
final class Templates extends Component
{
    public Workspace $workspace;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $description = '';

    public string $icon = '';

    public string $type = 'general';

    public function mount(Workspace $workspace): void
    {
        $this->authorize('viewAny', [ProjectTemplate::class, $workspace]);

        $this->workspace = $workspace;
    }

    /**
     * Templates this workspace authored.
     *
     * @return Collection<int, ProjectTemplate>
     */
    #[Computed]
    public function own(): Collection
    {
        return ProjectTemplate::query()
            ->forWorkspace($this->workspace)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, ProjectTemplate>
     */
    #[Computed]
    public function system(): Collection
    {
        return ProjectTemplate::query()
            ->system()
            ->active()
            ->orderBy('name')
            ->get();
    }

    /**
     * What a template will actually create, as a short line of counts.
     *
     * @return array<string, int>
     */
    public function contents(ProjectTemplate $template): array
    {
        return [
            'statuses' => count($template->section('statuses')),
            'milestones' => count($template->section('milestones')),
            'tasks' => count($template->section('tasks')),
            'tags' => count($template->section('tags')),
            'views' => count($template->section('views')),
        ];
    }

    public function startEdit(int $templateId): void
    {
        $template = $this->ownTemplate($templateId);

        if ($template === null) {
            return;
        }

        $this->authorize('update', $template);

        $this->editingId = (int) $template->getKey();
        $this->name = (string) $template->name;
        $this->description = (string) $template->description;
        $this->icon = (string) $template->icon;
        $this->type = ($template->type ?? ProjectType::General)->value;
        $this->showForm = true;

        $this->resetValidation();
    }

    public function save(ActivityLogger $activity): void
    {
        $template = $this->editingId === null ? null : $this->ownTemplate($this->editingId);

        if ($template === null) {
            return;
        }

        $this->authorize('update', $template);

        $data = $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'icon' => ['nullable', 'string', 'max:8'],
            'type' => ['required', Rule::in(array_column(ProjectType::cases(), 'value'))],
        ]);

        $template->name = $data['name'];
        $template->description = $data['description'] === '' ? null : $data['description'];
        $template->icon = $data['icon'] === '' ? null : $data['icon'];
        $template->type = ProjectType::from($data['type']);
        $template->save();

        $activity->forUser($this->actor())->log($template, 'updated', ['name' => $template->name]);

        $this->showForm = false;
        $this->editingId = null;

        unset($this->own);

        $this->dispatch('planvio-notify', type: 'success', message: __('Template saved.'));
    }

    /**
     * Copy a template into this workspace so it can be edited.
     *
     * The definition is copied wholesale rather than referenced: a system template is part
     * of the product and changes with a release, and a workspace's own blueprint must not
     * change underneath it when Planvio is upgraded.
     */
    public function duplicate(int $templateId, ActivityLogger $activity): void
    {
        $template = ProjectTemplate::query()
            ->availableIn($this->workspace)
            ->whereKey($templateId)
            ->first();

        if (! $template instanceof ProjectTemplate) {
            return;
        }

        $this->authorize('duplicate', $template);
        $this->authorize('create', [ProjectTemplate::class, $this->workspace]);

        $name = __('Copy of :name', ['name' => $template->name]);

        $copy = ProjectTemplate::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => mb_substr($name, 0, 80),
            'slug' => $this->uniqueSlug($name),
            'description' => $template->description,
            'icon' => $template->icon,
            'color' => $template->color,
            'type' => $template->type,
            'definition' => $template->definition,
            'is_system' => false,
            'is_active' => true,
        ]);

        $activity->forUser($this->actor())->log($copy, 'created', [
            'copied_from' => (int) $template->getKey(),
            'name' => (string) $copy->name,
        ]);

        unset($this->own);

        $this->dispatch('planvio-notify', type: 'success', message: __('“:name” is now yours to edit.', [
            'name' => $copy->name,
        ]));
    }

    public function toggleActive(int $templateId, ActivityLogger $activity): void
    {
        $template = $this->ownTemplate($templateId);

        if ($template === null) {
            return;
        }

        $this->authorize('update', $template);

        $template->is_active = ! $template->is_active;
        $template->save();

        $activity->forUser($this->actor())->log($template, 'updated', [
            'is_active' => (bool) $template->is_active,
        ]);

        unset($this->own);

        $this->dispatch('planvio-notify', type: 'success', message: $template->is_active
            ? __('“:name” is offered again when creating a project.', ['name' => $template->name])
            : __('“:name” is retired. Projects already made from it are untouched.', ['name' => $template->name]));
    }

    public function deleteTemplate(int $templateId, ActivityLogger $activity): void
    {
        $template = $this->ownTemplate($templateId);

        if ($template === null) {
            return;
        }

        $this->authorize('delete', $template);

        $activity->forUser($this->actor())->log($template, 'deleted', ['name' => (string) $template->name]);

        $template->delete();

        unset($this->own);

        $this->dispatch('planvio-notify', type: 'success', message: __('Template deleted.'));
    }

    public function render(): View
    {
        return view('livewire.app.settings.templates');
    }

    private function ownTemplate(int $templateId): ?ProjectTemplate
    {
        return ProjectTemplate::query()
            ->forWorkspace($this->workspace)
            ->whereKey($templateId)
            ->first();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'template';
        $slug = $base;
        $suffix = 2;

        while (ProjectTemplate::query()->availableIn($this->workspace)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return mb_substr($slug, 0, 80);
    }

    private function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}

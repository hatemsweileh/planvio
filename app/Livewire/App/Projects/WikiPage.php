<?php

declare(strict_types=1);

namespace App\Livewire\App\Projects;

use App\Actions\Attachments\DeleteAttachment;
use App\Actions\Attachments\StoreAttachment;
use App\Actions\Wiki\UpdateWikiPage;
use App\Enums\WikiVisibility;
use App\Exceptions\DomainException;
use App\Exceptions\UploadRejected;
use App\Livewire\App\Projects\Concerns\ManagesWikiTree;
use App\Models\AiSetting;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\WikiPage as Page;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * One document: read it, edit it, see who last touched it and what is attached to it.
 *
 * Reading and editing are the same screen rather than two routes. The editor is TipTap
 * (the shared `richEditor` Alpine component) and what it produces is treated as untrusted:
 * the server runs every save back through {@see UpdateWikiPage}, which sanitises the HTML
 * before it is stored. Nothing the browser sends is written as-is.
 */
#[Layout('layouts.app')]
final class WikiPage extends Component
{
    use ManagesWikiTree;
    use WithFileUploads;

    public Workspace $workspace;

    public Project $project;

    public Page $page;

    public bool $editing = false;

    public string $title = '';

    public string $content = '';

    public string $visibility = 'project';

    public bool $confirmingDelete = false;

    /** @var TemporaryUploadedFile|null */
    public $upload = null;

    public function mount(Workspace $workspace, Project $project, Page $page): void
    {
        $this->authorize('view', $project);
        $this->authorize('view', $page);

        $this->workspace = $workspace;
        $this->project = $project;
        $this->page = $page;

        $this->fillFromPage();
    }

    /* ------------------------------------------------------------------ *
     * Reads
     * ------------------------------------------------------------------ */

    /**
     * Root-first ancestors, ending with this page.
     *
     * Walked over the rail's own flat page list rather than through `parent` relations:
     * the tree is already in memory, and following the relation would issue one query per
     * level — which, with lazy loading refused outside production, is an exception rather
     * than a slow page.
     *
     * @return array<int, Page>
     */
    #[Computed]
    public function breadcrumb(): array
    {
        $pages = Page::query()
            ->forProject($this->project)
            ->visibleTo($this->actor())
            ->get(['id', 'parent_id', 'title', 'slug'])
            ->keyBy(fn (Page $page): int => (int) $page->getKey());

        $trail = [$this->page];
        $seen = [(int) $this->page->getKey() => true];
        $parentId = $this->page->parent_id === null ? null : (int) $this->page->parent_id;

        // The tree is user-editable, so a parent cycle is reachable; stop instead of looping.
        while ($parentId !== null && ! isset($seen[$parentId])) {
            $parent = $pages->get($parentId);

            if (! $parent instanceof Page) {
                break;
            }

            $seen[$parentId] = true;
            array_unshift($trail, $parent);

            $parentId = $parent->parent_id === null ? null : (int) $parent->parent_id;
        }

        return $trail;
    }

    /**
     * @return Collection<int, Attachment>
     */
    #[Computed]
    public function attachments(): Collection
    {
        return Attachment::query()
            ->forAttachable($this->page)
            ->with('uploader:id,name,avatar_path')
            ->latest('id')
            ->get();
    }

    public function canEdit(): bool
    {
        return Gate::allows('update', $this->page);
    }

    /**
     * Whether the AI actions on this page are worth offering: the acting user may use the
     * agent, and the workspace's AI layer is actually able to run (ARCHITECTURE.md §7.7).
     */
    #[Computed]
    public function aiAvailable(): bool
    {
        if (! Gate::allows('ai.use', $this->workspace)) {
            return false;
        }

        return AiSetting::forWorkspace($this->workspace)?->isUsable() === true;
    }

    /* ------------------------------------------------------------------ *
     * Editing
     * ------------------------------------------------------------------ */

    public function startEditing(): void
    {
        $this->authorize('update', $this->page);

        $this->fillFromPage();
        $this->editing = true;
        $this->resetValidation();
    }

    public function cancelEditing(): void
    {
        $this->fillFromPage();
        $this->editing = false;
        $this->resetValidation();
    }

    public function save(UpdateWikiPage $updateWikiPage): void
    {
        $this->authorize('update', $this->page);

        $data = $this->validate([
            'title' => ['required', 'string', 'min:1', 'max:255'],
            'content' => ['nullable', 'string', 'max:400000'],
            'visibility' => ['required', 'string', 'in:project,workspace,private'],
        ]);

        $slugChanges = $this->page->title !== $data['title'];

        try {
            $page = $updateWikiPage(
                page: $this->page,
                editor: $this->actor(),
                title: $data['title'],
                // The editor sends an empty document as an empty string; stored as null it
                // is honestly "no content" rather than a paragraph containing nothing.
                content: trim($data['content']) === '' ? null : $data['content'],
                visibility: WikiVisibility::from($data['visibility']),
                reslug: $slugChanges,
            );
        } catch (DomainException $failure) {
            $this->addError('title', $failure->userMessage());

            return;
        }

        $this->page = $page->fresh() ?? $page;
        $this->editing = false;

        unset($this->tree, $this->parentOptions, $this->breadcrumb);

        $this->dispatch('planvio-notify', type: 'success', message: __('Page saved.'));

        // The slug is part of the address, so a retitled page has to be re-addressed or the
        // next refresh lands on a URL that no longer resolves.
        if ($slugChanges) {
            $this->redirect(
                route('app.projects.wiki.show', [$this->workspace, $this->project, $this->page]),
                navigate: true,
            );
        }
    }

    /* ------------------------------------------------------------------ *
     * Attachments
     * ------------------------------------------------------------------ */

    public function updatedUpload(): void
    {
        $this->storeUpload();
    }

    public function storeUpload(): void
    {
        if (! $this->upload instanceof TemporaryUploadedFile) {
            return;
        }

        $this->authorize('create', [Attachment::class, $this->page]);

        $file = $this->upload;
        $this->upload = null;

        // No parallel client-side allow-list: StoreAttachment is the gate, it enforces the
        // extension, the sniffed type and the size limit together, and its refusal names
        // the control that fired.
        try {
            app(StoreAttachment::class)($this->page, $file, $this->actor());
        } catch (UploadRejected $rejected) {
            $this->dispatch('planvio-notify', type: 'error', message: $rejected->userMessage());

            return;
        }

        unset($this->attachments);

        $this->dispatch('planvio-notify', type: 'success', message: __('File attached.'));
    }

    public function removeAttachment(int $attachmentId, DeleteAttachment $deleteAttachment): void
    {
        $attachment = Attachment::query()
            ->forAttachable($this->page)
            ->whereKey($attachmentId)
            ->first();

        if (! $attachment instanceof Attachment) {
            return;
        }

        $this->authorize('delete', $attachment);

        $deleteAttachment($attachment, $this->actor());

        unset($this->attachments);

        $this->dispatch('planvio-notify', type: 'success', message: __('File removed.'));
    }

    /* ------------------------------------------------------------------ *
     * Tree
     * ------------------------------------------------------------------ */

    protected function afterPageDeleted(Page $page): void
    {
        if ((int) $page->getKey() !== (int) $this->page->getKey()) {
            return;
        }

        $this->redirect(
            route('app.projects.wiki', [$this->workspace, $this->project]),
            navigate: true,
        );
    }

    public function render(): View
    {
        // Livewire re-resolves a model property by key on every request, so relations loaded
        // during mount are gone by the second one. The byline needs them on every render,
        // and lazy loading is refused outside production — so they are loaded here, once,
        // rather than tripped over in the view.
        $this->page->loadMissing([
            'editor:id,name,avatar_path',
            'author:id,name,avatar_path',
        ]);

        return view('livewire.app.projects.wiki-page')
            ->title($this->page->title.' · '.$this->project->name);
    }

    private function fillFromPage(): void
    {
        $this->title = (string) $this->page->title;
        $this->content = (string) $this->page->content;
        $this->visibility = ($this->page->visibility ?? WikiVisibility::Project)->value;
    }
}

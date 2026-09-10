<?php

declare(strict_types=1);

namespace App\Livewire\App\Projects;

use App\Actions\Attachments\DeleteAttachment;
use App\Actions\Attachments\StoreAttachment;
use App\Exceptions\UploadRejected;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WikiPage;
use App\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Every file in the project, wherever it was uploaded from.
 *
 * Attachments are polymorphic: the same table holds what somebody dropped on a task, what
 * came in on a comment, what hangs off a milestone or a wiki page, and what was uploaded to
 * the project itself. This screen is the one place that reassembles them, and the source
 * filter is the grouping — expressed as a filter rather than as headings because the list
 * is paginated and a heading that only covers the visible page is a lie.
 *
 * Nothing here is served from a disk URL. Every thumbnail and every download goes through
 * the authorising route (ARCHITECTURE.md §9), which re-runs the policy per request.
 */
#[Layout('layouts.app')]
final class Files extends Component
{
    use WithFileUploads;
    use WithPagination;

    /** Source keys, in the order the filter offers them. */
    private const SOURCES = ['task', 'comment', 'milestone', 'page', 'project'];

    /**
     * Type filters, as the extension groups a person actually thinks in.
     *
     * Matched on the stored extension rather than on the MIME type: the extension is what
     * the filter chip says, and the two are already proven to agree by the upload gate.
     *
     * @var array<string, list<string>>
     */
    private const TYPES = [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'tiff', 'heic'],
        'document' => ['pdf', 'doc', 'docx', 'odt', 'rtf', 'txt', 'md'],
        'sheet' => ['xls', 'xlsx', 'ods', 'csv', 'tsv'],
        'slides' => ['ppt', 'pptx', 'odp'],
        'media' => ['mp3', 'wav', 'ogg', 'm4a', 'mp4', 'webm', 'mov', 'avi', 'mkv'],
        'archive' => ['zip', 'gz', 'tar', 'rar', '7z'],
        'data' => ['json', 'xml', 'ics'],
    ];

    public Workspace $workspace;

    public Project $project;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $type = 'all';

    #[Url(except: 'all')]
    public string $source = 'all';

    #[Url(except: 'grid')]
    public string $view = 'grid';

    /**
     * Refusals from the last upload, kept on screen rather than flashed.
     *
     * A rejected upload is a sentence somebody has to read and act on — "PHP files are not
     * accepted" — and a toast that disappears after four seconds is not where that belongs.
     *
     * @var list<array{name: string, message: string}>
     */
    public array $rejections = [];

    /** @var array<int, TemporaryUploadedFile> */
    public array $uploads = [];

    public function mount(Workspace $workspace, Project $project): void
    {
        $this->authorize('view', $project);
        $this->authorize('viewAny', [Attachment::class, $project]);

        $this->workspace = $workspace;
        $this->project = $project;
    }

    /* ------------------------------------------------------------------ *
     * Reads
     * ------------------------------------------------------------------ */

    /**
     * @return LengthAwarePaginator<int, Attachment>
     */
    #[Computed]
    public function files(): LengthAwarePaginator
    {
        return $this->baseQuery()
            ->when($this->source !== 'all', fn (Builder $q): Builder => $q->where(
                'attachable_type',
                self::morphFor($this->source),
            ))
            ->when($this->type !== 'all', fn (Builder $q): Builder => $q->whereIn(
                'extension',
                self::TYPES[$this->type] ?? ['__none__'],
            ))
            ->when($this->search !== '', fn (Builder $q): Builder => $q->where(
                'original_name',
                'like',
                '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%',
            ))
            ->with([
                'uploader:id,name,avatar_path',
                'attachable' => fn (MorphTo $morph) => $morph->morphWith([
                    Comment::class => ['commentable'],
                ]),
            ])
            ->latest('id')
            ->paginate((int) config('planvio.pagination.list', 50));
    }

    /**
     * How many files each source holds, for the filter chips.
     *
     * One grouped query rather than one count per chip: the numbers are a navigation aid,
     * not a report, and they should cost accordingly.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function sourceCounts(): array
    {
        $rows = $this->baseQuery()
            ->select('attachable_type')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('attachable_type')
            ->pluck('aggregate', 'attachable_type');

        $counts = ['all' => 0];

        foreach (self::SOURCES as $source) {
            $count = (int) ($rows[self::morphFor($source)] ?? 0);

            $counts[$source] = $count;
            $counts['all'] += $count;
        }

        return $counts;
    }

    #[Computed]
    public function totalBytes(): int
    {
        return (int) $this->baseQuery()->sum('size_bytes');
    }

    /**
     * Where a file came from, ready to render: a label, the record's own name, and a link
     * back to it.
     *
     * Everything it reads is either on the attachment row or on a relation loaded with the
     * page, so drawing a hundred rows costs no extra queries.
     *
     * @return array{label: string, title: string, url: string|null, icon: string}
     */
    public function sourceOf(Attachment $attachment): array
    {
        $subject = $attachment->relationLoaded('attachable') ? $attachment->attachable : null;

        return match ((string) $attachment->attachable_type) {
            self::morphFor('task') => [
                'label' => __('Task'),
                'title' => $subject instanceof Task
                    ? $this->project->key.'-'.$subject->number.'  '.$subject->title
                    : __('Deleted task'),
                'url' => $subject instanceof Task
                    ? route('app.tasks.show', [$this->workspace, $subject])
                    : null,
                'icon' => 'icon.check-circle',
            ],
            self::morphFor('comment') => $this->commentSource($subject),
            self::morphFor('milestone') => [
                'label' => __('Milestone'),
                'title' => $subject instanceof Milestone ? (string) $subject->name : __('Deleted milestone'),
                'url' => route('app.projects.timeline', [$this->workspace, $this->project]),
                'icon' => 'icon.flag',
            ],
            self::morphFor('page') => [
                'label' => __('Page'),
                'title' => $subject instanceof WikiPage ? (string) $subject->title : __('Deleted page'),
                'url' => $subject instanceof WikiPage
                    ? route('app.projects.wiki.show', [$this->workspace, $this->project, $subject])
                    : null,
                'icon' => 'icon.document',
            ],
            self::morphFor('project') => [
                'label' => __('Project'),
                'title' => (string) $this->project->name,
                'url' => route('app.projects.show', [$this->workspace, $this->project]),
                'icon' => 'icon.folder',
            ],
            default => [
                'label' => __('File'),
                'title' => (string) $attachment->original_name,
                'url' => null,
                'icon' => 'icon.paperclip',
            ],
        };
    }

    /* ------------------------------------------------------------------ *
     * Filters
     * ------------------------------------------------------------------ */

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function setSource(string $source): void
    {
        $this->source = in_array($source, self::SOURCES, true) ? $source : 'all';
        $this->resetPage();
    }

    public function setType(string $type): void
    {
        $this->type = array_key_exists($type, self::TYPES) ? $type : 'all';
        $this->resetPage();
    }

    public function setView(string $view): void
    {
        $this->view = $view === 'list' ? 'list' : 'grid';
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->type = 'all';
        $this->source = 'all';
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->type !== 'all' || $this->source !== 'all';
    }

    /**
     * @return array<string, string>
     */
    public function typeOptions(): array
    {
        return [
            'all' => __('All types'),
            'image' => __('Images'),
            'document' => __('Documents'),
            'sheet' => __('Spreadsheets'),
            'slides' => __('Presentations'),
            'media' => __('Audio and video'),
            'archive' => __('Archives'),
            'data' => __('Data'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function sourceLabels(): array
    {
        return [
            'all' => __('Everywhere'),
            'task' => __('Tasks'),
            'comment' => __('Comments'),
            'milestone' => __('Milestones'),
            'page' => __('Wiki'),
            'project' => __('Project'),
        ];
    }

    /* ------------------------------------------------------------------ *
     * Writes
     * ------------------------------------------------------------------ */

    public function updatedUploads(): void
    {
        $this->storeUploads();
    }

    public function storeUploads(): void
    {
        $storeAttachment = app(StoreAttachment::class);

        $this->authorize('create', [Attachment::class, $this->project]);

        $files = array_filter(
            $this->uploads,
            static fn (mixed $file): bool => $file instanceof TemporaryUploadedFile,
        );

        $this->uploads = [];
        $this->rejections = [];

        if ($files === []) {
            return;
        }

        $stored = 0;

        foreach ($files as $file) {
            try {
                // The gate is the Action, not a duplicate allow-list here: it checks the
                // extension, the sniffed type and the size together, and its refusal names
                // the control that fired so the message can be specific.
                $storeAttachment($this->project, $file, $this->actor());

                $stored++;
            } catch (UploadRejected $rejected) {
                $this->rejections[] = [
                    'name' => (string) $file->getClientOriginalName(),
                    'message' => $rejected->userMessage(),
                ];
            }
        }

        unset($this->files, $this->sourceCounts, $this->totalBytes);

        if ($stored > 0) {
            $this->dispatch('planvio-notify', type: 'success', message: trans_choice(
                '{1} :count file uploaded.|[2,*] :count files uploaded.',
                $stored,
                ['count' => $stored],
            ));
        }

        if ($this->rejections !== []) {
            $this->dispatch('planvio-notify', type: 'error', message: trans_choice(
                '{1} :count file was not accepted.|[2,*] :count files were not accepted.',
                count($this->rejections),
                ['count' => count($this->rejections)],
            ));
        }
    }

    public function dismissRejections(): void
    {
        $this->rejections = [];
    }

    public function deleteFile(int $attachmentId, DeleteAttachment $deleteAttachment): void
    {
        $attachment = $this->baseQuery()->whereKey($attachmentId)->first();

        if (! $attachment instanceof Attachment) {
            return;
        }

        $this->authorize('delete', $attachment);

        $deleteAttachment($attachment, $this->actor());

        unset($this->files, $this->sourceCounts, $this->totalBytes);

        $this->dispatch('planvio-notify', type: 'success', message: __('“:name” was removed.', [
            'name' => $attachment->original_name,
        ]));
    }

    public function render(): View
    {
        return view('livewire.app.projects.files')
            ->title($this->project->name.' · '.__('Files'));
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    /**
     * Everything attached anywhere inside this project.
     *
     * The subject ids are sub-selects rather than materialised arrays: a project with ten
     * thousand tasks must not turn its file list into a ten-thousand-item `IN` clause.
     *
     * @return Builder<Attachment>
     */
    private function baseQuery(): Builder
    {
        $projectId = (int) $this->project->getKey();

        $taskIds = Task::query()->where('project_id', $projectId)->select('id');

        return Attachment::query()
            ->where('workspace_id', $this->project->workspace_id)
            ->where(function (Builder $anywhere) use ($projectId, $taskIds): void {
                $anywhere
                    ->where(fn (Builder $q): Builder => $q
                        ->where('attachable_type', self::morphFor('project'))
                        ->where('attachable_id', $projectId))
                    ->orWhere(fn (Builder $q): Builder => $q
                        ->where('attachable_type', self::morphFor('task'))
                        ->whereIn('attachable_id', $taskIds->clone()))
                    ->orWhere(fn (Builder $q): Builder => $q
                        ->where('attachable_type', self::morphFor('milestone'))
                        ->whereIn(
                            'attachable_id',
                            Milestone::query()->where('project_id', $projectId)->select('id'),
                        ))
                    ->orWhere(fn (Builder $q): Builder => $q
                        ->where('attachable_type', self::morphFor('page'))
                        ->whereIn(
                            'attachable_id',
                            WikiPage::query()->where('project_id', $projectId)->select('id'),
                        ))
                    ->orWhere(fn (Builder $q): Builder => $q
                        ->where('attachable_type', self::morphFor('comment'))
                        ->whereIn(
                            'attachable_id',
                            Comment::query()
                                ->where('commentable_type', self::morphFor('task'))
                                ->whereIn('commentable_id', $taskIds->clone())
                                ->select('id'),
                        ));
            });
    }

    /**
     * @return array{label: string, title: string, url: string|null, icon: string}
     */
    private function commentSource(mixed $subject): array
    {
        $on = $subject instanceof Comment && $subject->relationLoaded('commentable')
            ? $subject->commentable
            : null;

        return [
            'label' => __('Comment'),
            'title' => $on instanceof Task
                ? $this->project->key.'-'.$on->number.'  '.$on->title
                : __('A comment'),
            'url' => $on instanceof Task
                ? route('app.tasks.show', [$this->workspace, $on])
                : null,
            'icon' => 'icon.chat',
        ];
    }

    /**
     * What `attachments.attachable_type` actually holds for a source.
     *
     * A morph-map alias — `task`, never `App\Models\Task` (ARCHITECTURE.md §5.0). Written
     * against the class name the comparison matches nothing at all, and it fails silently:
     * an empty file list is indistinguishable from a project that has no files.
     */
    private static function morphFor(string $source): string
    {
        return Relation::getMorphAlias(match ($source) {
            'task' => Task::class,
            'comment' => Comment::class,
            'milestone' => Milestone::class,
            'page' => WikiPage::class,
            default => Project::class,
        });
    }

    private function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}

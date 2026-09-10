<?php

declare(strict_types=1);

namespace App\Livewire\App\Import;

use App\Jobs\RunTaskImport;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DateResolver;
use App\Services\Export\CsvStream;
use App\Services\Import\ColumnMap;
use App\Services\Import\CsvSource;
use App\Services\Import\ImportCatalogue;
use App\Services\Import\ImportProgress;
use App\Services\Import\ImportProgressStore;
use App\Services\Import\ImportReport;
use App\Services\Import\ImportSpec;
use App\Services\Import\ResolvedRow;
use App\Services\Import\RowResolver;
use App\Services\Import\TaskField;
use App\Services\Import\TaskImporter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Bringing a spreadsheet of work into a project, in five steps.
 *
 *   1. **Upload** — the file is sniffed for its delimiter and staged on the private disk.
 *   2. **Map** — each Planvio field is pointed at a column, guessed from the headers.
 *   3. **Validate** — every row is checked and its problems reported by number and value.
 *   4. **Preview** — the first twenty rows exactly as they will be created.
 *   5. **Import** — written through `Actions\Tasks\CreateTask`, one row at a time.
 *
 * ## Why the steps are separate
 *
 * Because an import is irreversible in practice. Planvio does not offer "undo the last
 * import" — the tasks are real tasks the moment they exist, with activity entries and
 * notifications behind them — so the cost of finding out at step 3 that the due-date column
 * is unreadable is a corrected mapping, and the cost of finding out afterwards is a person
 * deleting four hundred tasks by hand.
 *
 * The step the wizard is on is server state, not a URL: a half-finished import is not a
 * place somebody should be able to arrive at by pasting a link, because the file that made
 * it meaningful lives in this component.
 *
 * ## Small files run here, large ones queue
 *
 * Under {@see self::QUEUE_THRESHOLD} rows the import runs inside the request, because a
 * person waiting three seconds for a hundred tasks does not want a progress bar. Above it
 * the work is dispatched and the screen polls, since the alternative is a PHP timeout with
 * half a file imported and no report of which half.
 *
 * ## The file itself is never trusted
 *
 * Every cell arrives as text and stays text (see {@see CsvSource}). Nothing is evaluated,
 * and the values a row resolves to are matched against catalogues loaded from this project —
 * so a CSV cannot name a status, a milestone or a member from another workspace, whatever it
 * contains.
 */
#[Layout('layouts.app')]
final class Wizard extends Component
{
    use WithFileUploads;

    public const STEPS = 5;

    /** Rows above which the import is queued rather than run inside the request. */
    public const QUEUE_THRESHOLD = 300;

    /** Rows shown on the preview step. */
    private const PREVIEW_ROWS = 20;

    /**
     * Upload ceiling for an import, in kilobytes.
     *
     * Deliberately below the product-wide attachment limit: a CSV of the twenty thousand
     * rows this importer will accept is well under eight megabytes, and anything larger is
     * a file that will be truncated anyway — better to say so at the upload than after the
     * parse.
     */
    private const MAX_UPLOAD_KB = 8192;

    /** Staged uploads older than this are swept when somebody starts a new import. */
    private const STALE_UPLOAD_HOURS = 24;

    public Workspace $workspace;

    public Project $project;

    public int $step = 1;

    public ?TemporaryUploadedFile $file = null;

    /** Where the accepted upload is staged, relative to the private disk. */
    public string $path = '';

    public string $originalName = '';

    public string $delimiter = ',';

    /** @var list<string> */
    public array $headers = [];

    public int $rowCount = 0;

    public bool $truncated = false;

    /**
     * Field value => column index, or '' for "do not import this field".
     *
     * @var array<string, int|string>
     */
    public array $mapping = [];

    public bool $createMissingTags = false;

    /**
     * The person has read what a partial failure means. The import button is inert without
     * it, because "this cannot be undone" is not something to bury in a paragraph.
     */
    public bool $acknowledged = false;

    /** Opaque handle for this run's progress record. */
    public string $token = '';

    public bool $queued = false;

    public function mount(Workspace $workspace, Project $project): void
    {
        $this->authorize('view', $project);
        $this->authorize('create', [Task::class, $project]);

        $this->workspace = $workspace;
        $this->project = $project;
        $this->mapping = ColumnMap::make()->toFormState();
    }

    public function render(): View
    {
        return view('livewire.app.import.wizard')
            ->title($this->project->name.' · '.__('Import tasks'));
    }

    /* ------------------------------------------------------------------ *
     * Step 1 — upload
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.self::MAX_UPLOAD_KB, 'mimes:csv,txt,tsv'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'file.mimes' => __('Planvio imports CSV files. Export one from your spreadsheet first.'),
            'file.max' => __('That file is larger than :size MB. Split it and import the parts.', [
                'size' => (int) (self::MAX_UPLOAD_KB / 1024),
            ]),
        ];
    }

    /**
     * Livewire calls this the moment the upload finishes, so the file is read and described
     * without a second click.
     */
    public function updatedFile(): void
    {
        $this->validate();

        $upload = $this->file;

        if (! $upload instanceof TemporaryUploadedFile) {
            return;
        }

        $this->discardStagedFile();
        $this->sweepStaleUploads();

        $directory = 'imports/'.$this->workspace->getKey();
        $name = (string) Str::ulid();

        $stored = $upload->storeAs($directory, $name.'.csv', ['disk' => 'private']);

        if (! is_string($stored) || $stored === '') {
            $this->addError('file', __('The upload could not be saved. Check that storage is writable.'));

            return;
        }

        $this->path = $stored;
        $this->originalName = $this->safeName($upload->getClientOriginalName());

        try {
            $source = CsvSource::open($this->absolutePath(), null);
        } catch (RuntimeException $exception) {
            $this->discardStagedFile();
            $this->addError('file', $exception->getMessage());

            return;
        }

        $this->delimiter = $source->delimiter();
        $this->headers = $source->headers();
        $this->rowCount = $source->rowCount();
        $this->truncated = $source->isTruncated();
        $this->mapping = ColumnMap::guess($this->headers)->toFormState();
        $this->file = null;

        if ($this->rowCount === 0) {
            $this->addError('file', __('That file has a header row and nothing under it.'));

            return;
        }

        $this->step = 2;
        $this->forgetDerived();
    }

    /**
     * A file with the columns this importer understands, for somebody starting from nothing.
     */
    public function template(): StreamedResponse
    {
        $headers = array_map(static fn (TaskField $field): string => $field->label(), TaskField::all());

        $example = [
            __('Write the landing page copy'),
            __('Two paragraphs and a call to action.'),
            $this->catalogue()->defaultStatus()?->name ?? __('To Do'),
            'high',
            $this->actor()->email,
            '2026-03-31',
            '2026-03-24',
            __('Marketing, Copy'),
            '',
            '4h',
        ];

        return response()->streamDownload(
            static function () use ($headers, $example): void {
                $out = fopen('php://output', 'wb');

                if ($out === false) {
                    return;
                }

                CsvStream::writeTo($out, $headers, [$example]);
            },
            'planvio-import-template.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /* ------------------------------------------------------------------ *
     * Step 2 — mapping
     * ------------------------------------------------------------------ */

    public function columnMap(): ColumnMap
    {
        return ColumnMap::fromArray($this->mapping, count($this->headers));
    }

    /**
     * The detected separator, named rather than printed — a tab cannot be shown.
     */
    public function delimiterLabel(): string
    {
        return match ($this->delimiter) {
            ',' => __('commas'),
            ';' => __('semicolons'),
            "\t" => __('tabs'),
            '|' => __('pipes'),
            default => $this->delimiter,
        };
    }

    /**
     * @return list<TaskField>
     */
    public function fields(): array
    {
        return TaskField::all();
    }

    /**
     * The dropdown behind every field: "Do not import", then each column with its first
     * value as a hint, because a header called "Col 3" tells nobody anything.
     *
     * @return array<int|string, string>
     */
    #[Computed]
    public function columnOptions(): array
    {
        $sample = $this->sampleRow;
        $options = ['' => __('Do not import')];

        foreach ($this->headers as $index => $header) {
            $value = $sample[$index] ?? '';
            $options[$index] = $value === ''
                ? $header
                : $header.'  ·  '.Str::limit($value, 28);
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function sampleRow(): array
    {
        if ($this->path === '') {
            return [];
        }

        foreach ($this->source()->rows(1) as $row) {
            return $row;
        }

        return [];
    }

    /**
     * A column pointed at two fields would import the same text twice, so the second
     * selection releases the first.
     */
    public function updatedMapping(mixed $value, string $key): void
    {
        if ($value === '' || ! is_numeric($value)) {
            $this->forgetDerived();

            return;
        }

        foreach ($this->mapping as $field => $index) {
            if ($field !== $key && $index !== '' && (int) $index === (int) $value) {
                $this->mapping[$field] = '';
            }
        }

        $this->forgetDerived();
    }

    public function autoMap(): void
    {
        $this->mapping = ColumnMap::guess($this->headers)->toFormState();
        $this->forgetDerived();
    }

    public function clearMapping(): void
    {
        $this->mapping = ColumnMap::make()->toFormState();
        $this->forgetDerived();
    }

    /**
     * @return list<TaskField>
     */
    public function missingRequired(): array
    {
        return $this->columnMap()->missingRequired();
    }

    /* ------------------------------------------------------------------ *
     * Steps 3 and 4 — validation and preview
     * ------------------------------------------------------------------ */

    #[Computed]
    public function report(): ImportReport
    {
        return $this->importer()->validate($this->source(), $this->columnMap(), $this->resolver());
    }

    /**
     * @return list<ResolvedRow>
     */
    #[Computed]
    public function previewRows(): array
    {
        return $this->importer()->preview(
            $this->source(),
            $this->columnMap(),
            $this->resolver(),
            self::PREVIEW_ROWS,
        );
    }

    /* ------------------------------------------------------------------ *
     * Step 5 — the import itself
     * ------------------------------------------------------------------ */

    public function willQueue(): bool
    {
        return $this->rowCount > self::QUEUE_THRESHOLD;
    }

    public function import(): void
    {
        $this->authorize('create', [Task::class, $this->project]);

        if ($this->path === '' || ! $this->acknowledged || $this->missingRequired() !== []) {
            return;
        }

        // A second click while the first import is still running would import the file
        // twice; the token is the flag that says one is already under way.
        if ($this->token !== '' && ($this->progress?->isRunning() ?? false)) {
            return;
        }

        $this->token = (string) Str::ulid();
        $this->step = 5;

        $store = app(ImportProgressStore::class);
        $store->put($this->token, ImportProgress::queued($this->rowCount));

        $spec = new ImportSpec(
            workspaceId: (int) $this->workspace->getKey(),
            projectId: (int) $this->project->getKey(),
            actorId: (int) $this->actor()->getKey(),
            path: $this->path,
            delimiter: $this->delimiter,
            mapping: $this->columnMap()->toArray(),
            createMissingTags: $this->createMissingTags,
            token: $this->token,
            total: $this->rowCount,
        );

        if ($this->willQueue()) {
            $this->queued = true;
            RunTaskImport::dispatch($spec->toArray());

            return;
        }

        $this->queued = false;
        $this->runNow($store);
    }

    /**
     * The small-file path: written inside this request, reporting into the same progress
     * record the queued run uses so the screen has one shape either way.
     */
    private function runNow(ImportProgressStore $store): void
    {
        $catalogue = $this->catalogue();

        try {
            $this->importer()->run(
                source: $this->source(),
                map: $this->columnMap(),
                resolver: $this->resolver($catalogue),
                catalogue: $catalogue,
                project: $this->project,
                workspace: $this->workspace,
                actor: $this->actor(),
                progress: ImportProgress::queued($this->rowCount),
                onProgress: fn (ImportProgress $progress) => $store->put($this->token, $progress),
            );
        } catch (Throwable $exception) {
            $store->put($this->token, ($store->get($this->token) ?? ImportProgress::queued($this->rowCount))
                ->failedWith(__('The import stopped early. Anything already imported has been kept.')));

            report($exception);
        } finally {
            $this->discardStagedFile();
            $this->forgetDerived();
        }

        $progress = $this->progress;

        $this->dispatch(
            'planvio-notify',
            type: ($progress?->failed ?? 0) > 0 ? 'warning' : 'success',
            message: trans_choice(
                '{0}No task was created|{1}One task imported|[2,*]:count tasks imported',
                $progress?->created ?? 0,
                ['count' => $progress?->created ?? 0],
            ),
        );
    }

    #[Computed]
    public function progress(): ?ImportProgress
    {
        return $this->token === '' ? null : app(ImportProgressStore::class)->get($this->token);
    }

    /**
     * The poll target while a queued import is running. Forgetting the computed value is the
     * whole job: the record itself lives in the cache, written by the worker.
     */
    public function refreshProgress(): void
    {
        unset($this->progress);
    }

    public function isRunning(): bool
    {
        return $this->token !== '' && ($this->progress?->isRunning() ?? false);
    }

    /* ------------------------------------------------------------------ *
     * Navigation
     * ------------------------------------------------------------------ */

    public function goTo(int $step): void
    {
        $step = max(1, min(self::STEPS, $step));

        // Forwards is gated on the step before it being complete; backwards never is, so a
        // mistake at step 4 is one click from being fixed.
        if ($step > $this->step) {
            if ($this->path === '') {
                return;
            }

            if ($step >= 3 && $this->missingRequired() !== []) {
                return;
            }

            if ($step >= 5 && ! $this->report->canImport()) {
                return;
            }
        }

        if ($this->step === 5 && $this->token !== '') {
            return;
        }

        $this->step = $step;
        $this->forgetDerived();
    }

    public function next(): void
    {
        $this->goTo($this->step + 1);
    }

    public function back(): void
    {
        $this->goTo($this->step - 1);
    }

    /**
     * Throw the whole thing away and start again, taking the staged upload with it.
     */
    public function startOver(): void
    {
        $this->discardStagedFile();

        $this->reset(['originalName', 'headers', 'rowCount', 'truncated', 'createMissingTags', 'acknowledged', 'token', 'queued']);
        $this->delimiter = ',';
        $this->mapping = ColumnMap::make()->toFormState();
        $this->step = 1;
        $this->resetErrorBag();
        $this->forgetDerived();
    }

    /**
     * @return array<int, array{number: int, label: string, hint: string}>
     */
    public function steps(): array
    {
        return [
            ['number' => 1, 'label' => __('Upload'), 'hint' => __('A CSV exported from anywhere.')],
            ['number' => 2, 'label' => __('Map columns'), 'hint' => __('Which column is which field.')],
            ['number' => 3, 'label' => __('Validate'), 'hint' => __('Every row, checked.')],
            ['number' => 4, 'label' => __('Preview'), 'hint' => __('The first rows as they will be.')],
            ['number' => 5, 'label' => __('Import'), 'hint' => __('Written one task at a time.')],
        ];
    }

    /* ------------------------------------------------------------------ *
     * Plumbing
     * ------------------------------------------------------------------ */

    private function source(): CsvSource
    {
        return CsvSource::open($this->absolutePath(), $this->delimiter);
    }

    private function importer(): TaskImporter
    {
        return app(TaskImporter::class);
    }

    private function catalogue(): ImportCatalogue
    {
        return ImportCatalogue::for($this->project, $this->workspace);
    }

    private function resolver(?ImportCatalogue $catalogue = null): RowResolver
    {
        return new RowResolver(
            dates: app(DateResolver::class),
            workspace: $this->workspace,
            catalogue: $catalogue ?? $this->catalogue(),
            createMissingTags: $this->createMissingTags,
        );
    }

    private function absolutePath(): string
    {
        return Storage::disk('private')->path($this->path);
    }

    private function discardStagedFile(): void
    {
        if ($this->path !== '') {
            Storage::disk('private')->delete($this->path);
            $this->path = '';
        }
    }

    /**
     * An import somebody walked away from leaves a staged file nothing will ever read. The
     * next upload into the same workspace clears anything older than a day, which keeps the
     * directory bounded without a scheduled task nobody would remember to register.
     */
    private function sweepStaleUploads(): void
    {
        $disk = Storage::disk('private');
        $directory = 'imports/'.$this->workspace->getKey();
        $cutoff = now()->subHours(self::STALE_UPLOAD_HOURS)->getTimestamp();

        foreach ($disk->files($directory) as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
            }
        }
    }

    private function forgetDerived(): void
    {
        unset($this->report, $this->previewRows, $this->columnOptions, $this->sampleRow, $this->progress);
    }

    private function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));

        return mb_substr((string) preg_replace('/[\x00-\x1F\x7F"]+/u', '', $name), 0, 120);
    }

    private function actor(): User
    {
        $user = auth()->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DateResolver;
use App\Services\Import\CsvSource;
use App\Services\Import\ImportCatalogue;
use App\Services\Import\ImportProgress;
use App\Services\Import\ImportProgressStore;
use App\Services\Import\ImportSpec;
use App\Services\Import\RowResolver;
use App\Services\Import\TaskImporter;
use App\Support\CurrentWorkspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Carries a large import onto the queue.
 *
 * A few hundred rows are written while somebody waits; past that the request would run into
 * the web server's timeout with half the file imported and no report. So a big file is
 * staged on the private disk, this job is dispatched, and the wizard polls
 * {@see ImportProgressStore} for the bar and the outcome.
 *
 * ## The acting user is checked again here
 *
 * The wizard authorised the import before dispatching, but a job runs later, possibly much
 * later, and permission is not a property of the moment it was granted. The Gate is
 * therefore re-run for the stored user before a single row is written — someone removed
 * from the project between clicking and the worker waking up must not have their import go
 * through on the strength of a queued row.
 *
 * ## Why it does not retry
 *
 * `tries = 1`. The import is not transactional by design (see {@see TaskImporter}), so a
 * second attempt would re-import everything the first attempt had already written. A failed
 * run reports what it managed and stops; re-running is a decision for the person, made in
 * front of the report, not for the queue.
 */
final class RunTaskImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    /**
     * @param array<string, mixed> $spec the serialised {@see ImportSpec}
     */
    public function __construct(private readonly array $spec) {}

    public function handle(
        CurrentWorkspace $workspaces,
        ImportProgressStore $store,
        TaskImporter $importer,
        DateResolver $dates,
    ): void {
        $spec = ImportSpec::fromArray($this->spec);

        $workspace = Workspace::query()->whereKey($spec->workspaceId)->first();
        $actor = User::query()->whereKey($spec->actorId)->first();

        if (! $workspace instanceof Workspace || ! $actor instanceof User || $spec->path === '') {
            $store->put($spec->token, $this->progress($store, $spec)->failedWith(
                __('This import no longer points at anything Planvio can write to.'),
            ));

            return;
        }

        // Nothing is bound outside a request, which makes WorkspaceScope inert
        // (ARCHITECTURE.md §3). Everything below runs with this tenant bound.
        $workspaces->runFor($workspace, function () use ($spec, $workspace, $actor, $store, $importer, $dates): void {
            $this->run($spec, $workspace, $actor, $store, $importer, $dates);
        });
    }

    private function run(
        ImportSpec $spec,
        Workspace $workspace,
        User $actor,
        ImportProgressStore $store,
        TaskImporter $importer,
        DateResolver $dates,
    ): void {
        $disk = Storage::disk('private');
        $project = Project::query()->whereKey($spec->projectId)->first();

        if (! $project instanceof Project || ! $disk->exists($spec->path)) {
            $store->put($spec->token, $this->progress($store, $spec)->failedWith(
                __('The uploaded file is no longer available. Please upload it again.'),
            ));

            return;
        }

        if (! Gate::forUser($actor)->allows('create', [Task::class, $project])) {
            $store->put($spec->token, $this->progress($store, $spec)->failedWith(
                __('You no longer have permission to create tasks in this project.'),
            ));

            $disk->delete($spec->path);

            return;
        }

        try {
            $source = CsvSource::open($disk->path($spec->path), $spec->delimiter);
            $catalogue = ImportCatalogue::for($project, $workspace);

            $resolver = new RowResolver(
                dates: $dates,
                workspace: $workspace,
                catalogue: $catalogue,
                createMissingTags: $spec->createMissingTags,
            );

            $importer->run(
                source: $source,
                map: $spec->columnMap($source->columnCount()),
                resolver: $resolver,
                catalogue: $catalogue,
                project: $project,
                workspace: $workspace,
                actor: $actor,
                progress: ImportProgress::queued($source->rowCount()),
                onProgress: static fn (ImportProgress $progress) => $store->put($spec->token, $progress),
            );
        } catch (Throwable $exception) {
            // The message is Planvio's own: a throwable's can carry a filesystem path, and
            // this string is shown in the product (CLAUDE.md rule 4).
            $store->put($spec->token, $this->progress($store, $spec)->failedWith(
                __('The import stopped early. Anything already imported has been kept.'),
            ));

            report($exception);
        } finally {
            // The staged copy has done its job either way. Leaving it behind would accrue
            // uploads nobody can see and nothing will ever delete.
            $disk->delete($spec->path);
        }
    }

    /**
     * The record as it stands, so a failure keeps the counts the run had reached.
     */
    private function progress(ImportProgressStore $store, ImportSpec $spec): ImportProgress
    {
        return $store->get($spec->token) ?? ImportProgress::queued($spec->total);
    }

    public function failed(Throwable $exception): void
    {
        $spec = ImportSpec::fromArray($this->spec);
        $store = app(ImportProgressStore::class);

        $store->put($spec->token, ($store->get($spec->token) ?? ImportProgress::queued($spec->total))
            ->failedWith(__('The import stopped early. Anything already imported has been kept.')));
    }
}

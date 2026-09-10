<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Actions\Tags\AttachTag;
use App\Actions\Tags\CreateTag;
use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\CreateTaskData;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use DomainException;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Reads a mapped CSV and writes the tasks it describes.
 *
 * ## Every row goes through CreateTask
 *
 * Not a bulk insert. {@see CreateTask} is what allocates the per-project task number under a
 * lock, seeds the watcher list, records the activity entry and fires `TaskCreated` — which
 * is what sends the notifications and updates the project's cached counts. A hand-rolled
 * insert would produce rows that look like tasks and behave like nothing, and the divergence
 * would only show up weeks later in a feed with a hole in it.
 *
 * ## A partial failure is not rolled back
 *
 * There is no transaction around the run, and that is the design rather than an oversight.
 * Wrapping ten thousand inserts in one transaction on the shared hosting this product
 * targets means a lock held for minutes and a rollback that can itself time out; worse, a
 * failure at row 9,000 would silently discard 8,999 tasks people had already watched
 * appear. So each row stands or falls alone, the failures are reported by number and
 * reason, and the wizard says so in as many words *before* the button is pressed. Re-running
 * a fixed file creates the fixed rows again — Planvio does not deduplicate titles, because
 * two people asking for "Fix the footer" mean two pieces of work.
 *
 * ## Validation is the same code as the preview
 *
 * {@see validate()}, {@see preview()} and {@see run()} all resolve rows through the same
 * {@see RowResolver}. A preview built from a second implementation is a preview that
 * eventually disagrees with the import.
 */
final class TaskImporter
{
    /**
     * Rows between progress writes. Every row would make the cache the bottleneck; a
     * hundred is under a second of work, which is as often as a progress bar needs to move.
     */
    private const PROGRESS_EVERY = 100;

    /** Issues carried in a validation report before the list is cut. */
    private const MAX_REPORTED_ISSUES = 200;

    public function __construct(
        private readonly CreateTask $createTask,
        private readonly CreateTag $createTag,
        private readonly AttachTag $attachTag,
    ) {}

    /**
     * Check every row without writing anything.
     */
    public function validate(CsvSource $source, ColumnMap $map, RowResolver $resolver): ImportReport
    {
        $rows = 0;
        $blocked = 0;
        $errors = 0;
        $warnings = 0;
        $rowsWithWarnings = 0;
        $issues = [];
        $truncated = false;

        foreach ($source->rows() as $number => $row) {
            $rows++;
            $resolved = $resolver->resolve($number, $row, $map);

            $rowErrors = $resolved->errors();
            $rowWarnings = $resolved->warnings();

            $errors += count($rowErrors);
            $warnings += count($rowWarnings);

            if ($rowErrors !== []) {
                $blocked++;
            } elseif ($rowWarnings !== []) {
                $rowsWithWarnings++;
            }

            foreach ($resolved->issues as $issue) {
                if (count($issues) < self::MAX_REPORTED_ISSUES) {
                    $issues[] = $issue;
                } else {
                    $truncated = true;
                }
            }
        }

        return new ImportReport(
            rows: $rows,
            importable: $rows - $blocked,
            blocked: $blocked,
            errorCount: $errors,
            warningCount: $warnings,
            rowsWithWarnings: $rowsWithWarnings,
            issues: $issues,
            issuesTruncated: $truncated,
        );
    }

    /**
     * The first rows, resolved exactly as they will be created.
     *
     * @return list<ResolvedRow>
     */
    public function preview(CsvSource $source, ColumnMap $map, RowResolver $resolver, int $limit = 20): array
    {
        $rows = [];

        foreach ($source->rows($limit) as $number => $row) {
            $rows[] = $resolver->resolve($number, $row, $map);
        }

        return $rows;
    }

    /**
     * Write the file.
     *
     * @param callable(ImportProgress): void|null $onProgress called every hundred rows and once at the end
     */
    public function run(
        CsvSource $source,
        ColumnMap $map,
        RowResolver $resolver,
        ImportCatalogue $catalogue,
        Project $project,
        Workspace $workspace,
        User $actor,
        ImportProgress $progress,
        ?callable $onProgress = null,
    ): ImportProgress {
        // CreateTask asks the assignee whether they belong to the project's workspace, which
        // reads `project->workspace`. Setting it here keeps that from being a lazy load —
        // fatal outside production under `preventLazyLoading` — once per run rather than once
        // per row.
        $project->setRelation('workspace', $workspace);

        $processed = 0;
        $created = 0;
        $skipped = 0;
        $failed = 0;
        $issues = [];

        $progress = $progress->starting();
        $this->report($progress, $onProgress);

        foreach ($source->rows() as $number => $row) {
            $processed++;
            $resolved = $resolver->resolve($number, $row, $map);

            if ($resolved->hasErrors()) {
                $skipped++;

                foreach ($resolved->errors() as $issue) {
                    $issues[] = $issue;
                }
            } else {
                try {
                    $this->createOne($resolved, $catalogue, $project, $workspace, $actor);
                    $created++;
                } catch (DomainException|QueryException $exception) {
                    // A row Planvio refused, or the database did. Both are this row's
                    // problem: the rows already written stay written.
                    $failed++;
                    $issues[] = RowIssue::error($number, $this->reason($exception));
                } catch (Throwable $exception) {
                    $failed++;
                    $issues[] = RowIssue::error($number, __('This row could not be imported.'));

                    report($exception);
                }
            }

            if ($processed % self::PROGRESS_EVERY === 0) {
                $progress = $progress->advanced($processed, $created, $skipped, $failed, $issues);
                $this->report($progress, $onProgress);
            }
        }

        $progress = $progress->advanced($processed, $created, $skipped, $failed, $issues)->finished();
        $this->report($progress, $onProgress);

        return $progress;
    }

    /* ------------------------------------------------------------------ *
     * One row
     * ------------------------------------------------------------------ */

    private function createOne(
        ResolvedRow $resolved,
        ImportCatalogue $catalogue,
        Project $project,
        Workspace $workspace,
        User $actor,
    ): Task {
        $task = ($this->createTask)(new CreateTaskData(
            project: $project,
            actor: $actor,
            title: $resolved->title,
            description: $resolved->description,
            status: $resolved->status,
            priority: $resolved->priority,
            assignee: $resolved->assignee,
            milestone: $resolved->milestone,
            startDate: $resolved->startDate,
            dueDate: $resolved->dueDate,
            estimateMinutes: $resolved->estimateMinutes,
        ));

        foreach ($resolved->tags as $tag) {
            ($this->attachTag)($task, $tag, $actor);
        }

        foreach ($resolved->newTagNames as $name) {
            // CreateTag returns the existing tag when the slug is already taken, so two rows
            // naming the same new tag produce one tag and two attachments.
            $tag = ($this->createTag)($workspace, $name, $actor);

            $catalogue->rememberTag($tag);

            ($this->attachTag)($task, $tag, $actor);
        }

        return $task;
    }

    /**
     * What to tell somebody about a row the write refused.
     *
     * A domain exception carries a sentence written for a person. A database error does
     * not: its message names columns and constraints, and it can quote the offending value,
     * so it is replaced rather than shown.
     */
    private function reason(Throwable $exception): string
    {
        if ($exception instanceof DomainException) {
            $message = trim($exception->getMessage());

            if ($message !== '') {
                return $message;
            }
        }

        return __('The database refused this row.');
    }

    /**
     * @param callable(ImportProgress): void|null $onProgress
     */
    private function report(ImportProgress $progress, ?callable $onProgress): void
    {
        if ($onProgress !== null) {
            $onProgress($progress);
        }
    }
}

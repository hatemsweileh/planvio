<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Models\User;
use App\Models\Workspace;

/**
 * Turns "what do you want, as whom, filtered how" into the one object that can produce it.
 *
 * The reader is a constructor argument of every export rather than something read from the
 * session inside it: a queued or scheduled export would otherwise silently become "whoever
 * the container thinks is signed in", which is nobody, and a permission check against
 * nobody is not a permission check.
 */
final class ExportFactory
{
    public function make(
        ExportType $type,
        Workspace $workspace,
        User $reader,
        ExportFilters $filters,
    ): CsvExport {
        return match ($type) {
            ExportType::Tasks => new TaskExport($workspace, $reader, $filters),
            ExportType::Projects => new ProjectExport($workspace, $reader, $filters),
            ExportType::Time => new TimeEntryExport($workspace, $reader, $filters),
        };
    }
}

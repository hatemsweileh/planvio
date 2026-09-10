<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * How much a problem with a row matters.
 *
 * The distinction is the whole design of this importer, so it is worth stating plainly:
 *
 *   - **Error** — the row cannot become the task the file describes, and importing it would
 *     quietly produce something else. A missing title; a due date that is not a date; a
 *     deadline before the start. The row is skipped and reported by number.
 *   - **Warning** — the row becomes a task, but one detail could not be honoured. An
 *     assignee who is not in this workspace, a status name no column matches, an
 *     unparseable estimate. The task is created without that detail, and the warning says
 *     which and why.
 *
 * The rule for deciding between them: if the omission is visible on the created task and
 * fixable in ten seconds, it is a warning. If it would put a wrong fact into the project,
 * it is an error.
 */
enum IssueSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';

    public function label(): string
    {
        return match ($this) {
            self::Error => __('Error'),
            self::Warning => __('Warning'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Error => 'red',
            self::Warning => 'amber',
        };
    }

    public function blocks(): bool
    {
        return $this === self::Error;
    }
}

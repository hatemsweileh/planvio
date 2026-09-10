<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * What a whole file looks like once every row has been checked.
 *
 * The issue list is capped. A file where every row is broken produces twenty thousand
 * problems, and a screen listing them is neither readable nor renderable; the counts stay
 * exact, and the list says how many it is not showing.
 */
final readonly class ImportReport
{
    /**
     * @param list<RowIssue> $issues
     */
    public function __construct(
        public int $rows,
        public int $importable,
        public int $blocked,
        public int $errorCount,
        public int $warningCount,
        public int $rowsWithWarnings,
        public array $issues,
        public bool $issuesTruncated,
    ) {}

    public function canImport(): bool
    {
        return $this->importable > 0;
    }

    /**
     * The issues that stop a row, in row order.
     *
     * @return list<RowIssue>
     */
    public function errors(): array
    {
        return array_values(array_filter($this->issues, static fn (RowIssue $issue): bool => $issue->blocks()));
    }

    /**
     * @return list<RowIssue>
     */
    public function warnings(): array
    {
        return array_values(array_filter($this->issues, static fn (RowIssue $issue): bool => ! $issue->blocks()));
    }
}

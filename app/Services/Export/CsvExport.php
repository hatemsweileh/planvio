<?php

declare(strict_types=1);

namespace App\Services\Export;

use Generator;

/**
 * One exportable table: its header row, and its body as a stream of rows.
 *
 * `rows()` is a generator on purpose — see {@see CsvStream} — so an implementation must
 * never materialise the whole result set. Everything here is read-side and side-effect
 * free, in keeping with the rule for `App\Services` (ARCHITECTURE.md §2).
 */
interface CsvExport
{
    /**
     * @return list<string>
     */
    public function headers(): array;

    /**
     * @return Generator<int, list<scalar|null>>
     */
    public function rows(): Generator;

    /**
     * How many rows the download will contain, for the sentence on the screen that offers
     * it. A count, not a fetch.
     */
    public function total(): int;

    /**
     * The middle of the filename: "tasks", "projects", "time-entries".
     */
    public function subject(): string;
}

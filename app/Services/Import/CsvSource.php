<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Services\Export\CsvStream;
use Generator;
use League\Csv\Reader;
use RuntimeException;

/**
 * A CSV on disk, read as text and nothing more.
 *
 * ## Every cell is data
 *
 * Nothing read here is ever evaluated, interpolated into a query, or handed to a template
 * as markup. A cell beginning `=cmd|'/c calc'!A1` is a string with an equals sign in it as
 * far as Planvio is concerned; it becomes dangerous only in a spreadsheet, which is why the
 * defence lives on the way out (see {@see CsvStream}) rather than
 * here. Import's job is the opposite one: do not sanitise the user's data into something
 * they did not write.
 *
 * ## What it does normalise
 *
 * Three things, all about the file rather than its meaning:
 *
 *   - **The byte-order mark**, which Excel writes and which would otherwise make the first
 *     header "\u{FEFF}Title" and defeat the column guess.
 *   - **The encoding.** A file exported from Excel on Windows is Windows-1252 more often
 *     than not. A cell that is not valid UTF-8 is converted rather than stored as broken
 *     bytes, because MySQL will reject it and the person who typed "café" is not at fault.
 *   - **Control characters**, which no cell of a task title has any business containing and
 *     which travel invisibly into the product if they are kept.
 *
 * ## Bounds
 *
 * A CSV is somebody's upload, so the row and column counts are capped. Without a bound, one
 * accidental 400 MB file is the whole installation's memory.
 */
final class CsvSource
{
    /** Rows one import may contain, not counting the header. */
    public const MAX_ROWS = 20000;

    /** Columns read from a row. Anything wider is a file that is not a task list. */
    public const MAX_COLUMNS = 60;

    /** Characters a delimiter may be, in the order ties are broken. */
    public const DELIMITERS = [',', ';', "\t", '|'];

    /** Longest cell kept. `tasks.title` is a varchar; a description is longText but not endless. */
    private const MAX_CELL_LENGTH = 20000;

    /** @var list<string> */
    private array $headers;

    private int $rowCount;

    private bool $truncated;

    private function __construct(
        private readonly string $path,
        private readonly string $delimiter,
    ) {
        $this->headers = [];
        $this->rowCount = 0;
        $this->truncated = false;
    }

    /**
     * @throws RuntimeException when the file is unreadable or holds no header row
     */
    public static function open(string $path, ?string $delimiter = null): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException(__('The uploaded file could not be read.'));
        }

        $source = new self($path, $delimiter ?? self::sniff($path));
        $source->load();

        return $source;
    }

    public function delimiter(): string
    {
        return $this->delimiter;
    }

    /**
     * @return list<string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function columnCount(): int
    {
        return count($this->headers);
    }

    /**
     * Data rows, header excluded.
     */
    public function rowCount(): int
    {
        return $this->rowCount;
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    /**
     * Every data row, in file order.
     *
     * The key is the row number a person would count in a spreadsheet: the header is row 1,
     * so the first record is row 2. Error messages quote that number, and a number that did
     * not line up with what they can see would be worse than no number at all.
     *
     * @return Generator<int, list<string>>
     */
    public function rows(?int $limit = null): Generator
    {
        $reader = $this->reader();
        $number = 1;
        $taken = 0;

        foreach ($reader->getRecords() as $index => $record) {
            // The header itself.
            if ($index === 0) {
                continue;
            }

            $number++;
            $row = $this->normaliseRecord($record);

            if ($this->isBlank($row)) {
                continue;
            }

            yield $number => $row;

            $taken++;

            if ($taken >= self::MAX_ROWS || ($limit !== null && $taken >= $limit)) {
                break;
            }
        }
    }

    /* ------------------------------------------------------------------ *
     * Reading
     * ------------------------------------------------------------------ */

    private function load(): void
    {
        $reader = $this->reader();
        $headers = null;
        $rows = 0;

        foreach ($reader->getRecords() as $index => $record) {
            $row = $this->normaliseRecord($record);

            if ($index === 0) {
                $headers = $row;

                continue;
            }

            if ($this->isBlank($row)) {
                continue;
            }

            $rows++;

            // One past the cap, so a file of exactly MAX_ROWS is not reported as truncated.
            if ($rows > self::MAX_ROWS) {
                break;
            }
        }

        if ($headers === null || $headers === []) {
            throw new RuntimeException(__('That file has no header row. The first line must name the columns.'));
        }

        $this->headers = $this->labelHeaders($headers);
        $this->truncated = $rows > self::MAX_ROWS;
        $this->rowCount = min($rows, self::MAX_ROWS);
    }

    private function reader(): Reader
    {
        $reader = Reader::createFromPath($this->path, 'r');
        $reader->setDelimiter($this->delimiter);
        $reader->skipInputBOM();

        return $reader;
    }

    /**
     * @param array<array-key, string|null> $record
     * @return list<string>
     */
    private function normaliseRecord(array $record): array
    {
        $row = [];
        $seen = 0;

        foreach ($record as $cell) {
            if ($seen++ >= self::MAX_COLUMNS) {
                break;
            }

            $row[] = $this->normaliseCell((string) ($cell ?? ''));
        }

        return $row;
    }

    private function normaliseCell(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            // Windows-1252 rather than ISO-8859-1: it is what Excel actually writes, and it
            // is a superset, so the smart quotes and the euro sign survive.
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        // Tabs and newlines inside a quoted cell are legitimate; the rest of C0 is not.
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

        return mb_substr(trim($value), 0, self::MAX_CELL_LENGTH);
    }

    /**
     * @param list<string> $row
     */
    private function isBlank(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * A header with no name still needs one, or the mapping screen shows a blank option
     * nobody can tell from the next blank option.
     *
     * @param list<string> $headers
     * @return list<string>
     */
    private function labelHeaders(array $headers): array
    {
        $labelled = [];

        foreach ($headers as $index => $header) {
            $labelled[] = $header === ''
                ? __('Column :number', ['number' => $index + 1])
                : $header;
        }

        return $labelled;
    }

    /* ------------------------------------------------------------------ *
     * Delimiter
     * ------------------------------------------------------------------ */

    /**
     * Which character separates the fields.
     *
     * Decided on the header line by parsing it once per candidate and keeping whichever
     * produces the most columns. Parsing rather than counting characters is what makes a
     * header like `"Smith, John",Title` come out as two fields and not three, and the tie
     * is broken by {@see self::DELIMITERS} order so a one-column file is a comma file.
     */
    public static function sniff(string $path): string
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            return ',';
        }

        $line = '';

        // Skip leading blank lines; some exports start with one.
        while (($candidate = fgets($handle, 65536)) !== false) {
            if (trim($candidate) !== '') {
                $line = $candidate;
                break;
            }
        }

        fclose($handle);

        $line = ltrim($line, "\xEF\xBB\xBF");

        if (trim($line) === '') {
            return ',';
        }

        $best = ',';
        $width = 0;

        foreach (self::DELIMITERS as $delimiter) {
            $fields = str_getcsv(rtrim($line, "\r\n"), $delimiter, '"', '\\');
            $count = count($fields);

            if ($count > $width) {
                $best = $delimiter;
                $width = $count;
            }
        }

        return $best;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Export\CsvStream;
use App\Services\Export\ExportFilters;
use App\Services\Import\ColumnMap;
use App\Services\Import\CsvSource;
use App\Services\Import\RowResolver;
use App\Services\Import\TaskField;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The parts of the CSV round trip that need no database.
 *
 * Delimiter sniffing, column guessing, estimate parsing and formula escaping are all pure
 * functions of their input, and all four are the sort of thing that is easy to get subtly
 * wrong in a way no feature test would notice.
 */
final class CsvImportExportTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ *
     * Delimiters
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function delimiters(): array
    {
        return [
            'comma' => ["Title,Due\nA,B\n", ','],
            'semicolon' => ["Title;Due;Owner\nA;B;C\n", ';'],
            'tab' => ["Title\tDue\tOwner\nA\tB\tC\n", "\t"],
            'pipe' => ["Title|Due|Owner\nA|B|C\n", '|'],
            'one column' => ["Title\nA\n", ','],
            // The comma inside the quoted header is part of the value, not a separator, so
            // parsing beats counting: a naive count would call this a comma file.
            'quoted commas' => ["\"Smith, John\";Title;Due\nx;y;z\n", ';'],
        ];
    }

    #[Test]
    #[DataProvider('delimiters')]
    public function it_sniffs_the_delimiter(string $csv, string $expected): void
    {
        $this->assertSame($expected, CsvSource::sniff($this->file($csv)));
    }

    #[Test]
    public function it_skips_a_byte_order_mark_and_blank_lines(): void
    {
        $source = CsvSource::open($this->file("\xEF\xBB\xBFTitle,Due\n\nAlpha,2026-01-01\n\n"));

        $this->assertSame(['Title', 'Due'], $source->headers());
        $this->assertSame(1, $source->rowCount());
    }

    #[Test]
    public function the_first_data_row_is_row_two_because_the_header_is_row_one(): void
    {
        $source = CsvSource::open($this->file("Title\nAlpha\nBeta\n"));

        $numbers = [];

        foreach ($source->rows() as $number => $row) {
            $numbers[$number] = $row[0];
        }

        $this->assertSame([2 => 'Alpha', 3 => 'Beta'], $numbers);
    }

    #[Test]
    public function a_nameless_column_still_gets_a_label(): void
    {
        $source = CsvSource::open($this->file("Title,,Due\nA,B,C\n"));

        $this->assertSame('Column 2', $source->headers()[1]);
    }

    /* ------------------------------------------------------------------ *
     * Column guessing
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_guesses_columns_from_the_header_names(): void
    {
        $map = ColumnMap::guess(['Task Name', 'Notes', 'Assigned To', 'Due Date', 'Est. Hours']);

        $this->assertSame(0, $map->indexFor(TaskField::Title));
        $this->assertSame(1, $map->indexFor(TaskField::Description));
        $this->assertSame(2, $map->indexFor(TaskField::Assignee));
        $this->assertSame(3, $map->indexFor(TaskField::DueDate));
        $this->assertSame(4, $map->indexFor(TaskField::Estimate));
    }

    #[Test]
    public function an_exact_header_beats_one_that_merely_contains_the_word(): void
    {
        $map = ColumnMap::guess(['Task status history', 'Status']);

        $this->assertSame(1, $map->indexFor(TaskField::Status));
    }

    #[Test]
    public function no_column_is_used_for_two_fields(): void
    {
        $map = ColumnMap::guess(['Name', 'Name']);

        $this->assertSame(0, $map->indexFor(TaskField::Title));
        $this->assertNotSame(0, $map->indexFor(TaskField::Description));
    }

    #[Test]
    public function a_mapping_pointing_past_the_last_column_is_dropped(): void
    {
        $map = ColumnMap::fromArray(['title' => 0, 'status' => 9, 'nonsense' => 1], 3);

        $this->assertSame(0, $map->indexFor(TaskField::Title));
        $this->assertNull($map->indexFor(TaskField::Status));
    }

    #[Test]
    public function a_file_with_no_title_column_reports_what_is_missing(): void
    {
        $this->assertSame([TaskField::Title], ColumnMap::make()->missingRequired());
        $this->assertSame([], ColumnMap::make(['title' => 0])->missingRequired());
    }

    /* ------------------------------------------------------------------ *
     * Estimates
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, array{0: string, 1: int|null}>
     */
    public static function estimates(): array
    {
        return [
            'bare number is hours' => ['3', 180],
            'decimal hours' => ['2.5', 150],
            'comma decimal' => ['2,5', 150],
            'explicit hours' => ['4h', 240],
            'minutes' => ['90m', 90],
            'hours and minutes' => ['1h 30m', 90],
            'clock' => ['1:30', 90],
            'days are eight hours' => ['2d', 960],
            'nonsense' => ['whenever', null],
            'empty' => ['', null],
        ];
    }

    #[Test]
    #[DataProvider('estimates')]
    public function it_reads_an_estimate(string $input, ?int $minutes): void
    {
        $this->assertSame($minutes, RowResolver::estimateMinutes($input));
    }

    /* ------------------------------------------------------------------ *
     * Export
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, array{0: string}>
     */
    public static function formulas(): array
    {
        return [
            'equals' => ['=1+1'],
            'plus' => ['+1+1'],
            'minus' => ['-1+1'],
            'at' => ['@SUM(A1)'],
            'tab' => ["\tSUM(A1)"],
        ];
    }

    #[Test]
    #[DataProvider('formulas')]
    public function every_formula_starter_is_neutralised_on_the_way_out(string $cell): void
    {
        $csv = $this->write(['Title'], [[$cell]]);

        $this->assertStringContainsString("'".$cell, $csv);
    }

    #[Test]
    public function an_ordinary_value_is_left_alone(): void
    {
        $csv = $this->write(['Title'], [['Design the header']]);

        $this->assertStringContainsString('Design the header', $csv);
        $this->assertStringNotContainsString("'Design", $csv);
    }

    #[Test]
    public function the_file_is_utf8_with_a_byte_order_mark(): void
    {
        $csv = $this->write(['Nom'], [['Café']]);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Café', $csv);
    }

    #[Test]
    public function a_null_becomes_an_empty_cell_rather_than_the_word_null(): void
    {
        $csv = $this->write(['A', 'B'], [['x', null]]);

        $this->assertStringContainsString("x,\n", $csv);
    }

    /* ------------------------------------------------------------------ *
     * Filters
     * ------------------------------------------------------------------ */

    #[Test]
    public function export_filters_discard_anything_they_do_not_recognise(): void
    {
        $filters = ExportFilters::fromArray([
            'project' => ['3', 'nonsense', '-1', '3'],
            'priority' => ['urgent', 'catastrophic'],
            'due' => 'whenever',
            'from' => '2026-02-30',
            'to' => '2026-02-28',
            'overdue' => '1',
        ]);

        $this->assertSame([3], $filters->projectIds);
        $this->assertSame(['urgent'], $filters->priorities);
        $this->assertSame('', $filters->dueRange);
        $this->assertNull($filters->from, 'February has no 30th.');
        $this->assertSame('2026-02-28', $filters->to);
        $this->assertTrue($filters->overdueOnly);
    }

    #[Test]
    public function the_query_it_produces_leaves_out_the_defaults(): void
    {
        $query = (new ExportFilters(projectIds: [7], overdueOnly: true))->toQuery();

        $this->assertSame([7], $query['project']);
        $this->assertSame(1, $query['overdue']);
        $this->assertArrayNotHasKey('q', $query);
        $this->assertArrayNotHasKey('unassigned', $query);
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'planvio-csv-');

        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }

    /**
     * @param list<string> $headers
     * @param list<list<scalar|null>> $rows
     */
    private function write(array $headers, array $rows): string
    {
        $stream = fopen('php://temp', 'w+b');

        CsvStream::writeTo($stream, $headers, $rows);
        rewind($stream);

        $contents = (string) stream_get_contents($stream);
        fclose($stream);

        return $contents;
    }
}

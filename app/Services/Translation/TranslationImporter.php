<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Console\Commands\LangExport;
use App\Console\Commands\LangImport;
use App\Exceptions\DomainException;
use Illuminate\Support\Arr;
use JsonException;

/**
 * Reads a translated JSON document, says what it would change, and then changes it.
 *
 * The command-line path — {@see LangImport} — writes as it reads, which is right for a
 * terminal where the operator typed the filename and can see the table afterwards. The
 * browser path cannot work that way: the person uploading has often not opened the file, it
 * arrived from an agency, and a single click would overwrite every line of a language.
 * So parsing, analysis and writing are separate here, and {@see TranslationImportPreview} is
 * what stands between the upload and the table.
 *
 * ## What is refused, and what is merely reported
 *
 * A line whose translation dropped a `:placeholder` is **refused**: it is counted, listed, and
 * never written, because Laravel gives no run-time signal that it broke ({@see Placeholders}).
 *
 * A key the product no longer contains is **skipped and reported**. Writing it would put a row
 * in `translations` that nothing can ever render, and the count is the useful part: a file
 * with two hundred unknown keys was exported from a different version and the translator needs
 * to know before they wonder why their work did not appear.
 *
 * ## Shapes
 *
 * Both of the shapes {@see LangExport} produces are accepted, exactly as `lang:import`
 * accepts them: a document of catalogues (`{"*": {...}, "actions": {...}}`), or a flat map for
 * one catalogue named by the caller. A mixture is refused rather than guessed at. An empty
 * string means "clear this line back to the shipped English", which is stored as null — a row
 * holding `""` would blank the text instead of falling back to it.
 */
final readonly class TranslationImporter
{
    /** The catalogue key Laravel's JSON namespace is addressed by, here and in every export. */
    public const JSON_CATALOGUE = '*';

    public function __construct(
        private TranslationCatalogue $catalogue,
        private TranslationRepository $translations,
    ) {}

    /**
     * Decode a document into `catalogue => key => value`, with `null` for "clear this line".
     *
     * @param string|null $group the catalogue a flat document belongs to; null means the
     *                           literal strings
     * @return array<string, array<string, string|null>>
     *
     * @throws DomainException when the document is not JSON, is empty, or mixes both shapes
     */
    public function parse(string $json, ?string $group = null): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DomainException(__('That file is not valid JSON: :message', [
                'message' => $exception->getMessage(),
            ]));
        }

        if (! is_array($decoded) || $decoded === []) {
            throw new DomainException(__('That file holds no translations.'));
        }

        $objects = 0;
        $scalars = 0;

        foreach ($decoded as $value) {
            is_array($value) ? $objects++ : $scalars++;
        }

        if ($objects > 0 && $scalars > 0) {
            throw new DomainException(__('Mixed shapes in that file: every value must be a translation, or every value must be a catalogue.'));
        }

        /** @var array<string, array<array-key, mixed>> $raw */
        $raw = $scalars > 0
            ? [($group ?? self::JSON_CATALOGUE) => $decoded]
            : $decoded;

        $catalogues = [];

        foreach ($raw as $catalogue => $lines) {
            if (! is_array($lines)) {
                continue;
            }

            $values = [];

            foreach ($lines as $key => $value) {
                if (! is_string($value)) {
                    continue;
                }

                $values[(string) $key] = trim($value) === '' ? null : $value;
            }

            if ($values !== []) {
                $catalogues[(string) $catalogue] = $values;
            }
        }

        if ($catalogues === []) {
            throw new DomainException(__('Nothing in that file could be imported: every value was empty or not a string.'));
        }

        return $catalogues;
    }

    /**
     * What the document would do to $locale, without doing any of it.
     *
     * @param array<string, array<string, string|null>> $catalogues as returned by {@see parse()}
     */
    public function preview(string $locale, array $catalogues): TranslationImportPreview
    {
        $known = $this->catalogue->scan()->catalogues();
        $source = (string) config('app.fallback_locale', 'en');

        $summary = [];
        $writes = [];
        $rejections = [];
        $rejected = 0;

        foreach ($catalogues as $catalogue => $lines) {
            $keys = array_flip($known[$catalogue] ?? []);
            $reference = $this->catalogue->linesFor($source, $catalogue);
            $current = $this->catalogue->linesFor($locale, $catalogue);

            $counts = ['new' => 0, 'changed' => 0, 'unchanged' => 0, 'cleared' => 0, 'unknown' => 0, 'rejected' => 0];

            foreach ($lines as $key => $value) {
                if (! isset($keys[$key])) {
                    $counts['unknown']++;

                    continue;
                }

                $stored = $this->line($catalogue, $current, $key);

                if ($value === null) {
                    $stored === null ? $counts['unchanged']++ : $counts['cleared']++;

                    if ($stored !== null) {
                        $writes[$catalogue][$key] = null;
                    }

                    continue;
                }

                $english = $this->line($catalogue, $reference, $key)
                    ?? ($catalogue === self::JSON_CATALOGUE ? $key : '');

                $missing = Placeholders::missing($english, $value);

                if ($missing !== []) {
                    $counts['rejected']++;
                    $rejected++;

                    if (count($rejections) < TranslationImportPreview::MAX_REJECTIONS) {
                        $rejections[] = ['catalogue' => $catalogue, 'key' => $key, 'missing' => $missing];
                    }

                    continue;
                }

                if ($stored === $value) {
                    $counts['unchanged']++;

                    continue;
                }

                $stored === null ? $counts['new']++ : $counts['changed']++;
                $writes[$catalogue][$key] = $value;
            }

            $summary[$catalogue] = $counts;
        }

        return new TranslationImportPreview(
            catalogues: $summary,
            rejections: $rejections,
            writes: $writes,
            rejectionsTruncated: $rejected > count($rejections),
        );
    }

    /**
     * Write everything the preview said would be written, and nothing else.
     *
     * @return array{written: int, added: int}
     */
    public function apply(
        string $locale,
        TranslationImportPreview $preview,
        ?int $updatedBy = null,
        bool $reviewed = false,
    ): array {
        $written = 0;
        $added = 0;

        foreach ($preview->writes as $catalogue => $values) {
            $result = $this->translations->putMany(
                $locale,
                $catalogue === self::JSON_CATALOGUE ? null : $catalogue,
                $values,
                $updatedBy,
                $reviewed,
            );

            $written += $result['written'];
            $added += $result['added'];
        }

        return ['written' => $written, 'added' => $added];
    }

    /**
     * One line out of a loaded catalogue, or null when this locale has nothing for it.
     *
     * The two catalogues are addressed differently and must stay that way: a JSON key is an
     * English sentence full of full stops, so `Arr::get` would read it as a path and find
     * nothing, while `enums.priority.high` is exactly such a path.
     *
     * @param array<string, mixed> $lines
     */
    private function line(string $catalogue, array $lines, string $key): ?string
    {
        $value = $catalogue === self::JSON_CATALOGUE
            ? ($lines[$key] ?? null)
            : Arr::get($lines, $key);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Localisation;

use App\Services\Translation\Placeholders;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The shipped catalogues, checked against each other rather than against a target.
 *
 * Two failure modes are silent at run time, and both are what this file exists to make loud.
 *
 * A key added to the product and never translated renders English inside an Arabic page,
 * because `__()` returns an unknown key verbatim. Nothing throws, nothing is logged, and the
 * only person who finds out is a reader who does not read English. So a key present in
 * `lang/en.json` and absent from `lang/ar.json` fails the build.
 *
 * A translation that dropped `:count` renders a sentence with the number gone. Laravel
 * substitutes the placeholders it was handed and leaves the rest of the line alone, so there
 * is no exception and no visible gap — the sentence simply stops saying the thing it was
 * written to say. `App\Services\Translation\Placeholders` already refuses such a line in the
 * editor and the importer; this asserts the same rule over what the release ships, which is
 * the one path those two do not cover.
 *
 * A third is quieter still. A line whose translation is byte-identical to the English passes
 * every check above — the key is there, the placeholders are intact, `lang:missing` reports
 * nothing — and an Arabic reader gets an English word anyway. Some of those are correct:
 * `Planvio` is `Planvio` and `HTTPS` is `HTTPS`. Which is why they are not merely counted but
 * *listed*, in {@see self::SAME_AS_ENGLISH}, each with the reason somebody accepted it, and
 * an unlisted one fails. A stale entry fails too, so the list cannot outlive its lines.
 *
 * Group catalogues (`lang/<locale>/*.php`) get the same treatment, flattened to dotted keys,
 * because a dynamic key like `enums.priority.high` can only live there.
 */
final class TranslationIntegrityTest extends TestCase
{
    /** Every language the release ships files for, besides the source language. */
    private const TRANSLATED_LOCALES = ['ar'];

    /**
     * Group files present for English, which every translated locale must also carry.
     *
     * Discovered from `lang/en/` rather than listed, because a group added to the product
     * and forgotten here is exactly the failure this file exists to catch: `defaults` was
     * shipped untested for that reason.
     *
     * @return list<string>
     */
    private static function groupNames(): array
    {
        $files = glob(lang_path('en/*.php')) ?: [];

        return array_values(array_map(
            static fn (string $path): string => basename($path, '.php'),
            $files,
        ));
    }

    /**
     * Lines a translation is expected to leave in English, and why.
     *
     * A translated value identical to its English source is nearly always a line somebody
     * skipped — so the rule is that every one of them is listed here with a reason a person
     * agreed to, and an unlisted one fails. Product names, protocol names, identifiers and
     * strings made entirely of placeholders are the whole of the legitimate set.
     *
     * @var array<string, string>
     */
    private const SAME_AS_ENGLISH = [
        // Names of things, which do not translate.
        'Planvio' => 'The product.',
        'Planvio AI' => 'The product. `Ask Planvio AI` keeps it Latin inside Arabic too.',
        'Laravel' => 'The framework, named on the system-information screen.',
        'PHP' => 'The language, named on the requirements and health screens.',
        'HTTPS' => 'The protocol.',
        'MySQL / MariaDB (pdo_mysql)' => 'A driver name the installer checks for.',
        'Português (Brasil)' => 'An endonym, shown as an example of a BCP-47 code.',

        // Identifiers and examples, which are the same string in every language.
        'client_reference' => 'An example custom-field key.',
        'name@company.com' => 'An example address in an input placeholder.',
        'd/m/Y' => 'A PHP date() format string, shown as an example.',
        'C' => 'A keyboard key, in the command palette.',

        // Punctuation and pure format strings: nothing here is a word.
        '#' => 'The number sign before a task number.',
        '—' => 'An em dash used as an empty value.',
        ':event :subject' => 'Two placeholders and a space.',
        ':milestone — :headline' => 'Placeholders around an em dash.',
        '[:key] :headline' => 'Placeholders and brackets.',
        '[:key] :status' => 'Placeholders and brackets.',
        '[:key] :title' => 'Placeholders and brackets.',
    ];

    /**
     * The same, for the dotted group catalogues.
     *
     * @var array<string, string>
     */
    private const GROUP_SAME_AS_ENGLISH = [
        'enums.ai_driver.openai' => 'A company name.',
        'enums.ai_driver.anthropic' => 'A company name.',
        'enums.ai_trigger.api' => 'Kept Latin inside Arabic prose throughout: مفتاح API, رمز API.',
        'validation.custom.attribute-name.rule-name' => "Laravel's own stub, which nothing renders.",
    ];

    /* ------------------------------------------------------------------ *
     |  Literal strings — lang/<locale>.json
     * ------------------------------------------------------------------ */

    #[Test]
    #[DataProvider('translatedLocales')]
    public function every_literal_english_key_has_a_line_in_the_locale(string $locale): void
    {
        $english = self::literals('en');
        $target = self::literals($locale);

        $missing = array_keys(array_diff_key($english, $target));

        $this->assertSame([], $missing, sprintf(
            "%d of %d keys in lang/en.json have nothing in lang/%s.json. They render English.\n%s",
            count($missing),
            count($english),
            $locale,
            self::sample($missing),
        ));
    }

    #[Test]
    #[DataProvider('translatedLocales')]
    public function no_line_in_the_locale_is_blank(string $locale): void
    {
        $blank = [];

        foreach (self::literals($locale) as $key => $value) {
            if (! is_string($value) || trim($value) === '') {
                $blank[] = (string) $key;
            }
        }

        // An empty string is worse than a missing key: Laravel renders it, so the sentence
        // disappears instead of falling back to the English it was written from.
        $this->assertSame([], $blank, sprintf(
            "lang/%s.json carries %d empty values, which render as nothing at all.\n%s",
            $locale,
            count($blank),
            self::sample($blank),
        ));
    }

    #[Test]
    #[DataProvider('translatedLocales')]
    public function the_locale_carries_no_key_the_product_no_longer_has(string $locale): void
    {
        $orphans = array_keys(array_diff_key(self::literals($locale), self::literals('en')));

        // An orphan is not dangerous, but it is always either a stale line nobody will ever
        // see or a key that was reworded in English and silently lost its translation.
        $this->assertSame([], $orphans, sprintf(
            "lang/%s.json holds %d keys that are not in lang/en.json.\n%s",
            $locale,
            count($orphans),
            self::sample($orphans),
        ));
    }

    #[Test]
    #[DataProvider('translatedLocales')]
    public function no_literal_translation_dropped_a_placeholder(string $locale): void
    {
        $english = self::literals('en');
        $lost = [];

        foreach (self::literals($locale) as $key => $value) {
            if (! isset($english[$key]) || ! is_string($value)) {
                continue;
            }

            $missing = Placeholders::missing((string) $english[$key], $value);

            if ($missing !== []) {
                $lost[] = implode(', ', array_map(static fn (string $n): string => ':'.$n, $missing))
                    .' — '.Placeholders::excerpt((string) $key, 70);
            }
        }

        $this->assertSame([], $lost, sprintf(
            "%d lines in lang/%s.json lost a placeholder the English needs. Laravel substitutes\n".
            "what it was given and leaves the rest alone, so the value is silently gone.\n%s",
            count($lost),
            $locale,
            self::sample($lost),
        ));
    }

    /* ------------------------------------------------------------------ *
     |  Group catalogues — lang/<locale>/<group>.php
     * ------------------------------------------------------------------ */

    #[Test]
    #[DataProvider('translatedLocales')]
    public function every_group_key_has_a_line_in_the_locale(string $locale): void
    {
        foreach (self::groupNames() as $group) {
            $english = self::group('en', $group);
            $target = self::group($locale, $group);

            $this->assertNotSame([], $target, "lang/{$locale}/{$group}.php is missing or empty.");

            $missing = array_keys(array_diff_key($english, $target));

            $this->assertSame([], $missing, sprintf(
                "%d keys in lang/en/%s.php have nothing in lang/%s/%s.php.\n%s",
                count($missing),
                $group,
                $locale,
                $group,
                self::sample($missing),
            ));
        }
    }

    #[Test]
    #[DataProvider('translatedLocales')]
    public function no_group_translation_dropped_a_placeholder(string $locale): void
    {
        $lost = [];

        foreach (self::groupNames() as $group) {
            $english = self::group('en', $group);

            foreach (self::group($locale, $group) as $key => $value) {
                if (! isset($english[$key]) || ! is_string($value) || ! is_string($english[$key])) {
                    continue;
                }

                $missing = Placeholders::missing($english[$key], $value);

                if ($missing !== []) {
                    $lost[] = $group.'.'.$key.' — '.implode(', ', array_map(
                        static fn (string $n): string => ':'.$n,
                        $missing,
                    ));
                }
            }
        }

        $this->assertSame([], $lost, sprintf(
            "%d group lines in lang/%s/ lost a placeholder the English needs.\n%s",
            count($lost),
            $locale,
            self::sample($lost),
        ));
    }

    #[Test]
    #[DataProvider('translatedLocales')]
    public function a_group_key_never_leaks_into_the_json_catalogue(string $locale): void
    {
        $leaked = [];
        $groups = self::groupNames();

        foreach (array_keys(self::literals($locale)) as $key) {
            $prefix = strtok((string) $key, '.');

            if (in_array($prefix, $groups, true) && str_contains((string) $key, '.')) {
                // A dotted group key written into the JSON catalogue shadows the file it was
                // addressing, and Laravel then renders the raw key.
                $leaked[] = (string) $key;
            }
        }

        $this->assertSame([], $leaked, sprintf(
            "lang/%s.json holds %d dotted group keys, which shadow lang/%s/<group>.php.\n%s",
            $locale,
            count($leaked),
            $locale,
            self::sample($leaked),
        ));
    }

    /* ------------------------------------------------------------------ *
     |  Untranslated lines that look translated
     * ------------------------------------------------------------------ */

    /**
     * A value byte-identical to its English is almost always a line somebody skipped.
     *
     * It is the one defect neither of the checks above can see: the key is present, the
     * placeholders are intact, `lang:missing` reports nothing — and an Arabic reader gets an
     * English word. The handful that are genuinely correct are proper nouns, protocol names
     * and identifiers, and every one of them is named in {@see self::SAME_AS_ENGLISH} with a
     * reason, so the list itself is the record of what a person judged.
     */
    #[Test]
    #[DataProvider('translatedLocales')]
    public function no_literal_translation_is_still_its_english(string $locale): void
    {
        $english = self::literals('en');
        $same = [];

        foreach (self::literals($locale) as $key => $value) {
            if (! isset($english[$key]) || ! is_string($value)) {
                continue;
            }

            if ($value !== (string) $english[$key]) {
                continue;
            }

            if (array_key_exists((string) $key, self::SAME_AS_ENGLISH)) {
                continue;
            }

            $same[] = Placeholders::excerpt((string) $key, 90);
        }

        $this->assertSame([], $same, sprintf(
            "%d lines in lang/%s.json are byte-identical to their English. Each is either a\n".
            "line nobody translated or a proper noun — decide which, and list the ones that\n".
            "are correct in TranslationIntegrityTest::SAME_AS_ENGLISH with the reason.\n%s",
            count($same),
            $locale,
            self::sample($same),
        ));
    }

    #[Test]
    #[DataProvider('translatedLocales')]
    public function no_group_translation_is_still_its_english(string $locale): void
    {
        $same = [];

        foreach (self::groupNames() as $group) {
            $english = self::group('en', $group);

            foreach (self::group($locale, $group) as $key => $value) {
                $dotted = $group.'.'.$key;

                if (! isset($english[$key]) || ! is_string($value) || ! is_string($english[$key])) {
                    continue;
                }

                if ($value !== $english[$key] || array_key_exists($dotted, self::GROUP_SAME_AS_ENGLISH)) {
                    continue;
                }

                $same[] = $dotted.' — '.Placeholders::excerpt($value, 60);
            }
        }

        $this->assertSame([], $same, sprintf(
            "%d group lines in lang/%s/ are byte-identical to their English. List the ones\n".
            "that are correct in TranslationIntegrityTest::GROUP_SAME_AS_ENGLISH.\n%s",
            count($same),
            $locale,
            self::sample($same),
        ));
    }

    /**
     * The allow-lists themselves must not go stale.
     *
     * An entry that no longer matches anything is a line that *was* reworded or translated,
     * and leaving it listed would quietly re-permit the same English if the key came back.
     */
    #[Test]
    #[DataProvider('translatedLocales')]
    public function every_allowed_english_line_is_still_english(string $locale): void
    {
        $english = self::literals('en');
        $target = self::literals($locale);
        $stale = [];

        foreach (array_keys(self::SAME_AS_ENGLISH) as $key) {
            if (! isset($english[$key], $target[$key]) || $target[$key] !== $english[$key]) {
                $stale[] = Placeholders::excerpt($key, 90);
            }
        }

        foreach (array_keys(self::GROUP_SAME_AS_ENGLISH) as $dotted) {
            $group = strtok($dotted, '.');
            $key = substr($dotted, strlen((string) $group) + 1);

            $source = self::group('en', (string) $group)[$key] ?? null;
            $line = self::group($locale, (string) $group)[$key] ?? null;

            if ($source === null || $line !== $source) {
                $stale[] = $dotted;
            }
        }

        $this->assertSame([], $stale, sprintf(
            "%d entries in the SAME_AS_ENGLISH lists no longer describe anything: the key is\n".
            "gone, or %s now translates it. Remove them.\n%s",
            count($stale),
            $locale,
            self::sample($stale),
        ));
    }

    /* ------------------------------------------------------------------ *
     |  Fixtures
     * ------------------------------------------------------------------ */

    /** @return iterable<string, array{string}> */
    public static function translatedLocales(): iterable
    {
        foreach (self::TRANSLATED_LOCALES as $locale) {
            yield $locale => [$locale];
        }
    }

    /** @return array<string, mixed> */
    private static function literals(string $locale): array
    {
        $path = lang_path($locale.'.json');

        if (! is_file($path)) {
            return [];
        }

        return (array) json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * A group file flattened to the dotted keys `__()` actually addresses.
     *
     * @return array<string, mixed>
     */
    private static function group(string $locale, string $group): array
    {
        $path = lang_path($locale.'/'.$group.'.php');

        if (! is_file($path)) {
            return [];
        }

        return self::flatten((array) require $path);
    }

    /**
     * @param array<array-key, mixed> $lines
     * @return array<string, mixed>
     */
    private static function flatten(array $lines, string $prefix = ''): array
    {
        $flat = [];

        foreach ($lines as $key => $value) {
            $dotted = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $flat += self::flatten($value, $dotted);

                continue;
            }

            $flat[$dotted] = $value;
        }

        return $flat;
    }

    /**
     * A failure message names enough lines to recognise the fault without printing a
     * catalogue into the terminal.
     *
     * @param list<string> $items
     */
    private static function sample(array $items, int $limit = 12): string
    {
        if ($items === []) {
            return '';
        }

        $shown = array_map(
            static fn (string $item): string => '  · '.Placeholders::excerpt($item, 110),
            array_slice($items, 0, $limit),
        );

        if (count($items) > $limit) {
            $shown[] = sprintf('  … and %d more.', count($items) - $limit);
        }

        return implode("\n", $shown);
    }
}

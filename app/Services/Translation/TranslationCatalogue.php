<?php

declare(strict_types=1);

namespace App\Services\Translation;

use Illuminate\Contracts\Translation\Loader;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;

/**
 * Every translatable string in Planvio, recovered from the source.
 *
 * ## Why a scanner rather than a hand-kept list
 *
 * Planvio addresses almost all of its text by the English sentence itself —
 * `__('Create project')` — because `__()` returns an unknown key verbatim, so English
 * renders correctly whether or not anybody wrote it down. That is convenient and it has one
 * consequence: nothing anywhere records what the product actually says. A second language
 * would render English for every string nobody happened to list. This class is what makes
 * the catalogue exist, and `lang:scan` is what writes it down.
 *
 * ## Two kinds of key, and the difference matters
 *
 * `actions.created` is a *group* key: it addresses `lang/<locale>/actions.php`. `Create
 * project` is a *JSON* key: it addresses `lang/<locale>.json`. Laravel consults the JSON
 * catalogue first, so writing a group key into `en.json` would shadow the group file and
 * render the raw key on screen. A key is treated as a group key only when it reads as a
 * dotted identifier *and* its first segment names a file that exists — anything else is a
 * sentence and goes to JSON.
 *
 * ## What it cannot see
 *
 * A key built by concatenation or interpolation is not in the source to be found. Those
 * call sites are counted, not guessed at, and every consumer reports the count beside the
 * catalogue: see {@see CatalogueScan}.
 */
final class TranslationCatalogue
{
    /**
     * Where user-visible text lives. Migrations, seeders, config and route files are either
     * not shown to anybody or not part of the shipped product's vocabulary.
     *
     * @var list<string>
     */
    private const ROOTS = ['app', 'resources'];

    /**
     * `__`, `trans` and `trans_choice` as calls, `@lang` and `@choice` as Blade directives.
     *
     * The lookbehind keeps `$this->trans(`, `Lang::trans(` and `\trans(`-lookalikes on other
     * classes out of the catalogue: a method that happens to be named `trans` is not the
     * translator.
     */
    private const CALL_PATTERN = '~(?<![\w$\\\\>:])(?:__|trans|trans_choice)\s*\(|@(?:lang|choice)\s*\(~';

    /** A dotted identifier: no spaces, no punctuation beyond the separator. */
    private const DOTTED_KEY = '~^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)+$~';

    private ?CatalogueScan $scan = null;

    /** @var list<string>|null */
    private ?array $groupNames = null;

    /**
     * @param list<string> $langPaths every directory the loader reads groups from, in the
     *                                same order — the framework's own `auth`, `passwords`,
     *                                `pagination` and `validation` catalogues included.
     *                                Miss those and `__('auth.failed')` would be filed as a
     *                                sentence, written into `en.json`, and would then shadow
     *                                the group file it was addressing.
     */
    public function __construct(
        private readonly Filesystem $files,
        private readonly Loader $loader,
        private readonly string $basePath,
        private readonly array $langPaths,
        private readonly string $sourceLocale = 'en',
    ) {}

    /**
     * Walk the source and return every key it can name, grouped.
     *
     * Memoised: a command that scans, then asks what is missing, then reports completion
     * would otherwise read every file three times.
     */
    public function scan(bool $fresh = false): CatalogueScan
    {
        if ($this->scan !== null && ! $fresh) {
            return $this->scan;
        }

        $groupNames = $this->groupNames($fresh);

        $json = [];
        $groups = [];
        $dynamic = 0;
        $occurrences = 0;
        $files = 0;

        foreach ($this->sourceFiles() as $path) {
            $files++;

            foreach ($this->keysIn($this->readable($path)) as $key) {
                if ($key === null) {
                    $dynamic++;

                    continue;
                }

                if ($key === '') {
                    continue;
                }

                $occurrences++;

                $group = $this->groupOf($key, $groupNames);

                if ($group === null) {
                    $json[$key] = true;

                    continue;
                }

                $groups[$group][substr($key, strlen($group) + 1)] = true;
            }
        }

        $this->addShippedGroupKeys($groups, $groupNames);

        $jsonKeys = array_keys($json);
        sort($jsonKeys, SORT_STRING);

        $grouped = [];

        foreach ($groups as $group => $items) {
            $names = array_keys($items);
            sort($names, SORT_STRING);
            $grouped[$group] = $names;
        }

        ksort($grouped, SORT_STRING);

        return $this->scan = new CatalogueScan($jsonKeys, $grouped, $dynamic, $occurrences, $files);
    }

    /**
     * Keys with nothing to render in this locale, as `catalogue => keys`.
     *
     * "Nothing to render" is asked of the loader rather than of the translator, so the
     * answer includes database overrides and so a key whose translation is identical to the
     * English still counts as translated — `Translator::has()` cannot tell those apart,
     * because it decides by comparing the result against the key.
     *
     * @return array<string, list<string>>
     */
    public function missing(string $locale): array
    {
        $missing = [];

        foreach ($this->scan()->catalogues() as $catalogue => $keys) {
            $lines = $this->linesFor($locale, $catalogue);

            $absent = [];

            foreach ($keys as $key) {
                if (! $this->translated($lines, $catalogue, $key)) {
                    $absent[] = $key;
                }
            }

            if ($absent !== []) {
                $missing[$catalogue] = $absent;
            }
        }

        return $missing;
    }

    /**
     * How much of the catalogue this locale can render, 0–100.
     */
    public function completion(string $locale): float
    {
        $total = $this->scan()->total();

        if ($total === 0) {
            return 100.0;
        }

        $missing = array_sum(array_map('count', $this->missing($locale)));

        return round((($total - $missing) / $total) * 100, 1);
    }

    /**
     * The group files this installation ships, lower-cased.
     *
     * Case-insensitively, because Windows and macOS resolve `lang/en/Search.php` and
     * `lang/en/search.php` to the same file — which is the whole reason a bare `__('Search')`
     * has to be listed in `en.json`.
     *
     * @return list<string>
     */
    public function groupNames(bool $fresh = false): array
    {
        if ($this->groupNames !== null && ! $fresh) {
            return $this->groupNames;
        }

        $names = [];

        foreach ($this->langPaths as $path) {
            $directory = rtrim($path, '\\/').DIRECTORY_SEPARATOR.$this->sourceLocale;

            if (! $this->files->isDirectory($directory)) {
                continue;
            }

            foreach ($this->files->files($directory) as $file) {
                if ($file->getExtension() === 'php') {
                    $names[mb_strtolower($file->getFilenameWithoutExtension())] = true;
                }
            }
        }

        $names = array_keys($names);
        sort($names, SORT_STRING);

        return $this->groupNames = $names;
    }

    /**
     * Add every line the source locale's group files already hold.
     *
     * This is how the keys a scan cannot see get into the catalogue anyway. `enums.php` and
     * `search.php` are addressed exclusively as `__('enums.priority.'.$case->value)`, so not
     * one of their keys appears literally in the source — and Laravel's own `validation.php`
     * is never named in Planvio's code at all, though every form in the product renders it.
     * Reading the shipped files is what makes those translatable rather than invisible.
     *
     * @param array<string, array<string, true>> $groups
     * @param list<string> $groupNames
     */
    private function addShippedGroupKeys(array &$groups, array $groupNames): void
    {
        foreach ($groupNames as $group) {
            foreach (Arr::dot($this->linesFor($this->sourceLocale, $group)) as $item => $value) {
                // A leaf that is not text is a structure — `validation.custom` is an empty
                // array waiting for an application to fill it — and there is nothing to
                // translate in it.
                if (is_string($value)) {
                    $groups[$group][(string) $item] = true;
                }
            }
        }
    }

    /**
     * Which group a key addresses, or null when it addresses the JSON catalogue.
     */
    private function groupOf(string $key, array $groupNames): ?string
    {
        if (str_contains($key, ' ') || preg_match(self::DOTTED_KEY, $key) !== 1) {
            return null;
        }

        $first = mb_strtolower(strtok($key, '.') ?: '');

        return in_array($first, $groupNames, true) ? $first : null;
    }

    /**
     * @param array<string, mixed> $lines
     */
    private function translated(array $lines, string $catalogue, string $key): bool
    {
        $value = $catalogue === '*'
            ? ($lines[$key] ?? null)
            : Arr::get($lines, $key);

        return is_string($value) && trim($value) !== '';
    }

    /**
     * What this locale itself holds, with nothing filled in from the source language.
     *
     * {@see DatabaseTranslationLoader::load()} fills the JSON catalogue's gaps with English,
     * because a key that reaches Laravel's group parser can come back as an array and kill
     * the page. Measuring completeness against that would report every language as finished
     * the moment it was created.
     *
     * @return array<string, mixed>
     */
    public function linesFor(string $locale, string $catalogue): array
    {
        if ($this->loader instanceof DatabaseTranslationLoader) {
            return $catalogue === '*'
                ? $this->loader->loadForLocale($locale, '*', '*')
                : $this->loader->loadForLocale($locale, $catalogue);
        }

        return $catalogue === '*'
            ? $this->loader->load($locale, '*', '*')
            : $this->loader->load($locale, $catalogue);
    }

    /**
     * Every key in one file: a string for a literal, null for a call whose key is built at
     * runtime and therefore cannot be catalogued.
     *
     * @return list<string|null>
     */
    public function keysIn(string $source): array
    {
        if (preg_match_all(self::CALL_PATTERN, $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        $keys = [];

        foreach ($matches[0] as [$match, $offset]) {
            $keys[] = $this->literalAt($source, $offset + strlen($match));
        }

        return $keys;
    }

    /**
     * Read the first argument of a call as a PHP string literal.
     *
     * Returns null — "dynamic" — for anything that is not a self-contained literal: a
     * variable, a call, an interpolated double-quoted string, or a literal followed by `.`,
     * which is concatenation and so only the first half of a key.
     */
    private function literalAt(string $source, int $index): ?string
    {
        $length = strlen($source);
        $index = $this->skipSpace($source, $index, $length);

        if ($index >= $length) {
            return null;
        }

        $quote = $source[$index];

        if ($quote !== "'" && $quote !== '"') {
            return null;
        }

        $index++;
        $value = '';
        $closed = false;

        while ($index < $length) {
            $character = $source[$index];

            if ($character === '\\') {
                $value .= $this->unescape($quote, $source[$index + 1] ?? '');
                $index += 2;

                continue;
            }

            if ($character === $quote) {
                $index++;
                $closed = true;

                break;
            }

            // `"Hello $name"` and `"Hello {$name}"` are not keys, they are templates.
            if ($quote === '"' && $character === '$') {
                return null;
            }

            if ($quote === '"' && $character === '{' && ($source[$index + 1] ?? '') === '$') {
                return null;
            }

            $value .= $character;
            $index++;
        }

        if (! $closed) {
            return null;
        }

        $index = $this->skipSpace($source, $index, $length);
        $next = $source[$index] ?? '';

        return $next === ',' || $next === ')' ? $value : null;
    }

    private function skipSpace(string $source, int $index, int $length): int
    {
        while ($index < $length && ($source[$index] === ' '
            || $source[$index] === "\t"
            || $source[$index] === "\n"
            || $source[$index] === "\r")) {
            $index++;
        }

        return $index;
    }

    private function unescape(string $quote, string $character): string
    {
        if ($quote === "'") {
            return match ($character) {
                '\\', "'" => $character,
                default => '\\'.$character,
            };
        }

        return match ($character) {
            'n' => "\n",
            't' => "\t",
            'r' => "\r",
            'e' => "\e",
            'f' => "\f",
            'v' => "\v",
            '\\', '"', '$' => $character,
            default => '\\'.$character,
        };
    }

    /**
     * The file's source with its comments removed.
     *
     * A docblock that quotes `__('Collapse sidebar')` as an example would otherwise put a
     * phantom key in the catalogue. PHP files are tokenised, which is exact; Blade files are
     * stripped of `{{-- --}}` blocks, because the rest of a Blade file is not PHP and cannot
     * be tokenised without compiling it.
     */
    private function readable(string $path): string
    {
        $contents = $this->files->get($path);

        if (str_ends_with($path, '.blade.php')) {
            return (string) preg_replace('~\{\{--.*?--\}\}~s', '', $contents);
        }

        $stripped = '';

        foreach (token_get_all($contents) as $token) {
            if (! is_array($token)) {
                $stripped .= $token;

                continue;
            }

            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }

            $stripped .= $token[1];
        }

        return $stripped;
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $paths = [];

        foreach (self::ROOTS as $root) {
            $directory = $this->basePath.DIRECTORY_SEPARATOR.$root;

            if (! $this->files->isDirectory($directory)) {
                continue;
            }

            foreach ($this->files->allFiles($directory) as $file) {
                if ($file->getExtension() === 'php') {
                    $paths[] = $file->getPathname();
                }
            }
        }

        return $paths;
    }
}

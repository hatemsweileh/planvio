<?php

declare(strict_types=1);

namespace App\Services\Translation;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Translation\FileLoader;

/**
 * The shipped `lang/` files, with an administrator's stored edits merged over the top.
 *
 * Planvio ships English and whatever translations come with a release. An installation that
 * wants "Client" where the product says "Customer", or a corrected phrase in its own
 * language, must not have to edit a file inside the release — the next upgrade would
 * overwrite it. Rows in `translations` win over the file, so an override survives upgrades
 * and can be undone by deleting a row.
 *
 * Three properties this class exists to guarantee:
 *
 *  - **It never throws.** The loader is resolved during boot and `__()` is reached from the
 *    installer, which runs before `migrate`. {@see TranslationRepository} answers with an
 *    empty override set when the table is absent, so the file result is returned unchanged.
 *  - **An edit is visible immediately.** Results are memoised per locale and group for the
 *    request and cached across requests, but the memo key carries the repository's version
 *    stamp — bumped by every write — so nothing has to be flushed by hand.
 *  - **It handles the JSON namespace.** `load($locale, '*', '*')` is Laravel's one-file-per-
 *    locale catalogue, which is where nearly every string in Planvio lives. Those keys are
 *    English sentences containing full stops, so they are merged flat; grouped keys are
 *    merged with dot notation, because `enums.priority.high` addresses a nested array.
 */
final class DatabaseTranslationLoader extends FileLoader
{
    /**
     * Merged results for this request, keyed `version|locale|group`.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $merged = [];

    /**
     * @param array<int, string>|string $path
     */
    public function __construct(
        Filesystem $files,
        array|string $path,
        private readonly TranslationRepository $translations,
    ) {
        parent::__construct($files, $path);
    }

    /**
     * The parameters are untyped because {@see FileLoader::load()} declares them that way;
     * narrowing them here would be an incompatible signature.
     *
     * @param string $locale
     * @param string $group
     * @param string|null $namespace
     * @return array<string, mixed>
     */
    public function load($locale, $group, $namespace = null): array
    {
        $lines = $this->loadForLocale((string) $locale, (string) $group, $namespace === null ? null : (string) $namespace);

        if (! $this->isJsonNamespace((string) $group, $namespace)) {
            return $lines;
        }

        $fallback = (string) config('app.fallback_locale', 'en');

        if ($locale === $fallback) {
            return $lines;
        }

        // Laravel falls back for group files and does not fall back for the JSON catalogue:
        // a key missing from `lang/ar.json` is re-parsed as `group.item`, and a single-word
        // key such as `AI` then finds `lang/en/ai.php` and returns the whole file as an
        // array. Blade escapes that and the page dies with a TypeError — the trap
        // `lang/README.md` documents, which every non-English locale would walk into.
        //
        // Filling the gap from the source locale closes it for good: a key nobody has
        // translated yet renders the English it was written in, which is what a fallback is
        // supposed to mean, and never reaches the group parser at all.
        return array_replace($this->loadForLocale($fallback, '*', '*'), $lines);
    }

    /**
     * The lines for exactly this locale, with nothing filled in from anywhere else.
     *
     * {@see TranslationCatalogue} measures completeness against this rather than against
     * `load()`: a language whose gaps had already been papered over with English would
     * measure as finished.
     *
     * @return array<string, mixed>
     */
    public function loadForLocale(string $locale, string $group, ?string $namespace = null): array
    {
        $lines = parent::load($locale, $group, $namespace);

        $catalogue = $this->catalogue($group, $namespace);
        $memoKey = $this->translations->version().'|'.$locale.'|'.($catalogue ?? '*');

        if (array_key_exists($memoKey, $this->merged)) {
            return $this->merged[$memoKey];
        }

        $overrides = $this->translations->lines($locale, $catalogue);

        if ($overrides === []) {
            return $this->merged[$memoKey] = $lines;
        }

        return $this->merged[$memoKey] = $catalogue === null
            ? array_replace($lines, $overrides)
            : $this->mergeGrouped($lines, $overrides);
    }

    private function isJsonNamespace(string $group, ?string $namespace): bool
    {
        return $group === '*' && $namespace === '*';
    }

    /**
     * Which catalogue in `translations` backs this load, or null for the JSON namespace.
     *
     * A vendor-namespaced group is stored as `namespace::group` so a package's strings can
     * be overridden without colliding with Planvio's own group of the same name.
     */
    private function catalogue(string $group, ?string $namespace): ?string
    {
        if ($group === '*' && $namespace === '*') {
            return null;
        }

        if ($namespace === null || $namespace === '*') {
            return $group;
        }

        return $namespace.'::'.$group;
    }

    /**
     * Grouped keys are paths into a nested array, so `Arr::set` rather than assignment:
     * a stored `priority.high` has to land beside the file's other priorities instead of
     * creating a literal `'priority.high'` entry the translator would never look up.
     *
     * @param array<string, mixed> $lines
     * @param array<string, string> $overrides
     * @return array<string, mixed>
     */
    private function mergeGrouped(array $lines, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            Arr::set($lines, $key, $value);
        }

        return $lines;
    }
}

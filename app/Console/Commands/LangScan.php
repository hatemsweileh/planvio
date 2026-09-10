<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Translation\CatalogueScan;
use App\Services\Translation\TranslationCatalogue;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use JsonException;

/**
 * Rebuild `lang/en.json` from the source.
 *
 * Planvio calls `__('Create project')`, not `__('projects.create')`, and Laravel returns an
 * unknown key verbatim — so English has always rendered correctly whether or not anybody
 * wrote the string down anywhere. The catalogue was therefore empty, and a second language
 * would have rendered English for every string in the product.
 *
 * This is what writes it down. Every literal key the source passes to the translator gets an
 * entry mapping to itself, which changes nothing about how English renders and gives every
 * other language something to translate against.
 *
 * Two entries are never removed by a scan:
 *
 *  - one whose value differs from its key, which is an installation that has reworded its
 *    own English and is not this command's to discard;
 *  - anything at all, when `--keep-orphans` is passed.
 */
final class LangScan extends Command
{
    protected $signature = 'lang:scan
        {--dry-run : Report what would change without writing the file}
        {--keep-orphans : Keep entries whose key is no longer called anywhere}';

    protected $description = 'Rebuild lang/en.json from every literal string the source passes to __().';

    public function handle(TranslationCatalogue $catalogue, Filesystem $files): int
    {
        $scan = $catalogue->scan(fresh: true);
        $path = lang_path('en.json');

        $existing = $this->read($files, $path);

        if ($existing === null) {
            $this->components->error(__('lang/en.json exists but is not valid JSON. Fix or delete it, then run this again.'));

            return self::FAILURE;
        }

        $map = [];
        $added = 0;
        $reworded = 0;

        foreach ($scan->json as $key) {
            $current = $existing[$key] ?? null;

            if (! is_string($current)) {
                $added++;
                $map[$key] = $key;

                continue;
            }

            if ($current !== $key) {
                $reworded++;
            }

            $map[$key] = $current;
        }

        $orphans = array_diff_key($existing, $map);
        $kept = 0;
        $removed = 0;

        foreach ($orphans as $key => $value) {
            $key = (string) $key;

            if ($this->option('keep-orphans') || (is_string($value) && $value !== $key)) {
                $map[$key] = is_string($value) ? $value : $key;
                $kept++;

                continue;
            }

            $removed++;
        }

        ksort($map, SORT_STRING);

        if (! $this->option('dry-run')) {
            $files->ensureDirectoryExists(dirname($path));
            $files->put($path, $this->encode($map));
        }

        $this->report($scan, count($map), $added, $reworded, $kept, $removed);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null null when the file is unreadable as JSON
     */
    private function read(Filesystem $files, string $path): ?array
    {
        if (! $files->exists($path)) {
            return [];
        }

        try {
            $decoded = json_decode($files->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, string> $map
     */
    private function encode(array $map): string
    {
        // Unescaped so the file stays readable and diffable: a key is an English sentence,
        // and `—` in place of an em dash makes a review of it unreadable.
        return json_encode(
            $map,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";
    }

    private function report(
        CatalogueScan $scan,
        int $total,
        int $added,
        int $reworded,
        int $kept,
        int $removed,
    ): void {
        $rows = [
            [__('Files read'), (string) $scan->files],
            [__('Call sites read'), (string) $scan->occurrences],
            [__('Literal keys in lang/en.json'), (string) $total],
            [__('Newly catalogued'), (string) $added],
            [__('Reworded locally, left alone'), (string) $reworded],
            [__('No longer called, kept'), (string) $kept],
            [__('No longer called, removed'), (string) $removed],
        ];

        foreach ($scan->groups as $group => $items) {
            $rows[] = [__('Group :group', ['group' => $group]), (string) count($items)];
        }

        $rows[] = [__('Keys built at runtime, not catalogued'), (string) $scan->dynamic];

        $this->table([__('Measure'), __('Count')], $rows);

        if ($scan->dynamic > 0) {
            // Stated rather than buried. These call sites are invisible to any scanner, and
            // the only reason their keys are in the catalogue at all is that they live in a
            // `lang/en/*.php` group, whose contents are read directly. A dynamic key that is
            // not in a group file is a string no translator will ever be offered.
            $this->components->warn(__(
                ':count call sites build their key at runtime. Those keys are catalogued from the group files, which is the only place they can be found — never from a literal string.',
                ['count' => $scan->dynamic],
            ));
        }

        if ($this->option('dry-run')) {
            $this->components->info(__('Nothing was written: this was a dry run.'));
        }
    }
}

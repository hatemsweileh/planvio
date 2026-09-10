<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Translation\TranslationCatalogue;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use stdClass;

/**
 * Write a locale's catalogue out as JSON, for a translator or for version control.
 *
 * Every key in the catalogue is emitted, translated or not, with an empty string where there
 * is nothing yet — a file containing only what is already done is of no use to the person
 * whose job is the rest. `lang:import` reads exactly this shape back.
 *
 * Without `--group` the document is keyed by catalogue, `*` being the literal strings:
 *
 *     {"*": {"Create project": "…"}, "actions": {"tasks.copy_of": "…"}}
 *
 * With `--group` it is the flat map for that one catalogue, which is what most translation
 * tools expect to be handed.
 */
final class LangExport extends Command
{
    protected $signature = 'lang:export
        {locale : The language code, e.g. ar}
        {--group= : One catalogue only — a group name, or * for the literal strings}
        {--missing : Only keys with no translation yet}
        {--output= : Write to this file instead of standard output}';

    protected $description = 'Export a locale as JSON, to a file or to standard output.';

    public function handle(TranslationCatalogue $catalogue, Filesystem $files): int
    {
        $locale = (string) $this->argument('locale');
        $only = $this->option('group');
        $missingOnly = (bool) $this->option('missing');

        $missing = $missingOnly ? $catalogue->missing($locale) : [];
        $document = [];

        foreach ($catalogue->scan()->catalogues() as $group => $keys) {
            if (is_string($only) && $only !== '' && $group !== $only) {
                continue;
            }

            // What this locale itself holds. Asking the translator would hand back the
            // English that fills the gaps at render time, and a translator opening the file
            // would find the work already done.
            $lines = $catalogue->linesFor($locale, $group);

            $wanted = $missingOnly ? ($missing[$group] ?? []) : $keys;

            foreach ($wanted as $key) {
                $value = $group === '*' ? ($lines[$key] ?? null) : Arr::get($lines, $key);

                $document[$group][$key] = is_string($value) ? $value : '';
            }
        }

        if (is_string($only) && $only !== '') {
            $document = $document[$only] ?? [];
        }

        $json = json_encode(
            $document === [] ? new stdClass : $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";

        $output = $this->option('output');

        if (! is_string($output) || $output === '') {
            $this->output->write($json);

            return self::SUCCESS;
        }

        $files->ensureDirectoryExists(dirname($output));
        $files->put($output, $json);

        $this->components->info(__('Wrote :path.', ['path' => $output]));

        return self::SUCCESS;
    }
}

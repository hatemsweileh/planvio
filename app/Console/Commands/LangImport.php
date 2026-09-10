<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Translation\TranslationRepository;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use JsonException;

/**
 * Merge a translated JSON file into the `translations` table.
 *
 * Rows, not files: an override written here survives an upgrade, where an edit inside
 * `lang/` would be replaced by the next release ZIP.
 *
 * Two shapes are accepted, both of which `lang:export` produces. A document whose every
 * value is an object is read as catalogue => (key => translation), with `*` meaning the
 * literal strings. A flat map of strings is read as one catalogue, named by `--group` and
 * defaulting to the literal ones. A mixture is refused rather than guessed at.
 *
 * An empty string is stored as null — "known, still untranslated" — because a row holding
 * `""` would blank the shipped English rather than fall back to it.
 */
final class LangImport extends Command
{
    protected $signature = 'lang:import
        {locale : The language code, e.g. ar}
        {file : Path to the JSON file}
        {--group= : Treat a flat file as this catalogue (default: the literal strings)}
        {--reviewed : Mark every imported line as reviewed}
        {--dry-run : Report what would be written without writing it}';

    protected $description = 'Merge a JSON translation file into the translations table.';

    public function handle(TranslationRepository $translations, Filesystem $files): int
    {
        $locale = (string) $this->argument('locale');
        $path = (string) $this->argument('file');

        if (! $files->exists($path)) {
            $this->components->error(__('No file at :path.', ['path' => $path]));

            return self::FAILURE;
        }

        try {
            $decoded = json_decode($files->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->components->error(__(':path is not valid JSON: :message', ['path' => $path, 'message' => $e->getMessage()]));

            return self::FAILURE;
        }

        if (! is_array($decoded) || $decoded === []) {
            $this->components->error(__(':path holds no translations.', ['path' => $path]));

            return self::FAILURE;
        }

        $catalogues = $this->catalogues($decoded);

        if ($catalogues === null) {
            $this->components->error(__('Mixed shapes in :path: every value must be a translation, or every value must be a catalogue.', ['path' => $path]));

            return self::FAILURE;
        }

        $reviewed = (bool) $this->option('reviewed');
        $dryRun = (bool) $this->option('dry-run');
        $rows = [];

        foreach ($catalogues as $catalogue => $lines) {
            $group = $catalogue === '*' ? null : $catalogue;
            $values = [];

            foreach ($lines as $key => $value) {
                if (! is_string($value)) {
                    continue;
                }

                $values[(string) $key] = trim($value) === '' ? null : $value;
            }

            if ($values === []) {
                continue;
            }

            if ($dryRun) {
                $rows[] = [$catalogue, (string) count($values), '—'];

                continue;
            }

            $result = $translations->putMany($locale, $group, $values, auth()->id(), $reviewed);

            $rows[] = [$catalogue, (string) $result['written'], (string) $result['added']];
        }

        if ($rows === []) {
            $this->components->warn(__('Nothing to import: every value in the file was empty or not a string.'));

            return self::SUCCESS;
        }

        $this->table([__('Catalogue'), __('Lines'), __('New rows')], $rows);

        $this->components->info($dryRun
            ? __('Nothing was written: this was a dry run.')
            : __('Imported into :locale.', ['locale' => $locale]));

        return self::SUCCESS;
    }

    /**
     * Normalise either accepted shape into catalogue => lines.
     *
     * @param array<mixed> $decoded
     * @return array<string, array<string, mixed>>|null null when the document mixes both
     */
    private function catalogues(array $decoded): ?array
    {
        $objects = 0;
        $scalars = 0;

        foreach ($decoded as $value) {
            is_array($value) ? $objects++ : $scalars++;
        }

        if ($objects > 0 && $scalars > 0) {
            return null;
        }

        if ($scalars > 0) {
            $group = $this->option('group');

            return [is_string($group) && $group !== '' ? $group : '*' => $decoded];
        }

        /** @var array<string, array<string, mixed>> $decoded */
        return $decoded;
    }
}

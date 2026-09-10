<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Locale;
use App\Services\Translation\TranslationCatalogue;
use App\Services\Translation\TranslationRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Give every enabled language a row for every key the product uses.
 *
 * `lang:scan` writes down what English says; this writes down what every other language has
 * been asked to say. Until a key has a row there is nothing for a translation screen to
 * list, and a language's progress cannot be measured against anything.
 *
 * New rows carry a null value, which the loader ignores — so syncing a language never
 * changes a single rendered string. Existing rows are never touched: a sync must not
 * overwrite somebody's work, and re-running it must be free.
 *
 * `--prune` removes rows for keys the source no longer contains, and only ever the
 * untranslated ones. Deleting a translation because a string was reworded this morning would
 * throw away the only copy of somebody's afternoon.
 */
final class LangSync extends Command
{
    protected $signature = 'lang:sync
        {--locale=* : Only these language codes (default: every enabled locale)}
        {--prune : Also delete untranslated rows for keys that are no longer used}';

    protected $description = 'Add every catalogue key to each enabled locale as an untranslated row.';

    public function handle(TranslationCatalogue $catalogue, TranslationRepository $translations): int
    {
        $locales = $this->locales();

        if ($locales === []) {
            $this->components->warn(__('No enabled locales to sync. Seed or enable one first.'));

            return self::SUCCESS;
        }

        $scan = $catalogue->scan(fresh: true);
        $catalogues = $scan->catalogues();
        $rows = [];

        foreach ($locales as $locale) {
            $added = 0;
            $pruned = 0;

            foreach ($catalogues as $group => $keys) {
                $added += $translations->addMissing($locale, $group === '*' ? null : $group, $keys);
            }

            if ($this->option('prune')) {
                $pruned = $this->prune($locale, $catalogues);
            }

            $rows[] = [
                $locale,
                (string) $added,
                (string) $pruned,
                $catalogue->completion($locale).'%',
            ];
        }

        $translations->flush();

        $this->table([__('Locale'), __('Added'), __('Pruned'), __('Complete')], $rows);

        $this->components->info(__('Catalogue: :total keys in :groups catalogues, :dynamic built at runtime.', [
            'total' => $scan->total(),
            'groups' => count($catalogues),
            'dynamic' => $scan->dynamic,
        ]));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function locales(): array
    {
        /** @var list<string> $requested */
        $requested = array_values(array_filter(array_map(
            static fn (mixed $code): string => trim((string) $code),
            (array) $this->option('locale'),
        )));

        $enabled = Locale::enabledByCode()->keys()->all();

        if ($requested === []) {
            /** @var list<string> */
            return $enabled;
        }

        $unknown = array_diff($requested, $enabled);

        foreach ($unknown as $code) {
            $this->components->warn(__(':code is not an enabled locale and was skipped.', ['code' => $code]));
        }

        return array_values(array_intersect($requested, $enabled));
    }

    /**
     * Drop untranslated rows whose key the source no longer contains.
     *
     * @param array<string, list<string>> $catalogues
     */
    private function prune(string $locale, array $catalogues): int
    {
        $pruned = 0;

        foreach ($catalogues as $group => $keys) {
            $wanted = [];

            foreach ($keys as $key) {
                $wanted[TranslationRepository::hash($group === '*' ? null : $group, $key)] = true;
            }

            $query = DB::table('translations')
                ->where('locale', $locale)
                ->whereNull('value')
                ->when($group === '*',
                    static fn ($builder) => $builder->whereNull('group'),
                    static fn ($builder) => $builder->where('group', $group),
                );

            $stale = [];

            foreach ($query->get(['id', 'key_hash']) as $row) {
                if (! isset($wanted[(string) $row->key_hash])) {
                    $stale[] = (int) $row->id;
                }
            }

            foreach (array_chunk($stale, 500) as $chunk) {
                $pruned += DB::table('translations')->whereIn('id', $chunk)->delete();
            }
        }

        return $pruned;
    }
}

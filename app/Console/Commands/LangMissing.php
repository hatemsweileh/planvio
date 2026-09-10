<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Locale;
use App\Services\Translation\TranslationCatalogue;
use Illuminate\Console\Command;

/**
 * What a language still cannot say.
 *
 * A key counts as translated when the loader — files plus stored overrides — has a non-empty
 * string for it. Deliberately not `Translator::has()`, which decides by comparing the result
 * against the key and so reports a correct translation that happens to match the English as
 * missing. "Email" is "Email" in a great many languages.
 */
final class LangMissing extends Command
{
    /** How many keys are printed per catalogue before the rest are summarised. */
    private const DEFAULT_LIMIT = 40;

    protected $signature = 'lang:missing
        {locale : The language code, e.g. ar}
        {--group= : One catalogue only — a group name, or * for the literal strings}
        {--limit= : Keys to print per catalogue (default 40)}
        {--all : Print every key, however many there are}';

    protected $description = 'List the catalogue keys a locale has no translation for.';

    public function handle(TranslationCatalogue $catalogue): int
    {
        $locale = (string) $this->argument('locale');

        if (! $this->known($locale)) {
            $this->components->warn(__('No enabled locale is stored for :code — reporting against the files and overrides anyway.', ['code' => $locale]));
        }

        $missing = $catalogue->missing($locale);
        $only = $this->option('group');

        if (is_string($only) && $only !== '') {
            $missing = array_intersect_key($missing, [$only => true]);
        }

        if ($missing === []) {
            $this->components->info(is_string($only) && $only !== ''
                ? __('Nothing is missing from :group in :locale.', ['group' => $only, 'locale' => $locale])
                : __('Nothing is missing: :locale can render the whole catalogue.', ['locale' => $locale]));

            return self::SUCCESS;
        }

        $limit = $this->option('all') ? PHP_INT_MAX : max(1, (int) ($this->option('limit') ?: self::DEFAULT_LIMIT));
        $total = 0;

        foreach ($missing as $group => $keys) {
            $total += count($keys);

            $this->newLine();
            $this->components->twoColumnDetail(
                '<fg=yellow>'.($group === '*' ? __('Literal strings (lang/:locale.json)', ['locale' => $locale]) : $group).'</>',
                (string) count($keys),
            );

            foreach (array_slice($keys, 0, $limit) as $key) {
                $this->line('  '.$this->trim($key));
            }

            if (count($keys) > $limit) {
                $this->line('  <fg=gray>'.__('… and :count more. Pass --all to see them.', ['count' => count($keys) - $limit]).'</>');
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail(
            __('Untranslated'),
            __(':count of :total keys · :percent% complete', [
                'count' => $total,
                'total' => $catalogue->scan()->total(),
                'percent' => (string) $catalogue->completion($locale),
            ]),
        );

        return self::SUCCESS;
    }

    private function known(string $locale): bool
    {
        return Locale::enabledByCode()->has($locale);
    }

    /**
     * Keys are whole sentences and some run to three hundred characters; a terminal listing
     * is for recognising them, not for reading them.
     */
    private function trim(string $key): string
    {
        $key = preg_replace('~\s+~u', ' ', $key) ?? $key;

        return mb_strlen($key) > 110 ? mb_substr($key, 0, 109).'…' : $key;
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Exceptions\DomainException;
use App\Filament\Resources\Locales\LocaleResource;
use App\Filament\Support\AdminAudit;
use App\Filament\Support\NavigationGroup;
use App\Filament\Support\PlatformPage;
use App\Jobs\TranslateLocaleJob;
use App\Models\Locale;
use App\Services\Translation\DatabaseTranslationLoader;
use App\Services\Translation\Placeholders;
use App\Services\Translation\TranslationCatalogue;
use App\Services\Translation\TranslationImporter;
use App\Services\Translation\TranslationImportPreview;
use App\Services\Translation\TranslationRepository;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Translating Planvio from inside Planvio.
 *
 * # Why this is a page and not a resource
 *
 * A Filament resource edits one record at a time, and the unit of work here is not a record —
 * it is a catalogue: several thousand English strings beside their translations, filtered down
 * to the forty a person is actually going to write this afternoon. `translations` has a row per
 * key per language, so a resource would technically fit, and it would be the wrong shape: a
 * translator would spend the day opening and closing edit forms and would never see the English
 * next to what they had just written.
 *
 * # The two catalogues, kept apart
 *
 * Laravel addresses `lang/<locale>.json` by the English sentence itself and `lang/<locale>/
 * <group>.php` by a dotted path, and the two must never be confused: a group key written into
 * the JSON catalogue shadows the file it was addressing and puts the raw key on screen
 * (`lang/README.md`). The grouping control here is that distinction made visible — `*` is the
 * literal strings, everything else is a group file — and every read and write on this screen
 * carries the catalogue with it.
 *
 * # Placeholders are refused, not warned about
 *
 * A translation that lost `:count` renders as a fluent sentence with the number silently gone,
 * and nothing at run time will ever say so ({@see Placeholders}). So a save containing one is
 * refused entirely — not partially applied with a warning — and the message names the line and
 * the tokens. This is the single most common way a translated application breaks, and it is the
 * one moment at which it is visible.
 *
 * # Nothing here needs an artisan command afterwards
 *
 * {@see TranslationRepository::put()} bumps a version stamp that every cache key in
 * {@see DatabaseTranslationLoader} carries, and clears the translator's own loaded lines. An
 * edit is therefore live on the next request, in every process, with no cache clear — which is
 * the difference between a screen an administrator can use and a screen that lies to them.
 */
final class Translations extends PlatformPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Platform;

    protected static ?int $navigationSort = 60;

    protected string $view = 'filament.pages.translations';

    /** The catalogue key Laravel's JSON namespace is addressed by. */
    public const JSON = TranslationImporter::JSON_CATALOGUE;

    public const FILTER_ALL = 'all';

    public const FILTER_UNTRANSLATED = 'untranslated';

    public const FILTER_UNREVIEWED = 'unreviewed';

    public const FILTER_STALE = 'stale';

    /** Lines on screen at once. Enough to work in, small enough to save in one request. */
    private const PER_PAGE = 40;

    /** An uploaded catalogue. Five megabytes is several times the whole English one. */
    private const MAX_UPLOAD_KB = 5120;

    public string $locale = '';

    public string $catalogue = self::JSON;

    public string $filter = self::FILTER_ALL;

    public string $search = '';

    public int $page = 1;

    /**
     * Draft translations for the lines on screen, keyed by the row hash rather than by the key
     * itself: a key is an English sentence full of full stops, and Livewire reads a dot in a
     * property path as a level of nesting.
     *
     * @var array<string, string>
     */
    public array $values = [];

    /** @var array<string, bool> */
    public array $reviewed = [];

    public ?TemporaryUploadedFile $upload = null;

    public bool $importReviewed = false;

    /**
     * What the staged upload would change, as {@see TranslationImportPreview::toSummary()}
     * produced it. The file itself stays where Livewire put it and is read again on apply, so
     * this property carries counts rather than a megabyte of JSON.
     *
     * @var array<string, mixed>
     */
    public array $importSummary = [];

    public static function getNavigationLabel(): string
    {
        return __('Translations');
    }

    public function getTitle(): string
    {
        return __('Translations');
    }

    public function getSubheading(): ?string
    {
        return __('The English the product ships with, beside what this installation says instead. Saved lines are live on the next request.');
    }

    public function mount(): void
    {
        $requested = request()->query('locale');

        $this->locale = $this->resolveLocale(is_string($requested) ? $requested : null);
    }

    /* ------------------------------------------------------------------ *
     * Controls
     * ------------------------------------------------------------------ */

    public function updatedLocale(): void
    {
        $this->locale = $this->resolveLocale($this->locale);
        $this->resetPage();
    }

    public function updatedCatalogue(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);

        // The drafts belong to the lines that were on screen; carrying them to the next page
        // would bind somebody's half-written sentence to a different key.
        $this->values = [];
        $this->reviewed = [];
    }

    private function resetPage(): void
    {
        $this->goToPage(1);
    }

    /* ------------------------------------------------------------------ *
     * Saving
     * ------------------------------------------------------------------ */

    /**
     * Write the lines that changed, or refuse the lot.
     *
     * Every changed line is validated before anything is written. A partial save — the good
     * lines through, the broken one reported — would leave the person with no way to tell which
     * of their forty edits had landed, and would put the broken line back in front of them with
     * its neighbours already committed.
     */
    public function save(): void
    {
        $rows = $this->rows()['rows'];
        $group = $this->group();
        $refusals = [];

        /** @var array<int, array<string, string|null>> $changes reviewed flag => key => value */
        $changes = [0 => [], 1 => []];
        $touched = 0;

        foreach ($rows as $row) {
            $hash = $row['hash'];

            if (! array_key_exists($hash, $this->values)) {
                continue;
            }

            $draft = $this->values[$hash];
            $isReviewed = (bool) ($this->reviewed[$hash] ?? false);

            if ($draft === $row['value'] && $isReviewed === $row['reviewed']) {
                continue;
            }

            if (trim($draft) === '') {
                // An empty line is "nothing stored here", which falls back to the shipped
                // English. A row holding "" would blank the text instead.
                $changes[0][$row['key']] = null;
                $touched++;

                continue;
            }

            $missing = Placeholders::missing($row['reference'], $draft);

            if ($missing !== []) {
                $refusals[] = Placeholders::refusal($row['key'], $missing);

                continue;
            }

            $changes[$isReviewed ? 1 : 0][$row['key']] = $draft;
            $touched++;
        }

        if ($refusals !== []) {
            Notification::make()
                ->title(trans_choice(
                    '{1} Nothing was saved: one line dropped a placeholder|[2,*] Nothing was saved: :count lines dropped a placeholder',
                    count($refusals),
                    ['count' => count($refusals)],
                ))
                ->body(implode("\n\n", array_slice($refusals, 0, 5)))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        if ($touched === 0) {
            Notification::make()
                ->title(__('Nothing had changed.'))
                ->send();

            return;
        }

        $translations = app(TranslationRepository::class);
        $userId = self::administrator()?->getKey();

        foreach ($changes as $isReviewed => $values) {
            if ($values !== []) {
                $translations->putMany($this->locale, $group, $values, $userId, (bool) $isReviewed);
            }
        }

        LocaleResource::forgetStatistics();

        AdminAudit::record(
            'admin.translations_saved',
            __('Translations edited from the administration panel.'),
            properties: [
                'locale' => $this->locale,
                'catalogue' => $this->catalogue,
                'lines' => $touched,
            ],
        );

        $this->values = [];
        $this->reviewed = [];

        Notification::make()
            ->title(trans_choice('{1} 1 line saved|[2,*] :count lines saved', $touched, ['count' => $touched]))
            ->body(__('Live on the next request. No cache clear is needed.'))
            ->success()
            ->send();
    }

    /* ------------------------------------------------------------------ *
     * Export
     * ------------------------------------------------------------------ */

    /**
     * The whole language as one JSON document, in the shape `lang:import` reads back.
     *
     * Every key is present, translated or not, because a file holding only the finished lines
     * is of no use to the person whose job is the rest — the same reasoning `lang:export`
     * applies. `--missing` is the `$missingOnly` argument here.
     */
    public function export(bool $missingOnly = false): StreamedResponse
    {
        $catalogue = app(TranslationCatalogue::class);
        $locale = $this->locale;
        $missing = $missingOnly ? $catalogue->missing($locale) : [];

        $document = [];

        foreach ($catalogue->scan()->catalogues() as $name => $keys) {
            $lines = $catalogue->linesFor($locale, $name);
            $wanted = $missingOnly ? ($missing[$name] ?? []) : $keys;

            foreach ($wanted as $key) {
                $value = $name === self::JSON ? ($lines[$key] ?? null) : Arr::get($lines, $key);

                $document[$name][$key] = is_string($value) ? $value : '';
            }
        }

        $json = json_encode(
            $document === [] ? new stdClass : $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $filename = 'planvio-'.Str::slug($locale).($missingOnly ? '-untranslated' : '').'.json';

        return response()->streamDownload(
            static function () use ($json): void {
                echo is_string($json) ? $json : '{}';
            },
            $filename,
            ['Content-Type' => 'application/json'],
        );
    }

    /* ------------------------------------------------------------------ *
     * Import
     * ------------------------------------------------------------------ */

    /**
     * Work out what the uploaded file would do, and show it. Nothing is written here.
     */
    public function previewImport(): void
    {
        $this->validate([
            'upload' => ['required', 'file', 'max:'.self::MAX_UPLOAD_KB],
        ], attributes: ['upload' => __('file')]);

        try {
            $preview = app(TranslationImporter::class)->preview($this->locale, $this->parseUpload());
        } catch (DomainException $exception) {
            $this->importSummary = [];

            Notification::make()
                ->title(__('That file could not be read.'))
                ->body($exception->userMessage())
                ->danger()
                ->send();

            return;
        }

        $this->importSummary = $preview->toSummary();
    }

    /**
     * Apply what the preview described.
     *
     * The file is parsed and analysed again rather than trusted from the component's state:
     * somebody may have translated a line by hand between the preview and the click, and the
     * counts reported afterwards have to be what actually happened.
     */
    public function applyImport(): void
    {
        if ($this->importSummary === []) {
            return;
        }

        $importer = app(TranslationImporter::class);

        try {
            $preview = $importer->preview($this->locale, $this->parseUpload());
        } catch (DomainException $exception) {
            Notification::make()
                ->title(__('That file could not be read.'))
                ->body($exception->userMessage())
                ->danger()
                ->send();

            return;
        }

        $result = $importer->apply($this->locale, $preview, self::administrator()?->getKey(), $this->importReviewed);

        LocaleResource::forgetStatistics();

        AdminAudit::record(
            'admin.translations_imported',
            __('Translations imported from the administration panel.'),
            properties: [
                'locale' => $this->locale,
                'written' => $result['written'],
                'added' => $result['added'],
                'rejected' => $preview->total('rejected'),
                'reviewed' => $this->importReviewed,
            ],
        );

        $this->discardImport();

        Notification::make()
            ->title(trans_choice('{0} Nothing needed changing|{1} 1 line imported|[2,*] :count lines imported', $result['written'], [
                'count' => number_format($result['written']),
            ]))
            ->body($preview->total('rejected') > 0
                ? __(':count lines were left out because they had lost a placeholder.', ['count' => number_format($preview->total('rejected'))])
                : null)
            ->success()
            ->send();
    }

    /**
     * Drop the staged file and the drafts on screen.
     *
     * Clearing the drafts is not tidiness. They were seeded from what the lines held before
     * the import, so a save straight afterwards would write every one of them back and undo
     * what was just imported, line by line, with nothing to say it had happened.
     */
    public function discardImport(): void
    {
        $this->importSummary = [];
        $this->upload = null;
        $this->values = [];
        $this->reviewed = [];
    }

    /**
     * @return array<string, array<string, string|null>>
     *
     * @throws DomainException
     */
    private function parseUpload(): array
    {
        $upload = $this->upload;

        if (! $upload instanceof TemporaryUploadedFile) {
            throw new DomainException(__('The uploaded file is no longer there. Choose it again.'));
        }

        // A flat document belongs to whichever catalogue is on screen, which is what somebody
        // exporting one group and sending it to a translator would expect back.
        return app(TranslationImporter::class)->parse($upload->get(), $this->group());
    }

    /* ------------------------------------------------------------------ *
     * Actions
     * ------------------------------------------------------------------ */

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->aiAction(),
            $this->syncAction(),
        ];
    }

    /**
     * Ask the model for the lines nobody has written yet.
     *
     * Hidden rather than disabled when the AI layer is off: a button that exists only to
     * explain why it cannot be pressed is worse than no button, and the reason is already on
     * screen for anybody who wants it.
     */
    private function aiAction(): Action
    {
        return Action::make('translateWithAi')
            ->label(__('Translate untranslated strings'))
            ->icon(Heroicon::OutlinedSparkles)
            ->visible(fn (): bool => TranslateLocaleJob::isAvailable() && $this->locale !== $this->sourceLocale())
            ->requiresConfirmation()
            ->modalHeading(fn (): string => __('Ask the AI for a first pass at :locale?', ['locale' => $this->localeLabel()]))
            ->modalDescription(__('Up to :limit untranslated lines are sent to the configured provider in batches, and what comes back is stored unreviewed. Any line that lost a placeholder is discarded rather than stored. Nothing already translated is touched, and nothing is ever marked reviewed by this.', [
                'limit' => number_format(TranslateLocaleJob::DEFAULT_LIMIT),
            ]))
            ->modalSubmitActionLabel(__('Start'))
            ->action(function (): void {
                $refusal = TranslateLocaleJob::refusal();

                if ($refusal !== null) {
                    Notification::make()
                        ->title(__('The AI layer refused.'))
                        ->body($refusal)
                        ->danger()
                        ->send();

                    return;
                }

                TranslateLocaleJob::markQueued($this->locale);

                TranslateLocaleJob::dispatch(
                    $this->locale,
                    null,
                    self::administrator()?->getKey(),
                    TranslateLocaleJob::DEFAULT_LIMIT,
                );

                AdminAudit::record(
                    'admin.translations_ai_requested',
                    __('AI translation pass requested from the administration panel.'),
                    properties: ['locale' => $this->locale, 'limit' => TranslateLocaleJob::DEFAULT_LIMIT],
                );

                Notification::make()
                    ->title(__('Queued.'))
                    ->body(__('It runs on the queue worker. Progress appears on this page; the queue cron has to be running for anything to happen.'))
                    ->success()
                    ->send();
            });
    }

    private function syncAction(): Action
    {
        return Action::make('sync')
            ->label(__('Sync catalogue'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('Sync the catalogue into this language?'))
            ->modalDescription(__('Every key the product uses gets a row here, untranslated. Existing rows are not touched, and nothing rendered changes.'))
            ->action(function (): void {
                $locale = Locale::query()->where('code', $this->locale)->first();

                if (! $locale instanceof Locale) {
                    return;
                }

                $seeded = LocaleResource::seed($locale, LocaleResource::SEED_CATALOGUE);

                Notification::make()
                    ->title(__(':count keys added.', ['count' => number_format($seeded['keys'])]))
                    ->success()
                    ->send();
            });
    }

    /* ------------------------------------------------------------------ *
     * The matrix
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $this->locale = $this->resolveLocale($this->locale);
        $catalogue = app(TranslationCatalogue::class);
        $catalogues = $catalogue->scan()->catalogues();

        if (! array_key_exists($this->catalogue, $catalogues)) {
            $this->catalogue = self::JSON;
        }

        $result = $this->rows();

        // Seeded here rather than in a lifecycle hook because this is the one place that knows
        // which lines are on screen. `??=` is what keeps a half-written sentence alive across a
        // re-render caused by something else on the page.
        foreach ($result['rows'] as $row) {
            $this->values[$row['hash']] ??= $row['value'];
            $this->reviewed[$row['hash']] ??= $row['reviewed'];
        }

        return [
            'locales' => Locale::query()->orderBy('position')->orderBy('name')->get(),
            'catalogues' => array_keys($catalogues),
            'catalogueLabels' => $this->catalogueLabels(array_keys($catalogues)),
            'filters' => $this->filterLabels(),
            'rows' => $result['rows'],
            'counts' => $result['counts'],
            'pages' => $result['pages'],
            'matched' => $result['matched'],
            'perPage' => self::PER_PAGE,
            'progress' => TranslateLocaleJob::progress($this->locale),
            'aiRefusal' => TranslateLocaleJob::refusal(),
            'source' => $this->sourceLocale(),
            'direction' => $this->direction(),
            'import' => $this->importSummary,
        ];
    }

    /**
     * Every line in the chosen catalogue, filtered, with the visible page materialised.
     *
     * The counts are computed over the whole catalogue rather than the page, because "412
     * untranslated" is the number a translator is working against and "3 untranslated on this
     * page" is not a fact about anything.
     *
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>, pages: int, matched: int}
     */
    private function rows(): array
    {
        $catalogue = app(TranslationCatalogue::class);
        $catalogues = $catalogue->scan()->catalogues();
        $name = array_key_exists($this->catalogue, $catalogues) ? $this->catalogue : self::JSON;
        $group = $name === self::JSON ? null : $name;

        $source = $this->sourceLocale();
        $english = $catalogue->linesFor($source, $name);
        $target = $catalogue->linesFor($this->locale, $name);

        $stored = $this->meta($this->locale, $group);
        $reference = $this->locale === $source ? [] : $this->meta($source, $group);

        $needle = mb_strtolower(trim($this->search));

        $rows = [];
        $counts = ['total' => 0, 'untranslated' => 0, 'unreviewed' => 0, 'stale' => 0, 'orphans' => 0];
        $seen = [];

        foreach ($catalogues[$name] as $key) {
            $hash = TranslationRepository::hash($group, $key);
            $seen[$hash] = true;

            $referenceText = $this->line($name, $english, $key) ?? ($name === self::JSON ? $key : '');
            $value = $this->line($name, $target, $key) ?? '';
            $row = $stored[$hash] ?? null;

            $isReviewed = $row !== null && $row['reviewed'];
            $isTranslated = $value !== '';
            $isStale = $isTranslated
                && $row !== null
                && isset($reference[$hash])
                && $reference[$hash]['updated_at'] !== null
                && $row['updated_at'] !== null
                && $reference[$hash]['updated_at'] > $row['updated_at'];

            $counts['total']++;

            if (! $isTranslated) {
                $counts['untranslated']++;
            }

            if ($isTranslated && ! $isReviewed) {
                $counts['unreviewed']++;
            }

            if ($isStale) {
                $counts['stale']++;
            }

            $matches = match ($this->filter) {
                self::FILTER_UNTRANSLATED => ! $isTranslated,
                self::FILTER_UNREVIEWED => $isTranslated && ! $isReviewed,
                self::FILTER_STALE => $isStale,
                default => true,
            };

            if ($matches && $needle !== '') {
                $matches = str_contains(mb_strtolower($key), $needle)
                    || str_contains(mb_strtolower($referenceText), $needle)
                    || str_contains(mb_strtolower($value), $needle);
            }

            if (! $matches) {
                continue;
            }

            $rows[] = [
                'hash' => $hash,
                'key' => $key,
                'reference' => $referenceText,
                'value' => $value,
                'reviewed' => $isReviewed,
                'translated' => $isTranslated,
                'stale' => $isStale,
                'placeholders' => Placeholders::in($referenceText),
            ];
        }

        // Rows this installation stores for keys the source no longer contains. Not a fault —
        // rewording an English sentence makes a new key, and the old translation is somebody's
        // work — but worth a number on screen before they wonder where it went.
        $counts['orphans'] = count(array_diff_key($stored, $seen));

        $matched = count($rows);
        $pages = max(1, (int) ceil($matched / self::PER_PAGE));
        $this->page = min(max(1, $this->page), $pages);

        return [
            'rows' => array_slice($rows, ($this->page - 1) * self::PER_PAGE, self::PER_PAGE),
            'counts' => $counts,
            'pages' => $pages,
            'matched' => $matched,
        ];
    }

    /**
     * The review state and write time of every stored row in one catalogue, keyed by hash.
     *
     * Neither `key` nor `value` is selected: they are the two long columns, the effective text
     * already came from the loader, and this query would otherwise pull a megabyte of sentences
     * across on every keystroke in the search box.
     *
     * @return array<string, array{reviewed: bool, translated: bool, updated_at: string|null}>
     */
    private function meta(string $locale, ?string $group): array
    {
        $rows = DB::table('translations')
            ->where('locale', $locale)
            ->when($group === null,
                static fn ($query) => $query->whereNull('group'),
                static fn ($query) => $query->where('group', $group),
            )
            ->get(['key_hash', 'is_reviewed', 'updated_at', DB::raw('(case when value is null then 0 else 1 end) as has_value')]);

        $meta = [];

        foreach ($rows as $row) {
            $meta[(string) $row->key_hash] = [
                'reviewed' => (bool) $row->is_reviewed,
                'translated' => (bool) $row->has_value,
                'updated_at' => $row->updated_at === null ? null : (string) $row->updated_at,
            ];
        }

        return $meta;
    }

    /* ------------------------------------------------------------------ *
     * Values
     * ------------------------------------------------------------------ */

    /**
     * One line out of a loaded catalogue. The JSON namespace is keyed by whole sentences, so
     * `Arr::get` would read a key as a path and find nothing; a group key is exactly a path.
     *
     * @param array<string, mixed> $lines
     */
    private function line(string $catalogue, array $lines, string $key): ?string
    {
        $value = $catalogue === self::JSON ? ($lines[$key] ?? null) : Arr::get($lines, $key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function group(): ?string
    {
        return $this->catalogue === self::JSON ? null : $this->catalogue;
    }

    private function sourceLocale(): string
    {
        return (string) config('app.fallback_locale', 'en');
    }

    /**
     * A code this installation actually offers, preferring the one asked for.
     */
    private function resolveLocale(?string $requested): string
    {
        $codes = Locale::query()->orderBy('position')->orderBy('name')->pluck('code')->all();

        if ($codes === []) {
            return $this->sourceLocale();
        }

        if (is_string($requested) && in_array($requested, $codes, true)) {
            return $requested;
        }

        $source = $this->sourceLocale();

        // A translation screen opening on the language the product is written in would show a
        // column of English beside itself, so anything else is preferred.
        foreach ($codes as $code) {
            if ($code !== $source) {
                return (string) $code;
            }
        }

        return (string) $codes[0];
    }

    private function localeLabel(): string
    {
        $locale = Locale::query()->where('code', $this->locale)->first();

        return $locale instanceof Locale ? $locale->label() : $this->locale;
    }

    private function direction(): string
    {
        $locale = Locale::query()->where('code', $this->locale)->first();

        return $locale instanceof Locale && $locale->isRtl() ? Locale::RTL : Locale::LTR;
    }

    /**
     * @param list<string> $catalogues
     * @return array<string, string>
     */
    private function catalogueLabels(array $catalogues): array
    {
        $labels = [];

        foreach ($catalogues as $name) {
            $labels[$name] = $name === self::JSON
                ? __('Literal strings')
                : $name;
        }

        return $labels;
    }

    /**
     * @return array<string, string>
     */
    private function filterLabels(): array
    {
        return [
            self::FILTER_ALL => __('Everything'),
            self::FILTER_UNTRANSLATED => __('Untranslated'),
            self::FILTER_UNREVIEWED => __('Needs review'),
            self::FILTER_STALE => __('English changed since'),
        ];
    }
}

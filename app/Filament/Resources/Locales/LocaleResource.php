<?php

declare(strict_types=1);

namespace App\Filament\Resources\Locales;

use App\Filament\Pages\Translations;
use App\Filament\Resources\Locales\Pages\CreateLocale;
use App\Filament\Resources\Locales\Pages\EditLocale;
use App\Filament\Resources\Locales\Pages\ListLocales;
use App\Filament\Support\AdminAudit;
use App\Filament\Support\NavigationGroup;
use App\Filament\Support\PlatformResource;
use App\Http\Middleware\SetLocale;
use App\Models\Locale;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Translation\TranslationCatalogue;
use App\Services\Translation\TranslationImporter;
use App\Services\Translation\TranslationRepository;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * The languages this installation offers.
 *
 * # This table is an allow-list, not a preference
 *
 * `users.locale` and `workspaces.locale` are ordinary columns and nothing stops either holding
 * `de` long after German was removed. {@see SetLocale} therefore honours a stored code only
 * when a row here carries it and is enabled, which is what makes switching a language off take
 * effect on the next request rather than after somebody clears every user row.
 *
 * # Two guard rails, and why each one exists
 *
 * **The default may not be switched off or deleted.** Locale resolution ends at the default
 * row; without one, an installation whose users all named a language that was later removed
 * falls through to `config('app.locale')`, which is whatever the installer happened to write.
 * Promotion is therefore how the default changes: making another language the default clears
 * the flag everywhere else in the same operation, so there is never a moment with none.
 *
 * **Disabling a language moves the people on it.** The middleware already degrades — a code
 * this installation no longer offers is skipped, and the person sees the default — so nothing
 * breaks if the columns are left alone. What breaks is later: re-enabling a half-finished
 * language would silently throw every one of those accounts back into it, without anybody
 * choosing that. So {@see fallBack()} rewrites the columns at the moment the language is
 * switched off, and the confirmation says how many rows that is before it happens.
 *
 * # The numbers on the list
 *
 * Completion is measured against {@see TranslationCatalogue}, which reads the source rather
 * than the `translations` table: a language shipping `lang/xx/*.php` files is complete whether
 * or not anybody has stored a row for it. That means a scan of `app/` and `resources/`, which
 * is far too expensive to repeat for every sort and page change, so the whole set is computed
 * once and cached — keyed on the translation version stamp and on the locales themselves, so
 * any edit to either invalidates it rather than leaving a stale bar on screen.
 *
 * @extends PlatformResource<Locale>
 */
final class LocaleResource extends PlatformResource
{
    protected static ?string $model = Locale::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Platform;

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * BCP-47, narrowed to what a language picker can actually mean: a language subtag, an
     * optional script, an optional region. `en`, `pt-BR`, `sr-Latn-RS`. Extensions and private
     * use are not accepted — the column is twelve characters and nothing in Planvio varies by
     * a calendar or a collation.
     */
    private const CODE_PATTERN = '/^[A-Za-z]{2,3}(-[A-Za-z]{4})?(-([A-Za-z]{2}|[0-9]{3}))?$/';

    /** Seeding a new language reads the source, so the answer is worth holding on to. */
    private const STATISTICS_TTL = 300;

    public static function getNavigationLabel(): string
    {
        return __('Languages');
    }

    public static function getModelLabel(): string
    {
        return __('language');
    }

    public static function getPluralModelLabel(): string
    {
        return __('languages');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Language'))
                ->columns(2)
                ->schema([
                    TextInput::make('code')
                        ->label(__('Code'))
                        ->required()
                        ->maxLength(12)
                        ->unique(ignoreRecord: true)
                        ->rule('regex:'.self::CODE_PATTERN)
                        ->validationMessages([
                            'regex' => __('Use a BCP-47 tag: a language, optionally a script, optionally a region. For example en, ar, pt-BR or sr-Latn-RS.'),
                        ])
                        ->dehydrateStateUsing(static fn (?string $state): string => self::canonical((string) $state))
                        // The code is the identity of every translation row and of every
                        // `users.locale` value. Changing it would orphan all of them at once,
                        // silently, with nothing to migrate from.
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->dehydrated(fn (string $operation): bool => $operation === 'create')
                        ->helperText(__('BCP-47, e.g. en, ar, pt-BR. This is what users.locale and every stored translation is keyed by, so it cannot be changed afterwards.')),
                    Select::make('direction')
                        ->label(__('Direction'))
                        ->options(self::directionOptions())
                        ->default(Locale::LTR)
                        ->required()
                        ->helperText(__('Stored rather than guessed from the code. The layout asks for this on every request.')),
                    TextInput::make('name')
                        ->label(__('Name in English'))
                        ->required()
                        ->maxLength(191)
                        ->placeholder(__('Portuguese (Brazil)')),
                    TextInput::make('native_name')
                        ->label(__('Name in the language itself'))
                        ->required()
                        ->maxLength(191)
                        ->placeholder(__('Português (Brasil)'))
                        ->helperText(__('What the language picker shows to somebody who reads this language and no other.')),
                ]),

            Section::make(__('Availability'))
                ->columns(2)
                ->schema([
                    Toggle::make('is_enabled')
                        ->label(__('Offered to users'))
                        ->default(true)
                        ->disabled(fn (?Locale $record): bool => $record?->is_default === true)
                        // Belt and braces on top of the disabled input: the default row must
                        // come out of this form enabled whatever arrives in the payload.
                        ->dehydrateStateUsing(static fn (mixed $state, ?Locale $record): bool => $record?->is_default === true || (bool) $state)
                        ->helperText(fn (?Locale $record): string => $record?->is_default === true
                            ? __('The default language is always offered. Promote another language first if you want to retire this one.')
                            : __('A language nobody is offered still keeps its translations. Switching it off moves anyone currently on it to the default.')),
                    Toggle::make('is_default')
                        ->label(__('Installation default'))
                        ->disabled(fn (?Locale $record): bool => $record?->is_default === true)
                        ->helperText(fn (?Locale $record): string => $record?->is_default === true
                            ? __('This is the default. To change it, promote another language from the list — the flag moves in one operation, so there is never a moment with no default.')
                            : __('Used by anybody whose account and workspace name no language. Setting this clears the flag everywhere else.')),
                    TextInput::make('position')
                        ->label(__('Position'))
                        ->numeric()
                        ->default(0)
                        ->required()
                        ->helperText(__('Order in the language picker. Ties are broken by name.')),
                    Select::make('seed_from')
                        ->label(__('Start the translator off with'))
                        ->options(fn (): array => self::seedOptions())
                        ->default(self::SEED_CATALOGUE)
                        ->native(false)
                        ->visible(fn (string $operation): bool => $operation === 'create')
                        ->helperText(__('A new language has no rows, so the translation screen would open empty. Seeding writes one untranslated row per key, which is the list a translator works down — and, from another language, copies its wording in as a starting point.')),
                ]),

            Section::make(__('Formatting'))
                ->description(__('Both are optional. Left blank, the date format and week start the workspace itself chose apply, which is right for every language that shares them.'))
                ->columns(2)
                ->collapsed()
                ->schema([
                    TextInput::make('date_format')
                        ->label(__('Date format'))
                        ->maxLength(32)
                        ->placeholder(__('d/m/Y'))
                        ->helperText(__('A PHP date() format string.')),
                    Select::make('first_day_of_week')
                        ->label(__('First day of the week'))
                        ->options(self::weekdayOptions())
                        ->placeholder(__('Inherit from the workspace')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $statistics = self::statistics();

        return $table
            ->defaultSort('position')
            ->columns([
                TextColumn::make('code')
                    ->label(__('Code'))
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('native_name')
                    ->label(__('Native name'))
                    ->searchable(),
                TextColumn::make('direction')
                    ->label(__('Direction'))
                    ->badge()
                    ->color(fn (?string $state): string => $state === Locale::RTL ? 'warning' : 'gray')
                    ->formatStateUsing(fn (?string $state): string => self::directionOptions()[$state] ?? (string) $state),
                ViewColumn::make('completion')
                    ->label(__('Translated'))
                    ->view('filament.tables.columns.locale-completion')
                    ->viewData(fn (Locale $record): array => self::statisticsFor($record, $statistics)),
                TextColumn::make('rows')
                    ->label(__('Stored lines'))
                    ->state(function (Locale $record) use ($statistics): string {
                        $figures = self::statisticsFor($record, $statistics);

                        return __(':rows stored · :reviewed reviewed', [
                            'rows' => number_format($figures['rows']),
                            'reviewed' => number_format($figures['reviewed']),
                        ]);
                    })
                    ->color('gray'),
                IconColumn::make('is_enabled')
                    ->label(__('Offered'))
                    ->boolean(),
                IconColumn::make('is_default')
                    ->label(__('Default'))
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedCheckCircle)
                    ->falseIcon(Heroicon::OutlinedXCircle)
                    ->falseColor('gray'),
            ])
            ->filters([
                TernaryFilter::make('is_enabled')
                    ->label(__('Offered to users')),
                TernaryFilter::make('direction')
                    ->label(__('Right to left'))
                    ->queries(
                        true: fn ($query) => $query->where('direction', Locale::RTL),
                        false: fn ($query) => $query->where('direction', '!=', Locale::RTL),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->recordActions([
                self::translateAction(),
                ActionGroup::make([
                    EditAction::make(),
                    self::promoteAction(),
                    self::availabilityAction(),
                    self::syncAction(),
                    self::deleteAction(),
                ]),
            ])
            ->toolbarActions([])
            ->emptyStateIcon(Heroicon::OutlinedLanguage)
            ->emptyStateHeading(__('No languages'))
            ->emptyStateDescription(__('Planvio renders in English with no rows here at all. Add a language to offer a second one.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLocales::route('/'),
            'create' => CreateLocale::route('/create'),
            'edit' => EditLocale::route('/{record}/edit'),
        ];
    }

    /* ------------------------------------------------------------------ *
     * Actions
     * ------------------------------------------------------------------ */

    /**
     * Straight to the editor, with the language already chosen.
     */
    private static function translateAction(): Action
    {
        return Action::make('translate')
            ->label(__('Translate'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->url(fn (Locale $record): string => Translations::getUrl(['locale' => $record->code]));
    }

    /**
     * Move the default flag, in one operation.
     *
     * Clearing the old flag and setting the new one as two separate saves leaves a window in
     * which the installation has no default at all, and `Locale::fallback()` resolves by
     * `orderByDesc('is_default')` — so during that window the answer is whichever row sorts
     * first, which is an accident of insertion order.
     */
    private static function promoteAction(): Action
    {
        return Action::make('promote')
            ->label(__('Make default'))
            ->icon(Heroicon::OutlinedStar)
            ->color('gray')
            ->visible(fn (Locale $record): bool => ! $record->is_default)
            ->requiresConfirmation()
            ->modalHeading(fn (Locale $record): string => __('Make :name the default?', ['name' => $record->name]))
            ->modalDescription(__('Anybody whose account and workspace name no language will see this one. It is also what a disabled language falls back to. Making it the default switches it on if it is off.'))
            ->action(function (Locale $record): void {
                self::promote($record);

                AdminAudit::record(
                    'admin.locale_promoted',
                    __('Default language changed from the administration panel.'),
                    properties: ['locale' => (string) $record->code],
                );

                Notification::make()
                    ->title(__(':name is now the default language.', ['name' => $record->name]))
                    ->success()
                    ->send();
            });
    }

    /**
     * Switch a language on, or off and move everyone who was on it.
     */
    private static function availabilityAction(): Action
    {
        return Action::make('availability')
            ->label(fn (Locale $record): string => $record->is_enabled ? __('Stop offering') : __('Offer to users'))
            ->icon(fn (Locale $record): string|BackedEnum => $record->is_enabled ? Heroicon::OutlinedEyeSlash : Heroicon::OutlinedEye)
            ->color(fn (Locale $record): string => $record->is_enabled ? 'danger' : 'success')
            // The default is the destination of every fallback; switching it off would leave
            // resolution with nowhere to land.
            ->visible(fn (Locale $record): bool => ! $record->is_default)
            ->requiresConfirmation()
            ->modalHeading(fn (Locale $record): string => $record->is_enabled
                ? __('Stop offering :name?', ['name' => $record->name])
                : __('Offer :name to users?', ['name' => $record->name]))
            ->modalDescription(fn (Locale $record): string => $record->is_enabled
                ? __(':users accounts and :workspaces workspaces are set to this language and will be moved to the default. Nothing translated is deleted, and switching it back on later is one click — but the accounts moved now will stay on the default until each person chooses again.', [
                    'users' => number_format(self::usersOn($record)),
                    'workspaces' => number_format(self::workspacesOn($record)),
                ])
                : __('It appears in every language picker from the next request. Anything still untranslated renders in English.'))
            ->action(function (Locale $record): void {
                $enabling = ! $record->is_enabled;

                $record->forceFill(['is_enabled' => $enabling])->save();

                $moved = $enabling ? ['users' => 0, 'workspaces' => 0] : self::fallBack($record);

                AdminAudit::record(
                    $enabling ? 'admin.locale_enabled' : 'admin.locale_disabled',
                    $enabling
                        ? __('Language switched on from the administration panel.')
                        : __('Language switched off from the administration panel.'),
                    properties: ['locale' => (string) $record->code] + $moved,
                );

                Notification::make()
                    ->title($enabling
                        ? __(':name is now offered.', ['name' => $record->name])
                        : __(':name is no longer offered.', ['name' => $record->name]))
                    ->body($enabling
                        ? null
                        : __(':users accounts and :workspaces workspaces were moved to the default language.', [
                            'users' => number_format($moved['users']),
                            'workspaces' => number_format($moved['workspaces']),
                        ]))
                    ->success()
                    ->send();
            });
    }

    /**
     * Give the language a row for every key the product uses — `lang:sync` for one locale.
     */
    private static function syncAction(): Action
    {
        return Action::make('sync')
            ->label(__('Sync catalogue'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(fn (Locale $record): string => __('Sync the catalogue into :name?', ['name' => $record->name]))
            ->modalDescription(__('Every key the product uses gets a row here, untranslated. Existing rows are not touched and nothing rendered changes: an untranslated row is exactly what the loader ignores.'))
            ->action(function (Locale $record): void {
                $added = self::seed($record, self::SEED_CATALOGUE);

                Notification::make()
                    ->title(__(':count keys added to :name.', ['count' => number_format($added['keys']), 'name' => $record->name]))
                    ->success()
                    ->send();
            });
    }

    private static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            // Deleting the default would leave resolution with nowhere to land.
            ->visible(fn (Locale $record): bool => ! $record->is_default)
            ->modalDescription(fn (Locale $record): string => __('The :count translated lines stored for this language are deleted with it, and cannot be recovered. Export them first if there is any chance you want them back. Accounts and workspaces set to it are moved to the default.', [
                'count' => number_format(self::statisticsFor($record, self::statistics())['translated']),
            ]))
            ->before(function (Locale $record): void {
                self::fallBack($record);
                self::purgeTranslations($record);

                AdminAudit::record(
                    'admin.locale_deleted',
                    __('Language removed from the administration panel.'),
                    properties: ['locale' => (string) $record->code],
                );
            });
    }

    /* ------------------------------------------------------------------ *
     * Invariants
     * ------------------------------------------------------------------ */

    /**
     * Make $locale the default, enabling it, and clear the flag everywhere else.
     */
    public static function promote(Locale $locale): void
    {
        DB::transaction(static function () use ($locale): void {
            Locale::query()
                ->whereKeyNot($locale->getKey())
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $locale->forceFill(['is_default' => true, 'is_enabled' => true])->save();
        });
    }

    /**
     * Exactly one row carries the default flag, and it is enabled.
     *
     * Called after every create and every save: a form can set the flag, and two flagged rows
     * would make `Locale::fallback()` resolve by insertion order.
     */
    public static function enforceSingleDefault(Locale $locale): void
    {
        if (! $locale->is_default) {
            return;
        }

        self::promote($locale);
    }

    /**
     * Delete every stored line for a language that is going away.
     *
     * Rows keyed by a code no longer in `locales` are unreachable: nothing loads them, nothing
     * lists them, and `lang:sync` will not touch them because it only walks enabled locales.
     * Leaving them would be a slow leak in the one table that grows by thousands of rows per
     * language, so they go with the row — which is exactly why the confirmation says how many
     * lines that is and suggests exporting first.
     */
    public static function purgeTranslations(Locale $locale): void
    {
        DB::table('translations')->where('locale', $locale->code)->delete();

        app(TranslationRepository::class)->flush();

        self::forgetStatistics();
    }

    /**
     * Move everybody who named $locale onto the default.
     *
     * Soft-deleted rows are included deliberately: a restored account must not come back
     * pointing at a language the installation no longer offers.
     *
     * @return array{users: int, workspaces: int}
     */
    public static function fallBack(Locale $locale): array
    {
        $fallback = Locale::query()
            ->where('is_default', true)
            ->whereKeyNot($locale->getKey())
            ->value('code')
            ?? (string) config('app.fallback_locale', 'en');

        return [
            'users' => User::query()->withTrashed()->where('locale', $locale->code)->update(['locale' => $fallback]),
            'workspaces' => Workspace::query()->withTrashed()->where('locale', $locale->code)->update(['locale' => $fallback]),
        ];
    }

    /* ------------------------------------------------------------------ *
     * Seeding
     * ------------------------------------------------------------------ */

    /** Every key the product uses, as an untranslated row. */
    public const SEED_CATALOGUE = '__catalogue';

    /** Nothing at all: the screen opens empty until somebody syncs. */
    public const SEED_NONE = '__none';

    /**
     * Give a new language something to open with.
     *
     * Always the catalogue, because a translation screen listing nothing is a screen nobody can
     * use. Optionally another language's wording on top of it — which is what makes `pt-BR` a
     * morning's work rather than a week's, and why the copied lines arrive unreviewed: they are
     * a starting point somebody still has to read.
     *
     * @return array{keys: int, copied: int}
     */
    public static function seed(Locale $locale, ?string $from): array
    {
        if ($from === self::SEED_NONE || $from === null || $from === '') {
            return ['keys' => 0, 'copied' => 0];
        }

        $catalogue = app(TranslationCatalogue::class);
        $translations = app(TranslationRepository::class);
        $code = (string) $locale->code;

        $keys = 0;

        foreach ($catalogue->scan()->catalogues() as $name => $items) {
            $keys += $translations->addMissing(
                $code,
                $name === TranslationImporter::JSON_CATALOGUE ? null : $name,
                $items,
            );
        }

        if ($from === self::SEED_CATALOGUE) {
            return ['keys' => $keys, 'copied' => 0];
        }

        $copied = 0;

        foreach ($translations->all($from) as $name => $lines) {
            $values = array_filter($lines, static fn (?string $value): bool => $value !== null && trim($value) !== '');

            if ($values === []) {
                continue;
            }

            $translations->putMany(
                $code,
                $name === TranslationImporter::JSON_CATALOGUE ? null : $name,
                $values,
                self::administrator()?->getKey(),
                // Copied wording is a draft in the new language, not an approval of it.
                reviewed: false,
            );

            $copied += count($values);
        }

        return ['keys' => $keys, 'copied' => $copied];
    }

    /**
     * @return array<string, string>
     */
    private static function seedOptions(): array
    {
        $options = [
            self::SEED_CATALOGUE => __('Every key, untranslated — the full list to work down'),
        ];

        foreach (Locale::query()->orderBy('position')->orderBy('name')->get() as $locale) {
            $options[(string) $locale->code] = __('Every key, plus the wording already stored for :name', [
                'name' => $locale->label(),
            ]);
        }

        $options[self::SEED_NONE] = __('Nothing — I will import a file');

        return $options;
    }

    /* ------------------------------------------------------------------ *
     * Numbers
     * ------------------------------------------------------------------ */

    /**
     * Completion and row counts for every language, computed once.
     *
     * The cache key carries the translation version stamp and a fingerprint of the `locales`
     * table, so writing a translation, adding a language or renaming one all invalidate it.
     * Without that the bar would be five minutes stale exactly when somebody had just finished
     * a batch and gone to look at it.
     *
     * @return array<string, array{total: int, translated: int, percent: float, rows: int, reviewed: int}>
     */
    public static function statistics(bool $fresh = false): array
    {
        $key = self::statisticsKey();

        if ($fresh) {
            self::forgetStatistics();
        }

        try {
            $cached = Cache::store('database')->get($key);

            if (is_array($cached) && ! $fresh) {
                /** @var array<string, array{total: int, translated: int, percent: float, rows: int, reviewed: int}> $cached */
                return $cached;
            }
        } catch (QueryException) {
            return self::computeStatistics();
        }

        $statistics = self::computeStatistics();

        try {
            Cache::store('database')->put($key, $statistics, self::STATISTICS_TTL);
        } catch (QueryException) {
            // No cache table; the figures are still correct, they are just recomputed.
        }

        return $statistics;
    }

    public static function forgetStatistics(): void
    {
        try {
            Cache::store('database')->forget(self::statisticsKey());
        } catch (QueryException) {
            // Nothing cached to forget.
        }
    }

    /**
     * @param array<string, array<string, mixed>> $statistics
     * @return array{total: int, translated: int, percent: float, rows: int, reviewed: int}
     */
    public static function statisticsFor(Locale $locale, array $statistics): array
    {
        /** @var array{total: int, translated: int, percent: float, rows: int, reviewed: int} $figures */
        $figures = $statistics[(string) $locale->code] ?? [
            'total' => 0,
            'translated' => 0,
            'percent' => 0.0,
            'rows' => 0,
            'reviewed' => 0,
        ];

        return $figures;
    }

    /**
     * @return array<string, array{total: int, translated: int, percent: float, rows: int, reviewed: int}>
     */
    private static function computeStatistics(): array
    {
        $catalogue = app(TranslationCatalogue::class);
        $total = $catalogue->scan()->total();

        $rows = [];

        foreach (DB::table('translations')->select('locale')
            ->selectRaw('count(*) as rows_total')
            ->selectRaw('sum(case when value is null then 0 else 1 end) as translated')
            ->selectRaw('sum(case when is_reviewed = 1 and value is not null then 1 else 0 end) as reviewed')
            ->groupBy('locale')
            ->get() as $row) {
            $rows[(string) $row->locale] = [
                'rows' => (int) $row->rows_total,
                'translated' => (int) $row->translated,
                'reviewed' => (int) $row->reviewed,
            ];
        }

        $statistics = [];

        foreach (Locale::query()->get() as $locale) {
            $code = (string) $locale->code;
            $missing = array_sum(array_map('count', $catalogue->missing($code)));
            $translated = max(0, $total - $missing);
            $stored = $rows[$code] ?? ['rows' => 0, 'translated' => 0, 'reviewed' => 0];

            $statistics[$code] = [
                'total' => $total,
                'translated' => $translated,
                'percent' => $total === 0 ? 100.0 : round(($translated / $total) * 100, 1),
                'rows' => $stored['rows'],
                'reviewed' => $stored['reviewed'],
            ];
        }

        return $statistics;
    }

    private static function statisticsKey(): string
    {
        $stamp = app(TranslationRepository::class)->version()
            .'|'.Locale::query()->count()
            .'|'.(string) Locale::query()->max('updated_at');

        return 'planvio:locales:statistics:'.sha1($stamp);
    }

    private static function usersOn(Locale $locale): int
    {
        return User::query()->withTrashed()->where('locale', $locale->code)->count();
    }

    private static function workspacesOn(Locale $locale): int
    {
        return Workspace::query()->withTrashed()->where('locale', $locale->code)->count();
    }

    /* ------------------------------------------------------------------ *
     * Values
     * ------------------------------------------------------------------ */

    /**
     * `pt-br` and `PT-BR` are the same language as `pt-BR`; the column is unique, so they must
     * not be able to become three rows.
     */
    public static function canonical(string $code): string
    {
        $parts = array_values(array_filter(explode('-', trim($code)), static fn (string $part): bool => $part !== ''));

        foreach ($parts as $index => $part) {
            $parts[$index] = match (true) {
                $index === 0 => mb_strtolower($part),
                strlen($part) === 4 => ucfirst(mb_strtolower($part)),
                default => mb_strtoupper($part),
            };
        }

        return implode('-', $parts);
    }

    /**
     * @return array<string, string>
     */
    private static function directionOptions(): array
    {
        return [
            Locale::LTR => __('Left to right'),
            Locale::RTL => __('Right to left'),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function weekdayOptions(): array
    {
        return [
            0 => __('Sunday'),
            1 => __('Monday'),
            6 => __('Saturday'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\Settings;

use App\Filament\Resources\Settings\Pages\CreateSetting;
use App\Filament\Resources\Settings\Pages\EditSetting;
use App\Filament\Resources\Settings\Pages\ListSettings;
use App\Filament\Support\AdminAudit;
use App\Filament\Support\NavigationGroup;
use App\Filament\Support\PlatformResource;
use App\Models\Setting;
use App\Support\Settings;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use JsonException;
use UnitEnum;

/**
 * The platform key/value store, edited directly.
 *
 * # Why every write goes through App\Support\Settings
 *
 * Two caches sit in front of this table: a request memo and the `database` cache store, which
 * has no tags and so uses a version segment in its keys. Writing a row through Eloquent would
 * update the database and leave both caches holding the old value for up to an hour — the
 * setting would appear changed on this screen and be ignored by the application. So the create,
 * update and delete paths on the pages call `Settings::set()` and `Settings::forget()`, which
 * write the row *and* invalidate what depends on it.
 *
 * # Encrypted values are never decrypted for display
 *
 * A row marked `is_encrypted` holds ciphertext, and this screen keeps it that way (CLAUDE.md
 * rule 4). The editor renders empty for such a row, an empty submission leaves the stored value
 * alone, and typing a new one replaces it. There is no path here that puts an SMTP password or
 * a token in front of a browser, and none that writes one to the audit trail.
 *
 * # This is a service hatch, not the settings UI
 *
 * Ordinary configuration belongs to the screens built for it. What lands here is the row nobody
 * anticipated: a value written by an installer that has to be corrected, a flag being flipped
 * for support. The list therefore says which keys the system writes for itself, because editing
 * one of those by hand is nearly always a mistake.
 *
 * @extends PlatformResource<Setting>
 */
final class SettingResource extends PlatformResource
{
    protected static ?string $model = Setting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Platform;

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'key';

    /**
     * Keys the application maintains for itself. Editing one by hand is almost never right, so
     * the screen says so rather than quietly allowing it.
     *
     * @var list<string>
     */
    private const MANAGED_PREFIXES = ['system.scheduler', 'db_version', 'app_version'];

    public static function getNavigationLabel(): string
    {
        return __('Settings');
    }

    public static function getModelLabel(): string
    {
        return __('setting');
    }

    public static function getPluralModelLabel(): string
    {
        return __('settings');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Setting'))
                ->schema([
                    TextInput::make('key')
                        ->label(__('Key'))
                        ->required()
                        ->maxLength(191)
                        ->unique(ignoreRecord: true)
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->dehydrated(fn (string $operation): bool => $operation === 'create')
                        ->helperText(__('Dotted, e.g. mail.from_address. The key is the identity of the row and cannot be changed afterwards.')),
                    Placeholder::make('managed_warning')
                        ->label(__('Careful'))
                        ->content(__('This key is written by Planvio itself. Editing it by hand will be overwritten, and may make System Health report something that is not true.'))
                        ->visible(fn (?Setting $record): bool => $record !== null && self::isManaged((string) $record->key)),
                    Toggle::make('is_encrypted')
                        ->label(__('Encrypted at rest'))
                        ->helperText(__('Stored as ciphertext under the application key. Rotating APP_KEY makes existing encrypted values unreadable.')),
                    Placeholder::make('encrypted_notice')
                        ->label(__('Stored value'))
                        ->content(__('Encrypted. Planvio does not decrypt it for display. Type a new value below to replace it, or leave the editor empty to keep what is stored.'))
                        ->visible(fn (?Setting $record): bool => $record !== null && (bool) $record->is_encrypted),
                    CodeEditor::make('value')
                        ->label(__('Value'))
                        ->language(Language::Json)
                        ->helperText(__('JSON: a string in double quotes, a number, true, false, null, or an object.'))
                        ->formatStateUsing(self::encodeValue(...))
                        ->rule(self::jsonRule())
                        ->required(fn (?Setting $record): bool => $record === null || ! $record->is_encrypted),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('key')
            ->columns([
                TextColumn::make('key')
                    ->label(__('Key'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Setting $record): ?string => self::isManaged((string) $record->key)
                        ? __('Written by Planvio')
                        : null),
                TextColumn::make('value')
                    ->label(__('Value'))
                    // Ciphertext is never rendered, and neither is a long payload: the list is
                    // for finding a row, and the row is where it is read.
                    ->state(self::preview(...))
                    ->wrap()
                    ->color(fn (Setting $record): string => $record->is_encrypted ? 'gray' : 'primary'),
                IconColumn::make('is_encrypted')
                    ->label(__('Encrypted'))
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedLockClosed)
                    ->falseIcon(Heroicon::OutlinedXCircle)
                    ->falseColor('gray'),
                TextColumn::make('updated_at')
                    ->label(__('Updated'))
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_encrypted')
                    ->label(__('Encrypted')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription(fn (Setting $record): string => self::isManaged((string) $record->key)
                        ? __('Planvio writes this key itself. Removing it will make System Health and the upgrade screen read the installation wrongly until something writes it again.')
                        : __('The application falls back to the default compiled into config/ for this key. If it has no default, whatever reads it gets null.')),
            ])
            ->toolbarActions([])
            ->emptyStateIcon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->emptyStateHeading(__('No settings stored'))
            ->emptyStateDescription(__('Everything is running on the defaults in config/.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSettings::route('/'),
            'create' => CreateSetting::route('/create'),
            'edit' => EditSetting::route('/{record}/edit'),
        ];
    }

    /* ------------------------------------------------------------------ *
     * Cache
     * ------------------------------------------------------------------ */

    /**
     * The escape hatch for a cache that has drifted — after a manual database edit, or a
     * restore. It empties nothing but the settings caches.
     */
    public static function flushCacheAction(): Action
    {
        return Action::make('flushSettingsCache')
            ->label(__('Flush settings cache'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('Flush the settings cache?'))
            ->modalDescription(__('Every setting is read from the database again on the next request. Nothing stored changes. Use this if a value was edited directly in the database.'))
            ->action(function (): void {
                app(Settings::class)->flush();

                AdminAudit::record(
                    'admin.settings_cache_flushed',
                    __('Settings cache flushed from the administration panel.'),
                );

                Notification::make()
                    ->title(__('Settings cache flushed.'))
                    ->success()
                    ->send();
            });
    }

    /* ------------------------------------------------------------------ *
     * Value conversion
     * ------------------------------------------------------------------ */

    /**
     * What the editor is filled with. An encrypted row yields an empty editor: the ciphertext
     * would be noise, and the plaintext must not be shown.
     */
    public static function encodeValue(mixed $state, ?Setting $record): string
    {
        if ($record !== null && $record->is_encrypted) {
            return '';
        }

        if ($state === null) {
            return 'null';
        }

        return (string) json_encode(
            $state,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * The value as PHP, ready for `Settings::set()`.
     *
     * @throws JsonException when the text is not JSON — prevented by the validation rule
     */
    public static function decodeValue(mixed $state): mixed
    {
        if (! is_string($state)) {
            return $state;
        }

        if (trim($state) === '') {
            return null;
        }

        return json_decode($state, true, 512, JSON_THROW_ON_ERROR);
    }

    public static function isManaged(string $key): bool
    {
        foreach (self::MANAGED_PREFIXES as $prefix) {
            if ($key === $prefix || str_starts_with($key, $prefix.'.')) {
                return true;
            }
        }

        return false;
    }

    private static function preview(Setting $record): string
    {
        if ($record->is_encrypted) {
            return __('Encrypted');
        }

        $encoded = json_encode($record->value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($encoded)) {
            return '';
        }

        return mb_strlen($encoded) > 120 ? mb_substr($encoded, 0, 120).'…' : $encoded;
    }

    private static function jsonRule(): Closure
    {
        return static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            try {
                json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                $fail(__('That is not valid JSON: :message', ['message' => $exception->getMessage()]));
            }
        };
    }
}

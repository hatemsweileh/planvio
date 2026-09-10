<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiProviders;

use App\Ai\Providers\AiProviderException;
use App\Ai\Providers\ProviderFactory;
use App\Enums\AiDriver;
use App\Filament\Resources\AiProviders\Pages\CreateAiProvider;
use App\Filament\Resources\AiProviders\Pages\EditAiProvider;
use App\Filament\Resources\AiProviders\Pages\ListAiProviders;
use App\Filament\Support\AdminAudit;
use App\Filament\Support\NavigationGroup;
use App\Filament\Support\PlatformResource;
use App\Models\AiProvider;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Throwable;
use UnitEnum;

/**
 * Model endpoints: where Planvio sends a prompt, and with what credential.
 *
 * # The API key never travels back to the browser
 *
 * This is the one rule the whole resource is built around (CLAUDE.md rule 4). `api_key` is
 * `$hidden` on the model, so Filament's edit form never receives it in the first place — the
 * input renders empty on every load. What an administrator sees instead is
 * {@see AiProvider::maskedApiKey()}: a fingerprint like `sk-...9f2a`, enough to recognise which
 * credential is stored and useless to anybody who reads it over a shoulder or out of a browser
 * history entry.
 *
 * Because the field is always empty, an empty submission cannot mean "clear the key" — it means
 * "I did not change it", which is what an administrator editing a temperature actually intends.
 * The field is therefore dropped from the payload unless something was typed into it. Clearing a
 * key deliberately is done by deleting the provider, which is unambiguous.
 *
 * # Extra headers are stored in the clear, and the form says so
 *
 * `headers` exists for gateways that want an extra token. That column is not encrypted, unlike
 * `api_key`, so anything put there is readable by anybody with database access. Saying that
 * plainly on the field is the honest option; silently accepting a credential into an unencrypted
 * column is not.
 *
 * @extends PlatformResource<AiProvider>
 */
final class AiProviderResource extends PlatformResource
{
    protected static ?string $model = AiProvider::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Ai;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('Providers');
    }

    public static function getModelLabel(): string
    {
        return __('AI provider');
    }

    public static function getPluralModelLabel(): string
    {
        return __('AI providers');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Endpoint'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label(__('Name'))
                        ->helperText(__('How this endpoint is referred to elsewhere in Planvio.'))
                        ->required()
                        ->maxLength(191),
                    Select::make('driver')
                        ->label(__('Driver'))
                        ->options(self::driverOptions())
                        ->required()
                        ->live()
                        ->default(AiDriver::OpenAi->value)
                        ->helperText(__('Determines the request shape. Anything speaking the OpenAI chat-completions API — Azure, OpenRouter, Groq, Ollama, LM Studio, vLLM — uses the compatible driver.')),
                    TextInput::make('base_url')
                        ->label(__('Base URL'))
                        ->url()
                        ->maxLength(255)
                        ->required(fn (Get $get): bool => self::requiresBaseUrl($get('driver')))
                        ->placeholder(fn (Get $get): string => self::defaultBaseUrl($get('driver')) ?? __('Required for this driver'))
                        ->helperText(__('Absolute http(s) URL. Left blank, the driver default is used; a driver without one refuses to start.')),
                    TextInput::make('model')
                        ->label(__('Model'))
                        ->required()
                        ->maxLength(191)
                        ->datalist(fn (Get $get): array => self::suggestedModels($get('driver'))),
                    TextInput::make('fallback_model')
                        ->label(__('Fallback model'))
                        ->maxLength(191)
                        ->helperText(__('Tried when the primary model is unavailable. Optional.'))
                        ->datalist(fn (Get $get): array => self::suggestedModels($get('driver'))),
                ]),

            Section::make(__('Credential'))
                ->description(__('Encrypted at rest with the application key, hidden from every API response, and never written to a log.'))
                ->schema([
                    Placeholder::make('stored_api_key')
                        ->label(__('Stored key'))
                        ->visible(fn (?AiProvider $record): bool => $record !== null)
                        ->content(fn (?AiProvider $record): string => $record?->maskedApiKey()
                            ?? __('No key is stored for this provider.')),
                    TextInput::make('api_key')
                        ->label(__('API key'))
                        ->password()
                        ->autocomplete('off')
                        ->maxLength(500)
                        ->placeholder(fn (?AiProvider $record): string => $record?->hasApiKey() === true
                            ? __('Leave blank to keep the stored key')
                            : __('Paste the key'))
                        ->helperText(__('Typing here replaces the stored key. Leaving it blank keeps it.'))
                        // Never dehydrated empty: an untouched field must not blank the column.
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->required(fn (string $operation, Get $get): bool => $operation === 'create'
                            && $get('driver') !== AiDriver::CustomHttp->value),
                ]),

            Section::make(__('Limits'))
                ->description(__('A provider may lower the ceilings in config/ai.php. It can never raise them.'))
                ->columns(3)
                ->schema([
                    TextInput::make('temperature')
                        ->label(__('Temperature'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(2)
                        ->step(0.01)
                        ->helperText(__('Blank uses the provider default.')),
                    TextInput::make('max_tokens')
                        ->label(__('Max output tokens'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(200000),
                    TextInput::make('timeout_seconds')
                        ->label(__('Timeout (seconds)'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue((int) config('ai.limits.request_timeout_seconds', 60))
                        ->default((int) config('ai.limits.request_timeout_seconds', 60))
                        ->required()
                        ->helperText(__('Clamped to :max seconds, the installation ceiling.', [
                            'max' => (int) config('ai.limits.request_timeout_seconds', 60),
                        ])),
                ]),

            Section::make(__('Advanced'))
                ->description(__('Only needed for gateways and self-hosted runtimes. Both are stored unencrypted — do not put a credential in either.'))
                ->columns(2)
                ->collapsed()
                ->schema([
                    KeyValue::make('headers')
                        ->label(__('Extra request headers'))
                        ->keyLabel(__('Header'))
                        ->valueLabel(__('Value')),
                    KeyValue::make('options')
                        ->label(__('Extra body parameters'))
                        ->keyLabel(__('Parameter'))
                        ->valueLabel(__('Value'))
                        ->helperText(__('Merged into the request body, e.g. top_p.')),
                ]),

            Section::make(__('Availability'))
                ->columns(2)
                ->schema([
                    Toggle::make('is_active')
                        ->label(__('Active'))
                        ->helperText(__('An inactive provider is never called and cannot be the default.')),
                    Toggle::make('is_default')
                        ->label(__('Default provider'))
                        ->helperText(__('Used by any workspace that has not chosen one. Setting this clears the flag on every other provider.')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('driver')
                    ->label(__('Driver'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?AiDriver $state): string => $state?->label() ?? __('Unknown')),
                TextColumn::make('model')
                    ->label(__('Model'))
                    ->searchable()
                    ->description(fn (AiProvider $record): ?string => $record->fallback_model),
                TextColumn::make('base_url')
                    ->label(__('Base URL'))
                    ->placeholder(__('Driver default'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('masked_api_key')
                    ->label(__('Key'))
                    // The masked fingerprint, never the value — the column is `$hidden` on
                    // the model, so the state has to be produced explicitly.
                    ->state(fn (AiProvider $record): string => $record->maskedApiKey() ?? __('None'))
                    ->color(fn (AiProvider $record): string => $record->hasApiKey() ? 'gray' : 'warning'),
                IconColumn::make('is_active')
                    ->label(__('Active'))
                    ->boolean(),
                IconColumn::make('is_default')
                    ->label(__('Default'))
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedCheckCircle)
                    ->falseIcon(Heroicon::OutlinedXCircle)
                    ->falseColor('gray'),
            ])
            ->filters([
                SelectFilter::make('driver')
                    ->label(__('Driver'))
                    ->options(self::driverOptions()),
                TernaryFilter::make('is_active')
                    ->label(__('Active')),
            ])
            ->recordActions([
                self::testConnectionAction(),
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make()
                        ->modalDescription(__('The stored credential is destroyed with the row. Workspaces pointing at this provider fall back to the default one.')),
                ]),
            ])
            ->toolbarActions([])
            ->emptyStateIcon(Heroicon::OutlinedCpuChip)
            ->emptyStateHeading(__('No AI provider configured'))
            ->emptyStateDescription(__('Planvio runs perfectly well without one. Add a provider to turn the AI features on.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiProviders::route('/'),
            'create' => CreateAiProvider::route('/create'),
            'edit' => EditAiProvider::route('/{record}/edit'),
        ];
    }

    /* ------------------------------------------------------------------ *
     * Test connection
     * ------------------------------------------------------------------ */

    /**
     * One round trip through the real driver, reported as a sentence.
     *
     * The failure text comes from {@see AiProviderException} and {@see ProviderHealth}, both of
     * which are written to be safe to display: an endpoint rejecting a credential is precisely
     * when it is most likely to echo that credential back in its body, and the drivers never
     * pass a response body through. Nothing here adds the key to the message either.
     */
    private static function testConnectionAction(): Action
    {
        return Action::make('testConnection')
            ->label(__('Test connection'))
            ->icon(Heroicon::OutlinedSignal)
            ->color('gray')
            ->action(function (AiProvider $record): void {
                $started = microtime(true);

                try {
                    $health = app(ProviderFactory::class)->make($record)->testConnection();
                } catch (AiProviderException $exception) {
                    self::reportTest($record, false, $exception->getMessage(), null);

                    return;
                } catch (Throwable) {
                    // Anything the driver did not turn into a domain failure — a DNS error, a
                    // TLS failure, a host that closed the socket. The message is deliberately
                    // ours rather than the exception's: a low-level client message can carry
                    // the request it was making, headers included.
                    self::reportTest(
                        $record,
                        false,
                        __('The endpoint could not be reached. Check the base URL, and confirm the host allows outbound HTTPS.'),
                        (int) round((microtime(true) - $started) * 1000),
                    );

                    return;
                }

                self::reportTest($record, $health->ok, $health->message, $health->latencyMs);
            });
    }

    private static function reportTest(AiProvider $record, bool $ok, string $message, ?int $latencyMs): void
    {
        AdminAudit::record(
            'admin.ai_provider_tested',
            $ok
                ? __('AI provider connection test succeeded.')
                : __('AI provider connection test failed.'),
            properties: [
                'provider_id' => (int) $record->getKey(),
                'provider' => (string) $record->name,
                'driver' => $record->driver?->value,
                'ok' => $ok,
                'latency_ms' => $latencyMs,
            ],
        );

        Notification::make()
            ->title($ok
                ? __(':name answered.', ['name' => $record->name])
                : __(':name did not answer.', ['name' => $record->name]))
            ->body($latencyMs === null
                ? $message
                : $message.' '.__('(:ms ms)', ['ms' => $latencyMs]))
            ->status($ok ? 'success' : 'danger')
            ->persistent()
            ->send();
    }

    /* ------------------------------------------------------------------ *
     * Driver metadata
     * ------------------------------------------------------------------ */

    /**
     * Exactly one provider carries the default flag.
     *
     * Called by both the create and the edit page after a save, because `defaultProvider()`
     * resolves by `orderByDesc('is_default')` and two flagged rows would make which one wins an
     * accident of insertion order.
     */
    public static function enforceSingleDefault(AiProvider $record): void
    {
        if (! $record->is_default) {
            return;
        }

        AiProvider::query()
            ->whereKeyNot($record->getKey())
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    /**
     * @return array<string, string>
     */
    private static function driverOptions(): array
    {
        $options = [];

        foreach (AiDriver::cases() as $driver) {
            $options[$driver->value] = $driver->label();
        }

        return $options;
    }

    private static function requiresBaseUrl(mixed $driver): bool
    {
        return (bool) (self::definition($driver)['requires_base_url'] ?? false);
    }

    private static function defaultBaseUrl(mixed $driver): ?string
    {
        $url = self::definition($driver)['base_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * @return list<string>
     */
    private static function suggestedModels(mixed $driver): array
    {
        $models = self::definition($driver)['suggested_models'] ?? [];

        return is_array($models) ? array_values(array_filter($models, is_string(...))) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function definition(mixed $driver): array
    {
        if (! is_string($driver)) {
            return [];
        }

        $definition = config('ai.drivers.'.$driver);

        return is_array($definition) ? $definition : [];
    }
}

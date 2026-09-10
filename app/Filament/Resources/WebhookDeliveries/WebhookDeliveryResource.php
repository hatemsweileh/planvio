<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookDeliveries;

use App\Filament\Resources\WebhookDeliveries\Pages\ListWebhookDeliveries;
use App\Filament\Resources\WebhookDeliveries\Pages\ViewWebhookDelivery;
use App\Filament\Support\LogResource;
use App\Filament\Support\NavigationGroup;
use App\Models\WebhookDelivery;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Outbound webhook attempts: what Planvio sent, and what came back.
 *
 * This is the screen somebody opens when an integration has stopped working, so it leads with
 * the two facts that diagnose it — the response status and the attempt number — and keeps the
 * request payload and the (truncated) response body a click away.
 *
 * Nothing here is editable, and there is no "resend": a delivery row is the record of an attempt
 * that has already happened. Retries belong to the queue, which backs off on its own schedule
 * and switches an endpoint off after `config('planvio.webhooks.disable_after_failures')`
 * consecutive failures rather than hammering a dead URL forever.
 *
 * The endpoint's signing secret is not shown. It lives on the webhook, not on the delivery, and
 * nothing in this panel renders it.
 *
 * @extends LogResource<WebhookDelivery>
 */
final class WebhookDeliveryResource extends LogResource
{
    protected static ?string $model = WebhookDelivery::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Logs;

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'event';

    public static function getNavigationLabel(): string
    {
        return __('Webhook deliveries');
    }

    public static function getModelLabel(): string
    {
        return __('webhook delivery');
    }

    public static function getPluralModelLabel(): string
    {
        return __('webhook deliveries');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('When'))
                    ->dateTime()
                    ->since()
                    ->sortable(),
                TextColumn::make('event')
                    ->label(__('Event'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('webhook.name')
                    ->label(__('Endpoint'))
                    ->searchable()
                    ->placeholder(__('Endpoint deleted'))
                    ->description(fn (WebhookDelivery $record): ?string => $record->webhook?->url),
                TextColumn::make('webhook.workspace.name')
                    ->label(__('Workspace'))
                    ->placeholder(__('Unknown')),
                TextColumn::make('response_status')
                    ->label(__('Response'))
                    ->badge()
                    ->state(fn (WebhookDelivery $record): string => $record->response_status === null
                        ? __('No response')
                        : (string) $record->response_status)
                    ->color(fn (WebhookDelivery $record): string => match (true) {
                        $record->wasAccepted() => 'success',
                        $record->response_status === null => 'danger',
                        default => 'warning',
                    })
                    ->sortable(),
                TextColumn::make('attempt')
                    ->label(__('Attempt'))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('delivered_at')
                    ->label(__('Delivered'))
                    ->dateTime()
                    ->placeholder(__('Never'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('webhook_id')
                    ->label(__('Endpoint'))
                    ->relationship('webhook', 'name')
                    ->searchable(),
                Filter::make('failed')
                    ->label(__('Failed only'))
                    ->query(fn (Builder $query): Builder => $query->failed()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([])
            ->emptyStateIcon(Heroicon::OutlinedPaperAirplane)
            ->emptyStateHeading(__('No webhook deliveries'))
            ->emptyStateDescription(__('Deliveries appear here once a workspace configures an endpoint and an event fires.'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Attempt'))
                ->columns(3)
                ->schema([
                    TextEntry::make('event')
                        ->label(__('Event')),
                    TextEntry::make('attempt')
                        ->label(__('Attempt number')),
                    TextEntry::make('response_status')
                        ->label(__('Response status'))
                        ->badge()
                        ->state(fn (WebhookDelivery $record): string => $record->response_status === null
                            ? __('No response')
                            : (string) $record->response_status)
                        ->color(fn (WebhookDelivery $record): string => $record->wasAccepted() ? 'success' : 'danger'),
                    TextEntry::make('webhook.name')
                        ->label(__('Endpoint'))
                        ->placeholder(__('Endpoint deleted')),
                    TextEntry::make('webhook.url')
                        ->label(__('URL'))
                        ->placeholder(__('Unknown'))
                        ->columnSpan(2),
                    TextEntry::make('created_at')
                        ->label(__('Queued'))
                        ->dateTime(),
                    TextEntry::make('delivered_at')
                        ->label(__('Delivered'))
                        ->dateTime()
                        ->placeholder(__('Never')),
                ]),

            Section::make(__('Payload sent'))
                ->collapsed()
                ->schema([
                    TextEntry::make('payload')
                        ->hiddenLabel()
                        ->placeholder(__('Empty'))
                        ->formatStateUsing(self::formatJson(...)),
                ]),

            Section::make(__('Response body'))
                ->description(__('Truncated to :bytes bytes when it was stored: an endpoint that answers with a megabyte of HTML must not be able to fill the database.', [
                    'bytes' => (int) config('planvio.webhooks.max_response_bytes', 2048),
                ]))
                ->collapsed()
                ->schema([
                    TextEntry::make('response_body')
                        ->hiddenLabel()
                        ->placeholder(__('The endpoint returned no body, or never answered.')),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWebhookDeliveries::route('/'),
            'view' => ViewWebhookDelivery::route('/{record}'),
        ];
    }

    /**
     * @return Builder<WebhookDelivery>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'webhook' => self::acrossWorkspaces(),
            'webhook.workspace',
        ]);
    }

    public static function formatJson(mixed $state): string
    {
        if ($state === null || $state === [] || $state === '') {
            return '';
        }

        if (is_string($state)) {
            return $state;
        }

        $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '';
    }
}

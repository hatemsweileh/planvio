<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Filament\Support\LogResource;
use App\Filament\Support\NavigationGroup;
use App\Models\AuditLog;
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
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * The security trail: sign-ins, password events, two-factor changes, and everything done from
 * this panel.
 *
 * Deliberately separate from the product's `activities` feed. That one answers "what happened to
 * this task" and is shown to workspace members; this answers "who signed in, from where, and
 * what was changed about the installation", is visible only here, and outlives the accounts and
 * workspaces it refers to — both foreign keys null out rather than cascade, so the trail survives
 * the deletion of everything it describes.
 *
 * An email address does appear on failed sign-ins. Without it the trail cannot tell one account
 * being guessed at from background noise, which is the entire reason those rows are written.
 * `properties` never carries a credential.
 *
 * @extends LogResource<AuditLog>
 */
final class AuditLogResource extends LogResource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Logs;

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'event';

    public static function getNavigationLabel(): string
    {
        return __('Audit log');
    }

    public static function getModelLabel(): string
    {
        return __('audit entry');
    }

    public static function getPluralModelLabel(): string
    {
        return __('audit entries');
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
                    ->badge()
                    ->color(fn (AuditLog $record): string => self::eventColor((string) $record->event))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('description')
                    ->label(__('What happened'))
                    ->wrap()
                    ->placeholder(__('No description')),
                TextColumn::make('user.name')
                    ->label(__('Who'))
                    ->placeholder(__('Not signed in'))
                    ->searchable(),
                TextColumn::make('workspace.name')
                    ->label(__('Workspace'))
                    ->placeholder(__('Platform'))
                    ->searchable(),
                TextColumn::make('ip')
                    ->label(__('From'))
                    ->placeholder(__('Unknown'))
                    ->searchable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label(__('Event'))
                    ->options(self::eventOptions())
                    ->multiple()
                    ->searchable(),
                SelectFilter::make('workspace_id')
                    ->label(__('Workspace'))
                    ->relationship('workspace', 'name')
                    ->searchable(),
                Filter::make('last_24_hours')
                    ->label(__('Last 24 hours'))
                    ->query(fn (Builder $query): Builder => $query->where('created_at', '>=', Carbon::now()->subDay())),
                Filter::make('security_only')
                    ->label(__('Sign-in and credential events'))
                    ->query(fn (Builder $query): Builder => $query->where('event', 'like', 'auth.%')),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([])
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentList)
            ->emptyStateHeading(__('Nothing in the audit log yet'))
            ->emptyStateDescription(__('Sign-ins, password changes and administrative actions are recorded here.'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Entry'))
                ->columns(3)
                ->schema([
                    TextEntry::make('event')
                        ->label(__('Event'))
                        ->badge()
                        ->color(fn (AuditLog $record): string => self::eventColor((string) $record->event)),
                    TextEntry::make('created_at')
                        ->label(__('When'))
                        ->dateTime(),
                    TextEntry::make('user.name')
                        ->label(__('Who'))
                        ->placeholder(__('Not signed in')),
                    TextEntry::make('workspace.name')
                        ->label(__('Workspace'))
                        ->placeholder(__('Platform-wide')),
                    TextEntry::make('ip')
                        ->label(__('IP address'))
                        ->placeholder(__('Unknown')),
                    TextEntry::make('user_agent')
                        ->label(__('User agent'))
                        ->placeholder(__('Unknown'))
                        ->columnSpanFull(),
                    TextEntry::make('description')
                        ->label(__('Description'))
                        ->placeholder(__('None'))
                        ->columnSpanFull()
                        ->prose(),
                ]),

            Section::make(__('Recorded detail'))
                ->description(__('Written by the code that produced the entry. Credentials are never among it.'))
                ->schema([
                    TextEntry::make('properties')
                        ->hiddenLabel()
                        ->placeholder(__('None'))
                        ->formatStateUsing(self::formatProperties(...)),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
            'view' => ViewAuditLog::route('/{record}'),
        ];
    }

    /**
     * @return Builder<AuditLog>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'workspace']);
    }

    /* ------------------------------------------------------------------ *
     * Formatting
     * ------------------------------------------------------------------ */

    public static function formatProperties(mixed $state): string
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

    /**
     * Colour by what the event means, not by its namespace: a failed sign-in and a forced
     * password reset should both read as "look at this" at a glance.
     */
    private static function eventColor(string $event): string
    {
        return match (true) {
            str_contains($event, 'failed'), str_contains($event, 'locked'), str_contains($event, 'denied') => 'danger',
            str_contains($event, 'deleted'), str_contains($event, 'suspended'), str_contains($event, 'reset') => 'warning',
            str_starts_with($event, 'auth.') => 'info',
            str_starts_with($event, 'admin.') => 'primary',
            default => 'gray',
        };
    }

    /**
     * The events this installation has actually written, so the filter never offers a value
     * that matches nothing.
     *
     * @return array<string, string>
     */
    private static function eventOptions(): array
    {
        return AuditLog::query()
            ->select('event')
            ->distinct()
            ->orderBy('event')
            ->limit(200)
            ->pluck('event', 'event')
            ->all();
    }
}

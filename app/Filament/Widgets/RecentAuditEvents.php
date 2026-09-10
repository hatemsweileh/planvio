<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Support\PlatformWidget;
use App\Models\AuditLog;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * The last ten security and administration events.
 *
 * Deliberately unpaginated and unsorted by the reader: it is a glance, not a search. The full
 * log, with its filters, is one click away — and that link is the point of the widget as much as
 * the rows are.
 */
final class RecentAuditEvents extends TableWidget
{
    use PlatformWidget;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 1;

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Recent activity'))
            ->description(__('Sign-ins, credential changes and everything done from this panel.'))
            ->query(fn (): Builder => AuditLog::query()->with(['user', 'workspace']))
            ->defaultSort('created_at', 'desc')
            ->paginationMode(PaginationMode::Simple)
            ->paginated([10])
            ->defaultPaginationPageOption(10)
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('When'))
                    ->since()
                    ->tooltip(fn (AuditLog $record): ?string => $record->created_at?->toDayDateTimeString()),
                TextColumn::make('event')
                    ->label(__('Event'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('description')
                    ->label(__('What happened'))
                    ->limit(60)
                    ->placeholder(__('No description')),
                TextColumn::make('user.name')
                    ->label(__('Who'))
                    ->placeholder(__('Not signed in')),
            ])
            ->headerActions([
                Action::make('openAuditLog')
                    ->label(__('Open the audit log'))
                    ->icon(Heroicon::OutlinedArrowRightOnRectangle)
                    ->color('gray')
                    ->url(fn (): string => AuditLogResource::getUrl()),
            ])
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentList)
            ->emptyStateHeading(__('Nothing recorded yet'))
            ->emptyStateDescription(__('The first sign-in will appear here.'));
    }
}

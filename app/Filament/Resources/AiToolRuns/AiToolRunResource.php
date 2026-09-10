<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiToolRuns;

use App\Enums\AiToolRisk;
use App\Enums\ToolRunStatus;
use App\Filament\Resources\AiRuns\AiRunResource;
use App\Filament\Resources\AiToolRuns\Pages\ListAiToolRuns;
use App\Filament\Support\LogResource;
use App\Filament\Support\NavigationGroup;
use App\Filament\Support\PanelColor;
use App\Models\AiToolRun;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Individual tool invocations, across every run in the installation.
 *
 * The same rows appear inside each run's trace; this view exists for the question that cuts the
 * other way — "has anything deleted a project this month", "which approvals are still pending",
 * "did that tool ever succeed" — which is unanswerable one run at a time.
 *
 * Arguments are not shown here. They are per-call detail, they are already redacted, and the
 * place to read them is the run they belong to, where the surrounding calls give them meaning.
 *
 * @extends LogResource<AiToolRun>
 */
final class AiToolRunResource extends LogResource
{
    protected static ?string $model = AiToolRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrench;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Logs;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'tool';

    public static function getNavigationLabel(): string
    {
        return __('Tool calls');
    }

    public static function getModelLabel(): string
    {
        return __('tool call');
    }

    public static function getPluralModelLabel(): string
    {
        return __('tool calls');
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
                TextColumn::make('tool')
                    ->label(__('Tool'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('risk')
                    ->label(__('Risk'))
                    ->badge()
                    ->formatStateUsing(fn (?AiToolRisk $state): string => $state?->label() ?? '')
                    ->color(fn (?AiToolRisk $state): string => PanelColor::for($state?->color())),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (?ToolRunStatus $state): string => $state?->label() ?? '')
                    ->color(fn (?ToolRunStatus $state): string => PanelColor::for($state?->color())),
                TextColumn::make('workspace.name')
                    ->label(__('Workspace'))
                    ->searchable()
                    ->description(fn (AiToolRun $record): ?string => $record->project?->name),
                TextColumn::make('user.name')
                    ->label(__('Acting as'))
                    ->placeholder(__('Account removed')),
                TextColumn::make('approver.name')
                    ->label(__('Approved by'))
                    ->placeholder(fn (AiToolRun $record): string => $record->approval_required
                        ? __('Awaiting')
                        : __('Not required')),
                TextColumn::make('subject_type')
                    ->label(__('Touched'))
                    ->state(fn (AiToolRun $record): string => $record->subject_type === null
                        ? '—'
                        : class_basename($record->subject_type).' #'.$record->subject_id),
                TextColumn::make('result_summary')
                    ->label(__('Result'))
                    ->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('duration_ms')
                    ->label(__('Took'))
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : number_format($state).' ms')
                    ->alignEnd()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(ToolRunStatus::options())
                    ->multiple(),
                SelectFilter::make('risk')
                    ->label(__('Risk'))
                    ->options(AiToolRisk::options())
                    ->multiple(),
                SelectFilter::make('workspace_id')
                    ->label(__('Workspace'))
                    ->relationship('workspace', 'name')
                    ->searchable(),
                Filter::make('mutating')
                    ->label(__('Changed something'))
                    ->query(fn (Builder $query): Builder => $query->where('risk', '!=', AiToolRisk::Read->value)),
                Filter::make('awaiting_approval')
                    ->label(__('Awaiting approval'))
                    ->query(fn (Builder $query): Builder => $query->where('status', ToolRunStatus::PendingApproval->value)),
            ])
            ->recordActions([
                Action::make('viewRun')
                    ->label(__('Open run'))
                    ->icon(Heroicon::OutlinedArrowRightOnRectangle)
                    ->color('gray')
                    ->url(fn (AiToolRun $record): string => AiRunResource::getUrl('view', ['record' => $record->ai_run_id])),
            ])
            ->toolbarActions([])
            ->emptyStateIcon(Heroicon::OutlinedWrench)
            ->emptyStateHeading(__('No tool calls recorded'))
            ->emptyStateDescription(__('Every action the agent takes is written here before it is considered done.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiToolRuns::route('/'),
        ];
    }

    /**
     * @return Builder<AiToolRun>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'workspace',
            'user',
            'approver',
            'project' => self::acrossWorkspaces(),
        ]);
    }
}

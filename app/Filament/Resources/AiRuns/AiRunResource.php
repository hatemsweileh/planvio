<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiRuns;

use App\Enums\AiMode;
use App\Enums\AiRunStatus;
use App\Enums\AiToolRisk;
use App\Enums\AiTrigger;
use App\Enums\ToolRunStatus;
use App\Filament\Resources\AiRuns\Pages\ListAiRuns;
use App\Filament\Resources\AiRuns\Pages\ViewAiRun;
use App\Filament\Support\LogResource;
use App\Filament\Support\NavigationGroup;
use App\Filament\Support\PanelColor;
use App\Models\AiRun;
use App\Models\AiToolRun;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Every agent execution, and what each one actually did.
 *
 * This is the read side of the audit spine. A run row says who asked, under what mode, against
 * which workspace, and how it ended; the `ai_tool_runs` beneath it say which tool was called
 * with which (redacted) arguments, whether a human approved it, what it touched and how long it
 * took. Together they are the answer to "the agent changed something — what, and on whose
 * authority" (AI_SECURITY.md).
 *
 * Nothing here is editable. A tool trace that could be corrected afterwards would not be
 * evidence of anything.
 *
 * Prompt bodies are absent because they are not stored: `config('ai.logging.store_prompts')`
 * is off by default, and tool arguments are redacted before they are written.
 *
 * @extends LogResource<AiRun>
 */
final class AiRunResource extends LogResource
{
    protected static ?string $model = AiRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Logs;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'uuid';

    public static function getNavigationLabel(): string
    {
        return __('AI runs');
    }

    public static function getModelLabel(): string
    {
        return __('AI run');
    }

    public static function getPluralModelLabel(): string
    {
        return __('AI runs');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('Started'))
                    ->dateTime()
                    ->since()
                    ->sortable()
                    ->description(fn (AiRun $record): string => (string) $record->uuid),
                TextColumn::make('workspace.name')
                    ->label(__('Workspace'))
                    ->searchable()
                    ->description(fn (AiRun $record): ?string => $record->project?->name),
                TextColumn::make('user.name')
                    ->label(__('Acting as'))
                    ->placeholder(__('Account removed'))
                    ->searchable(),
                TextColumn::make('trigger')
                    ->label(__('Trigger'))
                    ->badge()
                    ->formatStateUsing(fn (?AiTrigger $state): string => $state?->label() ?? '')
                    ->color(fn (?AiTrigger $state): string => PanelColor::for($state?->color())),
                TextColumn::make('mode')
                    ->label(__('Mode'))
                    ->badge()
                    ->formatStateUsing(fn (?AiMode $state): string => $state?->label() ?? '')
                    ->color(fn (?AiMode $state): string => PanelColor::for($state?->color())),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (?AiRunStatus $state): string => $state?->label() ?? '')
                    ->color(fn (?AiRunStatus $state): string => PanelColor::for($state?->color()))
                    ->sortable(),
                TextColumn::make('tool_call_count')
                    ->label(__('Tools'))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('tokens_in')
                    ->label(__('Tokens'))
                    ->state(fn (AiRun $record): string => number_format((int) $record->tokens_in)
                        .' / '.number_format((int) $record->tokens_out))
                    ->alignEnd()
                    ->tooltip(__('In / out')),
                TextColumn::make('duration_ms')
                    ->label(__('Duration'))
                    ->state(fn (AiRun $record): string => self::duration($record))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('model')
                    ->label(__('Model'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(AiRunStatus::options())
                    ->multiple(),
                SelectFilter::make('trigger')
                    ->label(__('Trigger'))
                    ->options(self::triggerOptions()),
                SelectFilter::make('workspace_id')
                    ->label(__('Workspace'))
                    ->relationship('workspace', 'name')
                    ->searchable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([])
            ->emptyStateIcon(Heroicon::OutlinedSparkles)
            ->emptyStateHeading(__('No AI runs recorded'))
            ->emptyStateDescription(__('Runs appear here as soon as somebody uses the assistant or an automation fires.'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Run'))
                ->columns(3)
                ->schema([
                    TextEntry::make('uuid')
                        ->label(__('Identifier'))
                        ->copyable(),
                    TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->formatStateUsing(fn (?AiRunStatus $state): string => $state?->label() ?? '')
                        ->color(fn (?AiRunStatus $state): string => PanelColor::for($state?->color())),
                    TextEntry::make('mode')
                        ->label(__('Mode'))
                        ->badge()
                        ->formatStateUsing(fn (?AiMode $state): string => $state?->label() ?? '')
                        ->color(fn (?AiMode $state): string => PanelColor::for($state?->color())),
                    TextEntry::make('workspace.name')
                        ->label(__('Workspace'))
                        ->placeholder(__('None')),
                    TextEntry::make('project.name')
                        ->label(__('Project'))
                        ->placeholder(__('Whole workspace')),
                    TextEntry::make('user.name')
                        ->label(__('Acting as'))
                        ->placeholder(__('Account removed'))
                        ->helperText(__('Every tool call was authorised as this person, never as the platform.')),
                    TextEntry::make('trigger')
                        ->label(__('Trigger'))
                        ->formatStateUsing(fn (?AiTrigger $state): string => $state?->label() ?? ''),
                    TextEntry::make('automation.name')
                        ->label(__('Automation'))
                        ->placeholder(__('Not an automation')),
                    TextEntry::make('provider.name')
                        ->label(__('Provider'))
                        ->placeholder(__('Unknown'))
                        ->helperText(fn (AiRun $record): ?string => $record->model),
                ]),

            Section::make(__('Objective'))
                ->schema([
                    TextEntry::make('objective')
                        ->hiddenLabel()
                        ->placeholder(__('No objective was recorded for this run.'))
                        ->prose(),
                ]),

            Section::make(__('Outcome'))
                ->columns(4)
                ->schema([
                    TextEntry::make('started_at')
                        ->label(__('Started'))
                        ->dateTime()
                        ->placeholder(__('Never started')),
                    TextEntry::make('finished_at')
                        ->label(__('Finished'))
                        ->dateTime()
                        ->placeholder(__('Still running')),
                    TextEntry::make('duration_ms')
                        ->label(__('Duration'))
                        ->state(fn (AiRun $record): string => self::duration($record)),
                    TextEntry::make('steps')
                        ->label(__('Steps')),
                    TextEntry::make('tool_call_count')
                        ->label(__('Tool calls')),
                    TextEntry::make('error_count')
                        ->label(__('Errors')),
                    TextEntry::make('tokens_in')
                        ->label(__('Tokens in'))
                        ->numeric(),
                    TextEntry::make('tokens_out')
                        ->label(__('Tokens out'))
                        ->numeric(),
                    TextEntry::make('summary')
                        ->label(__('Summary'))
                        ->placeholder(__('None recorded'))
                        ->columnSpanFull()
                        ->prose(),
                    TextEntry::make('error')
                        ->label(__('Error'))
                        ->placeholder(__('None'))
                        ->color('danger')
                        ->columnSpanFull(),
                ]),

            Section::make(__('Tool trace'))
                ->description(__('Every tool this run invoked, in order. Arguments are stored redacted; a credential never reaches this table.'))
                ->schema([
                    RepeatableEntry::make('toolRuns')
                        ->hiddenLabel()
                        ->placeholder(__('This run called no tools.'))
                        ->schema([
                            TextEntry::make('sequence')
                                ->label(__('#')),
                            TextEntry::make('tool')
                                ->label(__('Tool')),
                            TextEntry::make('risk')
                                ->label(__('Risk'))
                                ->badge()
                                ->formatStateUsing(fn (?AiToolRisk $state): string => $state?->label() ?? '')
                                ->color(fn (?AiToolRisk $state): string => PanelColor::for($state?->color())),
                            TextEntry::make('status')
                                ->label(__('Status'))
                                ->badge()
                                ->formatStateUsing(fn (?ToolRunStatus $state): string => $state?->label() ?? '')
                                ->color(fn (?ToolRunStatus $state): string => PanelColor::for($state?->color())),
                            TextEntry::make('approver.name')
                                ->label(__('Approved by'))
                                ->placeholder(fn (AiToolRun $record): string => $record->approval_required
                                    ? __('Not approved')
                                    : __('No approval needed')),
                            TextEntry::make('duration_ms')
                                ->label(__('Took'))
                                ->formatStateUsing(fn (?int $state): string => $state === null
                                    ? '—'
                                    : number_format($state).' ms'),
                            TextEntry::make('subject_type')
                                ->label(__('Touched'))
                                ->state(fn (AiToolRun $record): string => $record->subject_type === null
                                    ? '—'
                                    : class_basename($record->subject_type).' #'.$record->subject_id),
                            TextEntry::make('result_summary')
                                ->label(__('Result'))
                                ->placeholder(__('None recorded'))
                                ->columnSpanFull(),
                            TextEntry::make('rejected_reason')
                                ->label(__('Rejected because'))
                                ->visible(fn (AiToolRun $record): bool => filled($record->rejected_reason))
                                ->columnSpanFull(),
                            TextEntry::make('error')
                                ->label(__('Error'))
                                ->color('danger')
                                ->visible(fn (AiToolRun $record): bool => filled($record->error))
                                ->columnSpanFull(),
                            TextEntry::make('arguments')
                                ->label(__('Arguments (redacted)'))
                                ->placeholder(__('None'))
                                ->columnSpanFull()
                                ->formatStateUsing(self::formatArguments(...)),
                        ])
                        ->columns(4),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiRuns::route('/'),
            'view' => ViewAiRun::route('/{record}'),
        ];
    }

    /**
     * @return Builder<AiRun>
     */
    public static function getEloquentQuery(): Builder
    {
        // The trace itself is loaded by the view page, not here: the list would otherwise pull
        // every tool run on the page with it.
        return parent::getEloquentQuery()->with([
            'workspace',
            'user',
            'project' => self::acrossWorkspaces(),
        ]);
    }

    /* ------------------------------------------------------------------ *
     * Formatting
     * ------------------------------------------------------------------ */

    public static function formatArguments(mixed $state): string
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

    private static function duration(AiRun $record): string
    {
        $seconds = $record->durationSeconds();

        if ($seconds === null) {
            return '—';
        }

        return $seconds < 1
            ? number_format($seconds * 1000).' ms'
            : number_format($seconds, 1).' s';
    }

    /**
     * @return array<string, string>
     */
    private static function triggerOptions(): array
    {
        $options = [];

        foreach (AiTrigger::cases() as $trigger) {
            $options[$trigger->value] = $trigger->label();
        }

        return $options;
    }
}

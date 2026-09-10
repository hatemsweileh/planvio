<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiPolicies;

use App\Ai\Agent\ToolRegistry;
use App\Enums\AiMode;
use App\Enums\AiToolRisk;
use App\Enums\WorkspaceRole;
use App\Filament\Resources\AiPolicies\Pages\CreateAiPolicy;
use App\Filament\Resources\AiPolicies\Pages\EditAiPolicy;
use App\Filament\Resources\AiPolicies\Pages\ListAiPolicies;
use App\Filament\Support\NavigationGroup;
use App\Filament\Support\PanelColor;
use App\Filament\Support\PlatformResource;
use App\Models\AiPolicy;
use App\Models\Project;
use App\Models\Workspace;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The rules that decide what the agent may do, and when a human has to say yes first.
 *
 * A policy is resolved per run by `App\Ai\Policy\PolicyResolver`: the most specific active rule
 * wins — project before workspace, workspace before platform, higher `priority` before lower.
 * A row with no workspace is the installation-wide floor, which is why this resource is not
 * workspace-scoped and why the workspace field is optional rather than required.
 *
 * The tool lists are populated from the live `ToolRegistry`, not typed by hand: a policy naming
 * a tool that does not exist is a rule that silently does nothing, and the moment to catch that
 * is while it is being written.
 *
 * The panel cannot loosen the ceilings in `config('ai.approvals')`. Tools listed under
 * `always_require_approval` there need a human regardless of what any row here says.
 *
 * @extends PlatformResource<AiPolicy>
 */
final class AiPolicyResource extends PlatformResource
{
    protected static ?string $model = AiPolicy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Ai;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('Policies');
    }

    public static function getModelLabel(): string
    {
        return __('AI policy');
    }

    public static function getPluralModelLabel(): string
    {
        return __('AI policies');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Scope'))
                ->description(__('Leave both blank for a rule that applies to the whole installation. The most specific active rule wins.'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label(__('Name'))
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Select::make('workspace_id')
                        ->label(__('Workspace'))
                        ->options(fn (): array => Workspace::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->live()
                        ->placeholder(__('Every workspace')),
                    Select::make('project_id')
                        ->label(__('Project'))
                        ->options(fn (Get $get): array => self::projectOptions($get('workspace_id')))
                        ->searchable()
                        ->placeholder(__('Every project'))
                        ->disabled(fn (Get $get): bool => blank($get('workspace_id')))
                        ->helperText(__('Choose a workspace first. A project rule beats the workspace rule it sits inside.')),
                    TextInput::make('priority')
                        ->label(__('Priority'))
                        ->numeric()
                        ->default(0)
                        ->required()
                        ->helperText(__('Higher wins when two rules are equally specific.')),
                    Toggle::make('is_active')
                        ->label(__('Active'))
                        ->default(true),
                ]),

            Section::make(__('Mode and risk'))
                ->columns(2)
                ->schema([
                    Select::make('mode')
                        ->label(__('Mode'))
                        ->options(self::modeOptions())
                        ->placeholder(__('Use the workspace default'))
                        ->helperText(__('Assistant answers questions and changes nothing. Copilot proposes each change. Autonomous acts within these limits.')),
                    Select::make('max_risk')
                        ->label(__('Maximum unattended risk'))
                        ->options(self::riskOptions())
                        ->default(AiToolRisk::Medium->value)
                        ->required()
                        ->helperText(__('Anything riskier than this needs a human approval, whatever the mode.')),
                    Select::make('allowed_roles')
                        ->label(__('Roles that may drive the agent'))
                        ->options(self::roleOptions())
                        ->multiple()
                        ->placeholder(__('Every role the workspace permits'))
                        ->columnSpanFull(),
                ]),

            Section::make(__('Tools'))
                ->description(__('An explicit deny always beats an explicit allow. An allow-list denies everything absent from it; leave it empty to permit everything not denied.'))
                ->schema([
                    Select::make('allowed_tools')
                        ->label(__('Allowed tools'))
                        ->options(self::toolOptions())
                        ->multiple()
                        ->searchable()
                        ->placeholder(__('Everything not denied')),
                    Select::make('denied_tools')
                        ->label(__('Denied tools'))
                        ->options(self::toolOptions())
                        ->multiple()
                        ->searchable()
                        ->placeholder(__('Nothing')),
                    Select::make('approval_required_tools')
                        ->label(__('Always ask before these'))
                        ->options(self::toolOptions())
                        ->multiple()
                        ->searchable()
                        ->placeholder(__('Only what the risk ceiling already catches'))
                        ->helperText(__('In addition to :tools, which always need approval and cannot be waived.', [
                            'tools' => implode(', ', self::alwaysApproved()),
                        ])),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('priority', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Policy'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('workspace.name')
                    ->label(__('Workspace'))
                    ->placeholder(__('Whole installation'))
                    ->searchable(),
                TextColumn::make('project.name')
                    ->label(__('Project'))
                    ->placeholder(__('All projects')),
                TextColumn::make('mode')
                    ->label(__('Mode'))
                    ->badge()
                    ->placeholder(__('Workspace default'))
                    ->formatStateUsing(fn (?AiMode $state): string => $state?->label() ?? '')
                    ->color(fn (?AiMode $state): string => PanelColor::for($state?->color())),
                TextColumn::make('max_risk')
                    ->label(__('Max risk'))
                    ->badge()
                    ->formatStateUsing(fn (?AiToolRisk $state): string => $state?->label() ?? '')
                    ->color(fn (?AiToolRisk $state): string => PanelColor::for($state?->color())),
                TextColumn::make('denied_tools')
                    ->label(__('Denied'))
                    ->state(fn (AiPolicy $record): int => count($record->denied_tools ?? []))
                    ->alignEnd(),
                TextColumn::make('priority')
                    ->label(__('Priority'))
                    ->alignEnd()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('Active'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('workspace_id')
                    ->label(__('Workspace'))
                    ->relationship('workspace', 'name')
                    ->searchable(),
                SelectFilter::make('max_risk')
                    ->label(__('Maximum risk'))
                    ->options(self::riskOptions()),
                TernaryFilter::make('is_active')
                    ->label(__('Active')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription(__('Runs governed by this rule fall back to the next most specific one, or to the installation defaults in config/ai.php.')),
            ])
            ->toolbarActions([])
            ->emptyStateIcon(Heroicon::OutlinedShieldCheck)
            ->emptyStateHeading(__('No AI policies'))
            ->emptyStateDescription(__('Without a policy, the defaults in config/ai.php apply: every mutation above medium risk waits for a human.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiPolicies::route('/'),
            'create' => CreateAiPolicy::route('/create'),
            'edit' => EditAiPolicy::route('/{record}/edit'),
        ];
    }

    /**
     * @return Builder<AiPolicy>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['workspace', 'project']);
    }

    /* ------------------------------------------------------------------ *
     * Options
     * ------------------------------------------------------------------ */

    /**
     * Every registered tool, grouped the way the registry groups them, each labelled with the
     * risk it carries — which is the fact somebody writing a policy is actually deciding on.
     *
     * @return array<string, array<string, string>>
     */
    private static function toolOptions(): array
    {
        $options = [];

        foreach (app(ToolRegistry::class)->all() as $tool) {
            $options[$tool->group()][$tool->name()] = $tool->name().' — '.$tool->risk()->label();
        }

        ksort($options);

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private static function projectOptions(mixed $workspaceId): array
    {
        if (blank($workspaceId)) {
            return [];
        }

        return Project::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function modeOptions(): array
    {
        $options = [];

        foreach (AiMode::cases() as $mode) {
            $options[$mode->value] = $mode->label();
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function riskOptions(): array
    {
        $options = [];

        foreach (AiToolRisk::cases() as $risk) {
            $options[$risk->value] = $risk->label();
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function roleOptions(): array
    {
        $options = [];

        foreach (WorkspaceRole::cases() as $role) {
            $options[$role->value] = $role->label();
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    private static function alwaysApproved(): array
    {
        $tools = config('ai.approvals.always_require_approval', []);

        return is_array($tools) ? array_values(array_filter($tools, is_string(...))) : [];
    }
}

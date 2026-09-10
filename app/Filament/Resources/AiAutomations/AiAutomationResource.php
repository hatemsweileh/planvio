<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiAutomations;

use App\Enums\AiMode;
use App\Enums\AutomationTrigger;
use App\Enums\WorkspaceRole;
use App\Filament\Resources\AiAutomations\Pages\CreateAiAutomation;
use App\Filament\Resources\AiAutomations\Pages\EditAiAutomation;
use App\Filament\Resources\AiAutomations\Pages\ListAiAutomations;
use App\Filament\Support\AdminAudit;
use App\Filament\Support\NavigationGroup;
use App\Filament\Support\PanelColor;
use App\Filament\Support\PlatformResource;
use App\Models\AiAutomation;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use BackedEnum;
use Closure;
use Cron\CronExpression;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;
use UnitEnum;

/**
 * Standing instructions: an objective the agent pursues on a schedule or on an event.
 *
 * # "Runs as" is the whole security model of this screen
 *
 * `App\Ai\Automations\AutomationRunner` runs an automation under the authority of `created_by`
 * and nobody else — there is no service account and no elevated path. Every tick re-asks the
 * `AiGate` for that person, so an automation stops the moment its author loses `ai.use`, is
 * deactivated, or leaves the workspace.
 *
 * That is why the field is a visible choice here rather than "whoever is signed in": a platform
 * administrator is not a member of most workspaces, and an automation created under their name
 * would either do nothing or, worse, imply a standing authority they do not hold. The list is
 * therefore restricted to members of the chosen workspace, and the agent can never do more than
 * that person could do by hand.
 *
 * # The schedule
 *
 * `next_run_at` is what `AutomationRunner` actually looks at, so it is recalculated whenever the
 * expression, the trigger type or the active flag changes here — an automation saved without it
 * would sit in the table looking correct and never fire. The runner recalculates it after every
 * run using the same rule.
 *
 * @extends PlatformResource<AiAutomation>
 */
final class AiAutomationResource extends PlatformResource
{
    protected static ?string $model = AiAutomation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Ai;

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('Automations');
    }

    public static function getModelLabel(): string
    {
        return __('AI automation');
    }

    public static function getPluralModelLabel(): string
    {
        return __('AI automations');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('What it is'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label(__('Name'))
                        ->required()
                        ->maxLength(255),
                    Select::make('mode')
                        ->label(__('Mode'))
                        ->options(self::modeOptions())
                        ->default(AiMode::Copilot->value)
                        ->required()
                        ->helperText(__('Copilot proposes each change for approval. Autonomous acts within the policy limits.')),
                    Textarea::make('description')
                        ->label(__('Description'))
                        ->rows(2)
                        ->maxLength(1000)
                        ->columnSpanFull(),
                    Textarea::make('objective')
                        ->label(__('Objective'))
                        ->required()
                        ->rows(4)
                        ->columnSpanFull()
                        ->helperText(__('Written for the agent, in plain language. This is the standing instruction it pursues each time it runs.')),
                ]),

            Section::make(__('Where it acts, and as whom'))
                ->columns(2)
                ->schema([
                    Select::make('workspace_id')
                        ->label(__('Workspace'))
                        ->options(fn (): array => Workspace::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->required()
                        ->live(),
                    Select::make('project_id')
                        ->label(__('Project'))
                        ->options(fn (Get $get): array => self::projectOptions($get('workspace_id')))
                        ->searchable()
                        ->placeholder(__('Whole workspace'))
                        ->disabled(fn (Get $get): bool => blank($get('workspace_id'))),
                    Select::make('created_by')
                        ->label(__('Runs as'))
                        ->options(fn (Get $get): array => self::memberOptions($get('workspace_id')))
                        ->searchable()
                        ->required()
                        ->disabled(fn (Get $get): bool => blank($get('workspace_id')))
                        ->columnSpanFull()
                        ->helperText(__('The automation can never do more than this person could do by hand, and it stops if they lose access. Only members of the chosen workspace are listed.')),
                ]),

            Section::make(__('When it runs'))
                ->columns(2)
                ->schema([
                    Select::make('trigger_type')
                        ->label(__('Trigger'))
                        ->options(self::triggerOptions())
                        ->default(AutomationTrigger::Schedule->value)
                        ->required()
                        ->live(),
                    TextInput::make('schedule_cron')
                        ->label(__('Schedule'))
                        ->maxLength(64)
                        ->placeholder('0 8 * * 1')
                        ->visible(fn (Get $get): bool => $get('trigger_type') === AutomationTrigger::Schedule->value)
                        ->required(fn (Get $get): bool => $get('trigger_type') === AutomationTrigger::Schedule->value)
                        ->rule(self::cronRule())
                        ->helperText(__('Standard five-field cron, or an alias such as @daily. Interpreted in the workspace timezone.')),
                    TextInput::make('event')
                        ->label(__('Event'))
                        ->maxLength(64)
                        ->visible(fn (Get $get): bool => $get('trigger_type') === AutomationTrigger::Event->value)
                        ->required(fn (Get $get): bool => $get('trigger_type') === AutomationTrigger::Event->value)
                        ->helperText(__('The domain event name that triggers this automation, e.g. task.completed.')),
                    Toggle::make('is_active')
                        ->label(__('Active'))
                        ->helperText(__('Inactive automations are skipped by the scheduler and keep their history.')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Automation'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (AiAutomation $record): ?string => $record->description),
                TextColumn::make('workspace.name')
                    ->label(__('Workspace'))
                    ->searchable()
                    ->description(fn (AiAutomation $record): ?string => $record->project?->name),
                TextColumn::make('creator.name')
                    ->label(__('Runs as'))
                    ->placeholder(__('Author gone')),
                TextColumn::make('trigger_type')
                    ->label(__('Trigger'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?AutomationTrigger $state): string => $state?->label() ?? '')
                    ->description(fn (AiAutomation $record): ?string => $record->isScheduled()
                        ? $record->schedule_cron
                        : $record->event),
                TextColumn::make('mode')
                    ->label(__('Mode'))
                    ->badge()
                    ->formatStateUsing(fn (?AiMode $state): string => $state?->label() ?? '')
                    ->color(fn (?AiMode $state): string => PanelColor::for($state?->color())),
                TextColumn::make('last_run_at')
                    ->label(__('Last run'))
                    ->dateTime()
                    ->since()
                    ->placeholder(__('Never'))
                    ->description(fn (AiAutomation $record): ?string => $record->last_run_status)
                    ->sortable(),
                TextColumn::make('next_run_at')
                    ->label(__('Next run'))
                    ->dateTime()
                    ->placeholder(__('Not scheduled'))
                    ->sortable(),
                TextColumn::make('failure_count')
                    ->label(__('Failures'))
                    ->alignEnd()
                    ->color(fn (AiAutomation $record): string => (int) $record->failure_count > 0 ? 'danger' : 'gray')
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
                SelectFilter::make('trigger_type')
                    ->label(__('Trigger'))
                    ->options(self::triggerOptions()),
                TernaryFilter::make('is_active')
                    ->label(__('Active')),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    self::releaseLockAction(),
                    DeleteAction::make()
                        ->modalDescription(__('The automation stops. Runs it has already produced stay in the AI run log.')),
                ]),
            ])
            ->toolbarActions([])
            ->emptyStateIcon(Heroicon::OutlinedBolt)
            ->emptyStateHeading(__('No automations'))
            ->emptyStateDescription(__('Automations are usually created by workspace owners from the product. They can also be reviewed and stopped from here.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiAutomations::route('/'),
            'create' => CreateAiAutomation::route('/create'),
            'edit' => EditAiAutomation::route('/{record}/edit'),
        ];
    }

    /**
     * @return Builder<AiAutomation>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['workspace', 'project', 'creator']);
    }

    /* ------------------------------------------------------------------ *
     * Scheduling
     * ------------------------------------------------------------------ */

    /**
     * Put `next_run_at` where `AutomationRunner` will find it.
     *
     * Mirrors the runner's own rule: only a scheduled trigger has a next run, the expression is
     * read in the workspace's timezone, and an expression the parser refuses produces null —
     * a broken schedule drops out of the due list without silently deactivating the automation,
     * so it can be fixed by editing the expression alone.
     */
    public static function scheduleNextRun(AiAutomation $automation): void
    {
        $next = self::nextRunAt($automation);

        if (self::sameInstant($automation->next_run_at, $next)) {
            return;
        }

        AiAutomation::withoutWorkspaceScope()
            ->whereKey($automation->getKey())
            ->update(['next_run_at' => $next]);

        $automation->forceFill(['next_run_at' => $next])->syncOriginal();
    }

    private static function nextRunAt(AiAutomation $automation): ?Carbon
    {
        if ($automation->trigger_type !== AutomationTrigger::Schedule || ! $automation->is_active) {
            return null;
        }

        $expression = is_string($automation->schedule_cron) ? trim($automation->schedule_cron) : '';

        if ($expression === '' || ! CronExpression::isValidExpression($expression)) {
            return null;
        }

        $timezone = self::timezoneOf($automation);

        try {
            $next = (new CronExpression($expression))->getNextRunDate(
                Carbon::now()->setTimezone($timezone),
                0,
                false,
                $timezone,
            );
        } catch (Throwable) {
            return null;
        }

        return Carbon::instance($next)->setTimezone((string) config('app.timezone', 'UTC'));
    }

    /**
     * `workspaces.timezone` is free text, so a typo there must not take the scheduler down.
     */
    private static function timezoneOf(AiAutomation $automation): string
    {
        $timezone = $automation->workspace?->timezone;

        if (! is_string($timezone) || $timezone === '') {
            return 'UTC';
        }

        try {
            Carbon::now($timezone);

            return $timezone;
        } catch (Throwable) {
            return 'UTC';
        }
    }

    private static function sameInstant(?Carbon $a, ?Carbon $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return $a->equalTo($b);
    }

    /* ------------------------------------------------------------------ *
     * Row actions
     * ------------------------------------------------------------------ */

    /**
     * Release a stale overlap lock.
     *
     * An automation takes a database lock before it runs and releases it when it finishes. A
     * process killed mid-run — a PHP time limit on shared hosting is the usual cause — leaves
     * the lock behind, and the automation is skipped until it expires. This is the manual way
     * out for somebody who does not want to wait out `config('ai.automations.lock_ttl_seconds')`.
     */
    private static function releaseLockAction(): Action
    {
        return Action::make('releaseLock')
            ->label(__('Release lock'))
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(fn (AiAutomation $record): string => __('Release the lock on :name?', ['name' => $record->name]))
            ->modalDescription(__('Only do this if you are sure no run is still in progress. Releasing a live lock lets a second run start against the same objective, which for a mutating automation means the work happens twice.'))
            ->visible(fn (AiAutomation $record): bool => $record->isLocked())
            ->action(function (AiAutomation $record): void {
                AiAutomation::withoutWorkspaceScope()
                    ->whereKey($record->getKey())
                    ->update(['lock_token' => null, 'locked_until' => null]);

                $record->forceFill(['lock_token' => null, 'locked_until' => null])->syncOriginal();

                AdminAudit::record(
                    'admin.ai_automation_lock_released',
                    __('Automation overlap lock released from the administration panel.'),
                    properties: [
                        'automation_id' => (int) $record->getKey(),
                        'automation' => (string) $record->name,
                    ],
                    workspaceId: (int) $record->workspace_id,
                );

                Notification::make()
                    ->title(__('Lock released.'))
                    ->body(__(':name will be considered on the next scheduler tick.', ['name' => $record->name]))
                    ->success()
                    ->send();
            });
    }

    /* ------------------------------------------------------------------ *
     * Options
     * ------------------------------------------------------------------ */

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
     * Members of the workspace, with the role they hold — the role is what determines what the
     * automation will actually be permitted to do.
     *
     * @return array<int, string>
     */
    private static function memberOptions(mixed $workspaceId): array
    {
        if (blank($workspaceId)) {
            return [];
        }

        $memberships = WorkspaceMember::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->with('user')
            ->get();

        $options = [];

        foreach ($memberships as $membership) {
            $user = $membership->user;

            if (! $user instanceof User || ! $user->is_active) {
                continue;
            }

            $role = $membership->role instanceof WorkspaceRole ? $membership->role->label() : '';

            $options[(int) $user->getKey()] = trim($user->name.' — '.$role, ' —');
        }

        asort($options);

        return $options;
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
    private static function triggerOptions(): array
    {
        $options = [];

        foreach (AutomationTrigger::cases() as $trigger) {
            $options[$trigger->value] = $trigger->label();
        }

        return $options;
    }

    /**
     * A cron expression the parser refuses is a schedule that can never fire, so it is refused
     * here rather than saved and quietly ignored.
     *
     * The outer closure is what Filament wants: a closure handed to `rule()` is *evaluated* and
     * its return value becomes the rule, so the Laravel closure rule has to be returned rather
     * than passed.
     */
    private static function cronRule(): Closure
    {
        return static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            if (! CronExpression::isValidExpression(trim($value))) {
                $fail(__('That is not a cron expression Planvio can schedule.'));
            }
        };
    }
}

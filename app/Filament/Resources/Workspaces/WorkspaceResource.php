<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workspaces;

use App\Actions\Members\ChangeMemberRole;
use App\Actions\Workspaces\DeleteWorkspace;
use App\Actions\Workspaces\UpdateWorkspace;
use App\Actions\Workspaces\WorkspaceAttributes;
use App\Enums\WorkspaceRole;
use App\Filament\Resources\Workspaces\Pages\EditWorkspace;
use App\Filament\Resources\Workspaces\Pages\ListWorkspaces;
use App\Filament\Support\AdminAudit;
use App\Filament\Support\NavigationGroup;
use App\Filament\Support\PlatformResource;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use BackedEnum;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Tenants.
 *
 * The three controls here are the ones nobody inside a workspace can perform for themselves:
 * closing a tenant, moving its ownership when the owner has gone, and removing it.
 *
 * # Deleting is a soft delete, and the panel says what that means
 *
 * `App\Actions\Workspaces\DeleteWorkspace` suspends the workspace and soft-deletes the row.
 * Nothing beneath it is touched — every tenant-scoped table carries `workspace_id` with
 * `cascadeOnDelete`, so a hard delete would take the entire tenant with it in one statement.
 * The confirmation therefore states the real blast radius, counted from the database at the
 * moment it is opened: what stops resolving, and what is kept.
 *
 * @extends PlatformResource<Workspace>
 */
final class WorkspaceResource extends PlatformResource
{
    protected static ?string $model = Workspace::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Platform;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('Workspaces');
    }

    public static function getModelLabel(): string
    {
        return __('workspace');
    }

    public static function getPluralModelLabel(): string
    {
        return __('workspaces');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Identity'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label(__('Name'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('slug')
                        ->label(__('Slug'))
                        ->helperText(__('Appears in every workspace URL. Changing it breaks existing links.'))
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),
                    Textarea::make('description')
                        ->label(__('Description'))
                        ->rows(2)
                        ->columnSpanFull()
                        ->maxLength(1000),
                ]),

            Section::make(__('Regional defaults'))
                ->description(__('Applied to new projects, reports and reminder scheduling inside this workspace.'))
                ->columns(3)
                ->schema([
                    Select::make('timezone')
                        ->label(__('Timezone'))
                        ->options(self::timezones())
                        ->searchable()
                        ->required(),
                    TextInput::make('locale')
                        ->label(__('Language'))
                        ->required()
                        ->maxLength(8),
                    TextInput::make('currency')
                        ->label(__('Currency'))
                        ->helperText(__('Three-letter ISO code, e.g. USD.'))
                        ->required()
                        ->length(3),
                    TextInput::make('date_format')
                        ->label(__('Date format'))
                        ->helperText(__('PHP date format, e.g. Y-m-d.'))
                        ->required()
                        ->maxLength(32),
                    Select::make('week_starts_on')
                        ->label(__('Week starts on'))
                        ->options([
                            0 => __('Sunday'),
                            1 => __('Monday'),
                            6 => __('Saturday'),
                        ])
                        ->required(),
                    ColorPicker::make('accent_color')
                        ->label(__('Accent colour')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Workspace'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Workspace $record): string => '/w/'.$record->slug),
                TextColumn::make('owner.name')
                    ->label(__('Owner'))
                    ->searchable()
                    ->placeholder(__('Nobody')),
                TextColumn::make('memberships_count')
                    ->label(__('Members'))
                    ->counts('memberships')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('projects_count')
                    ->label(__('Projects'))
                    ->counts('projects')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('is_suspended')
                    ->label(__('Status'))
                    ->badge()
                    ->state(fn (Workspace $record): string => match (true) {
                        $record->trashed() => __('Deleted'),
                        (bool) $record->is_suspended => __('Suspended'),
                        default => __('Active'),
                    })
                    ->color(fn (Workspace $record): string => match (true) {
                        $record->trashed() => 'danger',
                        (bool) $record->is_suspended => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('created_at')
                    ->label(__('Created'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_suspended')
                    ->label(__('Suspended'))
                    ->placeholder(__('All workspaces'))
                    ->trueLabel(__('Suspended only'))
                    ->falseLabel(__('Active only')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    self::suspensionAction(),
                    self::transferOwnershipAction(),
                    self::deleteAction(),
                    self::restoreAction(),
                ]),
            ])
            ->toolbarActions([])
            ->emptyStateIcon(Heroicon::OutlinedBuildingOffice)
            ->emptyStateHeading(__('No workspaces match this filter'))
            ->emptyStateDescription(__('Workspaces are created from the product, not from here.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkspaces::route('/'),
            'edit' => EditWorkspace::route('/{record}/edit'),
        ];
    }

    /**
     * @return Builder<Workspace>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with('owner');
    }

    /* ------------------------------------------------------------------ *
     * Row actions
     * ------------------------------------------------------------------ */

    /**
     * Suspend closes the tenant to its members without deleting anything. It is the reversible
     * half of "make this stop" — non-payment, an investigation, a customer asking for a pause.
     */
    private static function suspensionAction(): Action
    {
        return Action::make('toggleSuspension')
            ->label(fn (Workspace $record): string => $record->is_suspended ? __('Lift suspension') : __('Suspend'))
            ->icon(fn (Workspace $record): Heroicon => $record->is_suspended
                ? Heroicon::OutlinedPlay
                : Heroicon::OutlinedPause)
            ->color(fn (Workspace $record): string => $record->is_suspended ? 'success' : 'warning')
            ->requiresConfirmation()
            ->modalHeading(fn (Workspace $record): string => $record->is_suspended
                ? __('Lift the suspension on :name?', ['name' => $record->name])
                : __('Suspend :name?', ['name' => $record->name]))
            ->modalDescription(fn (Workspace $record): string => $record->is_suspended
                ? __('Members can open the workspace again immediately. Nothing else changes.')
                : __('Members lose access to every project, task and file in this workspace until the suspension is lifted. Nothing is deleted, and scheduled work for the workspace stops.'))
            ->visible(fn (Workspace $record): bool => ! $record->trashed())
            ->action(function (Workspace $record): void {
                $suspending = ! $record->is_suspended;

                app(UpdateWorkspace::class)(
                    $record,
                    new WorkspaceAttributes(isSuspended: $suspending),
                    self::administrator(),
                );

                AdminAudit::record(
                    $suspending ? 'admin.workspace_suspended' : 'admin.workspace_unsuspended',
                    $suspending
                        ? __('Workspace suspended from the administration panel.')
                        : __('Workspace suspension lifted from the administration panel.'),
                    properties: ['workspace' => $record->slug],
                    workspaceId: (int) $record->getKey(),
                );

                Notification::make()
                    ->title($suspending
                        ? __(':name is suspended.', ['name' => $record->name])
                        : __(':name is open again.', ['name' => $record->name]))
                    ->success()
                    ->send();
            });
    }

    /**
     * Move `workspaces.owner_id`, and make the new owner a member holding the owner role.
     *
     * Both halves matter. The column is what "contact the owner" reads; the membership row is
     * what authorization reads. Setting one without the other produces a workspace whose stated
     * owner cannot open it, or an owner nobody can see in the member list.
     *
     * Somebody who is not yet a member can be chosen — that is the case this action exists for,
     * an owner whose account is gone — and they are added, visibly, as a member. There is no
     * path here that grants access without leaving a row in `workspace_members`.
     */
    private static function transferOwnershipAction(): Action
    {
        return Action::make('transferOwnership')
            ->label(__('Transfer ownership'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('primary')
            ->visible(fn (Workspace $record): bool => ! $record->trashed())
            ->modalHeading(fn (Workspace $record): string => __('Transfer ownership of :name', ['name' => $record->name]))
            ->modalDescription(__('The chosen account becomes an owner of this workspace and is recorded as its owner. If they are not a member yet, they are added as one.'))
            ->modalSubmitActionLabel(__('Transfer ownership'))
            ->schema([
                Select::make('user_id')
                    ->label(__('New owner'))
                    ->options(fn (): array => User::query()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->limit(500)
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required()
                    ->helperText(__('Deactivated accounts are not offered: a workspace owned by an account that cannot sign in is the problem this action exists to fix.')),
            ])
            ->action(function (Workspace $record, array $data): void {
                $newOwner = User::query()->find($data['user_id'] ?? null);

                if (! $newOwner instanceof User) {
                    Notification::make()
                        ->title(__('That account no longer exists.'))
                        ->danger()
                        ->send();

                    return;
                }

                $previousOwnerId = $record->owner_id === null ? null : (int) $record->owner_id;
                $actor = self::administrator();

                DB::transaction(function () use ($record, $newOwner, $actor): void {
                    $membership = WorkspaceMember::withoutWorkspaceScope()
                        ->where('workspace_id', $record->getKey())
                        ->where('user_id', $newOwner->getKey())
                        ->first();

                    if ($membership === null) {
                        $record->addMember($newOwner, WorkspaceRole::Owner);
                    } else {
                        app(ChangeMemberRole::class)($record, $newOwner, WorkspaceRole::Owner, $actor);
                    }

                    $record->owner_id = $newOwner->getKey();
                    $record->save();
                });

                AdminAudit::record(
                    'admin.workspace_ownership_transferred',
                    __('Workspace ownership transferred from the administration panel.'),
                    $newOwner,
                    [
                        'workspace' => $record->slug,
                        'previous_owner_id' => $previousOwnerId,
                    ],
                    (int) $record->getKey(),
                );

                Notification::make()
                    ->title(__(':name now owns :workspace.', [
                        'name' => $newOwner->name,
                        'workspace' => $record->name,
                    ]))
                    ->success()
                    ->send();
            });
    }

    /**
     * The blast radius is counted when the modal opens, not described in the abstract: an
     * administrator deciding whether to remove a tenant needs the actual numbers.
     */
    private static function deleteAction(): Action
    {
        return Action::make('deleteWorkspace')
            ->label(__('Delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (Workspace $record): string => __('Delete :name?', ['name' => $record->name]))
            ->modalDescription(fn (Workspace $record): string => self::blastRadius($record))
            ->modalSubmitActionLabel(__('Delete the workspace'))
            ->visible(fn (Workspace $record): bool => ! $record->trashed())
            ->action(function (Workspace $record): void {
                app(DeleteWorkspace::class)($record, self::administrator());

                AdminAudit::record(
                    'admin.workspace_deleted',
                    __('Workspace deleted from the administration panel.'),
                    properties: ['workspace' => $record->slug, 'name' => $record->name],
                    workspaceId: (int) $record->getKey(),
                );

                Notification::make()
                    ->title(__(':name has been deleted.', ['name' => $record->name]))
                    ->body(__('Its records are retained. Use the Deleted filter to restore it.'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Restore brings the row back suspended, which is what the delete left it as. Reopening a
     * tenant is a second, deliberate decision — its members should not find themselves back
     * inside a workspace because somebody was undoing a mistake.
     */
    private static function restoreAction(): Action
    {
        return Action::make('restoreWorkspace')
            ->label(__('Restore'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(fn (Workspace $record): string => __('Restore :name?', ['name' => $record->name]))
            ->modalDescription(__('The workspace comes back suspended, with every project, task and file exactly as it was. Lift the suspension when you are ready to let its members in.'))
            ->visible(fn (Workspace $record): bool => $record->trashed())
            ->action(function (Workspace $record): void {
                $record->restore();

                AdminAudit::record(
                    'admin.workspace_restored',
                    __('Workspace restored from the administration panel.'),
                    properties: ['workspace' => $record->slug],
                    workspaceId: (int) $record->getKey(),
                );

                Notification::make()
                    ->title(__(':name has been restored, still suspended.', ['name' => $record->name]))
                    ->success()
                    ->send();
            });
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    /**
     * What actually happens, in numbers read at the moment of asking.
     */
    private static function blastRadius(Workspace $record): string
    {
        $counts = Workspace::withoutGlobalScopes([SoftDeletingScope::class])
            ->whereKey($record->getKey())
            ->withCount(['memberships', 'projects', 'tasks'])
            ->first();

        return __('This closes the workspace to its :members members and takes :projects projects and :tasks tasks out of reach. Nothing is erased: every record is retained and the workspace can be restored from the Deleted filter.', [
            'members' => (int) ($counts?->memberships_count ?? 0),
            'projects' => (int) ($counts?->projects_count ?? 0),
            'tasks' => (int) ($counts?->tasks_count ?? 0),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function timezones(): array
    {
        $identifiers = DateTimeZone::listIdentifiers();

        return array_combine($identifiers, $identifiers);
    }
}

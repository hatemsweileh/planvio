<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Support\AdminAudit;
use App\Filament\Support\NavigationGroup;
use App\Filament\Support\PlatformResource;
use App\Models\Locale;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Services\TwoFactorService;
use BackedEnum;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;
use UnitEnum;

/**
 * Accounts.
 *
 * # Impersonation is deliberately absent
 *
 * Every self-hosted admin panel eventually grows a "sign in as this user" button, and it is
 * not here on purpose. Planvio's whole authorization story is that a platform administrator
 * is *not* automatically inside a tenant: `Gate::before` declines to answer for a workspace
 * they never joined, precisely so that "who could see this workspace" has a truthful answer in
 * `workspace_members` (ARCHITECTURE.md §4.2). Impersonation would drive a hole straight
 * through that: an administrator would read a customer's tasks, comments and attachments while
 * every record produced by the session carried the customer's own user id. The audit trail
 * would then say the customer did it, which is worse than having no trail.
 *
 * The supported route into a workspace is to be added to it, which leaves a row.
 *
 * # Deletes are soft, and force-delete is not offered
 *
 * `users` is referenced by roughly thirty tables — authored comments, logged time, approved
 * tool runs, audit entries. Those relations null out rather than cascade, so a hard delete
 * would silently strip authorship from history that exists to be read later. A soft delete
 * removes the account from every list and blocks sign-in while leaving the trail intact, and
 * it is reversible.
 *
 * @extends PlatformResource<User>
 */
final class UserResource extends PlatformResource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Platform;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('Users');
    }

    public static function getModelLabel(): string
    {
        return __('user');
    }

    public static function getPluralModelLabel(): string
    {
        return __('users');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Identity'))
                ->description(__('The name and address the rest of Planvio addresses this person by.'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label(__('Name'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('email')
                        ->label(__('Email address'))
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),
                    TextInput::make('job_title')
                        ->label(__('Job title'))
                        ->maxLength(255),
                ]),

            Section::make(__('Password'))
                ->description(fn (?User $record): string => $record === null
                    ? StrongPassword::description()
                    : __('Leave blank to keep the current password. :requirements', [
                        'requirements' => StrongPassword::description(),
                    ]))
                ->schema([
                    TextInput::make('password')
                        ->label(__('Password'))
                        ->password()
                        ->revealable()
                        ->rule(StrongPassword::rule())
                        ->required(fn (string $operation): bool => $operation === 'create')
                        // An empty field on edit must not blank the column: the value is
                        // dropped from the payload entirely rather than saved as ''.
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->maxLength(StrongPassword::MAX_LENGTH)
                        ->autocomplete('new-password'),
                ]),

            Section::make(__('Access'))
                ->description(__('Platform administrators reach this panel. They are still not members of any workspace until they are added to one.'))
                ->columns(2)
                ->schema([
                    Toggle::make('is_active')
                        ->label(__('Active'))
                        ->helperText(__('An inactive account cannot sign in and cannot request a password reset.'))
                        ->default(true)
                        ->disabled(fn (?User $record): bool => self::isSelf($record))
                        ->dehydrated(fn (?User $record): bool => ! self::isSelf($record)),
                    Toggle::make('is_admin')
                        ->label(__('Platform administrator'))
                        ->helperText(__('Full access to this panel, including AI credentials and every workspace record.'))
                        ->disabled(fn (?User $record): bool => self::isSelf($record))
                        ->dehydrated(fn (?User $record): bool => ! self::isSelf($record)),
                ]),

            Section::make(__('Preferences'))
                ->description(__('Defaults for this account. The person can change all three themselves.'))
                ->columns(3)
                ->schema([
                    Select::make('timezone')
                        ->label(__('Timezone'))
                        ->options(self::timezones())
                        ->searchable()
                        ->required()
                        ->default(config('planvio.defaults.workspace.timezone', 'UTC')),
                    Select::make('locale')
                        ->label(__('Language'))
                        ->options(self::locales())
                        ->required()
                        ->default(config('planvio.defaults.workspace.locale', 'en')),
                    Select::make('theme')
                        ->label(__('Theme'))
                        ->options([
                            'system' => __('Match the device'),
                            'light' => __('Light'),
                            'dark' => __('Dark'),
                        ])
                        ->required()
                        ->default('system'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (User $record): ?string => $record->job_title),
                TextColumn::make('email')
                    ->label(__('Email'))
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('is_admin')
                    ->label(__('Role'))
                    ->badge()
                    ->state(fn (User $record): string => $record->is_admin
                        ? __('Platform admin')
                        : __('Member'))
                    ->color(fn (User $record): string => $record->is_admin ? 'primary' : 'gray'),
                TextColumn::make('is_active')
                    ->label(__('Status'))
                    ->badge()
                    ->state(fn (User $record): string => match (true) {
                        $record->trashed() => __('Deleted'),
                        (bool) $record->is_active => __('Active'),
                        default => __('Deactivated'),
                    })
                    ->color(fn (User $record): string => match (true) {
                        $record->trashed() => 'danger',
                        (bool) $record->is_active => 'success',
                        default => 'warning',
                    }),
                IconColumn::make('two_factor_confirmed_at')
                    ->label(__('2FA'))
                    ->boolean()
                    ->state(fn (User $record): bool => $record->hasTwoFactorEnabled())
                    ->trueIcon(Heroicon::OutlinedShieldCheck)
                    ->falseIcon(Heroicon::OutlinedShieldExclamation)
                    ->trueColor('success')
                    ->falseColor('gray'),
                TextColumn::make('workspace_memberships_count')
                    ->label(__('Workspaces'))
                    ->counts('workspaceMemberships')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('last_login_at')
                    ->label(__('Last sign-in'))
                    ->dateTime()
                    ->since()
                    ->placeholder(__('Never'))
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('Created'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('Active'))
                    ->placeholder(__('Everyone'))
                    ->trueLabel(__('Active only'))
                    ->falseLabel(__('Deactivated only')),
                TernaryFilter::make('is_admin')
                    ->label(__('Platform administrator'))
                    ->placeholder(__('Everyone'))
                    ->trueLabel(__('Administrators only'))
                    ->falseLabel(__('Everyone else')),
                Filter::make('without_two_factor')
                    ->label(__('Two-factor not enabled'))
                    ->query(fn (Builder $query): Builder => $query->whereNull('two_factor_confirmed_at')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    self::activationAction(),
                    self::passwordResetAction(),
                    self::twoFactorResetAction(),
                    DeleteAction::make()
                        ->modalDescription(__('The account is deactivated and hidden everywhere. Its comments, logged time and audit entries are kept, and the account can be restored.'))
                        ->visible(fn (User $record): bool => ! self::isSelf($record) && ! self::isLastAdministrator($record)),
                    RestoreAction::make(),
                ]),
            ])
            ->toolbarActions([])
            ->emptyStateIcon(Heroicon::OutlinedUsers)
            ->emptyStateHeading(__('No accounts match this filter'))
            ->emptyStateDescription(__('Clear the filters, or create the first account.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    /**
     * @return Builder<User>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /* ------------------------------------------------------------------ *
     * Row actions
     * ------------------------------------------------------------------ */

    /**
     * Deactivate, or put back. One control rather than two, because they are one decision.
     *
     * An administrator cannot deactivate themselves, and the last remaining active
     * administrator cannot be deactivated at all: an installation with nobody able to reach
     * `/admin` can only be repaired from a database console, which on the shared hosting
     * Planvio targets frequently means not at all.
     */
    private static function activationAction(): Action
    {
        return Action::make('toggleActive')
            ->label(fn (User $record): string => $record->is_active ? __('Deactivate') : __('Activate'))
            ->icon(fn (User $record): Heroicon => $record->is_active
                ? Heroicon::OutlinedLockClosed
                : Heroicon::OutlinedCheckCircle)
            ->color(fn (User $record): string => $record->is_active ? 'warning' : 'success')
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => $record->is_active
                ? __('Deactivate :name?', ['name' => $record->name])
                : __('Activate :name?', ['name' => $record->name]))
            ->modalDescription(fn (User $record): string => $record->is_active
                ? __('They will be signed out and unable to sign in again or request a password reset. Their work stays where it is.')
                : __('They will be able to sign in again with their existing password.'))
            ->visible(fn (User $record): bool => ! $record->trashed()
                && ! self::isSelf($record)
                && ! ($record->is_active && self::isLastAdministrator($record)))
            ->action(function (User $record): void {
                $activating = ! $record->is_active;

                $record->is_active = $activating;
                $record->save();

                if (! $activating) {
                    self::revokeSessions($record);
                }

                AdminAudit::record(
                    $activating ? 'admin.user_activated' : 'admin.user_deactivated',
                    $activating
                        ? __('Account activated from the administration panel.')
                        : __('Account deactivated from the administration panel.'),
                    $record,
                );

                Notification::make()
                    ->title($activating
                        ? __(':name can sign in again.', ['name' => $record->name])
                        : __(':name has been deactivated.', ['name' => $record->name]))
                    ->success()
                    ->send();
            });
    }

    /**
     * Force a password reset: invalidate what they have, sign them out, email them a link.
     *
     * Planvio has no "must change password" column — the schema is fixed (ARCHITECTURE.md §5)
     * and a flag nothing enforces would be theatre. Replacing the hash with an unguessable
     * value is what actually forces the reset: the old password stops working at that instant,
     * and the only way back in is the emailed link.
     *
     * That makes working mail a precondition, which is why the confirmation says so plainly.
     * If the message never arrives the administrator can trigger it again; nothing is lost.
     */
    private static function passwordResetAction(): Action
    {
        return Action::make('forcePasswordReset')
            ->label(__('Force password reset'))
            ->icon(Heroicon::OutlinedKey)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => __('Force a password reset for :name?', ['name' => $record->name]))
            ->modalDescription(__('Their current password stops working immediately and every session is signed out. A reset link is emailed to them; they cannot sign in until they use it. Only do this if outgoing email is working.'))
            ->modalSubmitActionLabel(__('Reset and send the link'))
            ->visible(fn (User $record): bool => ! $record->trashed() && ! self::isSelf($record))
            ->action(function (User $record): void {
                // The `hashed` cast turns this into a bcrypt hash on save. The plaintext is
                // never stored, never logged and never shown — nobody is meant to know it.
                $record->forceFill([
                    'password' => Str::password(64),
                    'remember_token' => Str::random(60),
                ])->save();

                self::revokeSessions($record);

                $status = $record->is_active
                    ? Password::broker()->sendResetLink(['email' => $record->email, 'is_active' => true])
                    : 'passwords.user';

                AdminAudit::record(
                    'admin.user_password_reset_forced',
                    __('Password invalidated and a reset link requested from the administration panel.'),
                    $record,
                    ['outcome' => $status],
                );

                $sent = $status === Password::RESET_LINK_SENT;

                Notification::make()
                    ->title($sent
                        ? __('Reset link sent to :email.', ['email' => $record->email])
                        : __('The password was invalidated, but no link could be sent.'))
                    ->body($sent
                        ? __(':name cannot sign in until they use it.', ['name' => $record->name])
                        : __('Check the mail configuration under System, then use this action again. :name cannot sign in until a link reaches them.', ['name' => $record->name]))
                    ->status($sent ? 'success' : 'warning')
                    ->persistent()
                    ->send();
            });
    }

    /**
     * Clear two-factor authentication for somebody who has lost both their device and their
     * recovery codes. The account keeps its password; it simply stops being challenged.
     */
    private static function twoFactorResetAction(): Action
    {
        return Action::make('resetTwoFactor')
            ->label(__('Reset two-factor'))
            ->icon(Heroicon::OutlinedShieldExclamation)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => __('Reset two-factor for :name?', ['name' => $record->name]))
            ->modalDescription(__('Their authenticator secret and recovery codes are destroyed, and the account signs in with its password alone until they set it up again. Confirm who you are talking to first: this is the step an attacker asks for.'))
            ->modalSubmitActionLabel(__('Reset two-factor'))
            ->visible(fn (User $record): bool => ! $record->trashed() && $record->hasTwoFactorEnabled())
            ->action(function (User $record): void {
                app(TwoFactorService::class)->disable($record);

                AdminAudit::record(
                    'admin.user_two_factor_reset',
                    __('Two-factor authentication reset from the administration panel.'),
                    $record,
                );

                Notification::make()
                    ->title(__('Two-factor removed for :name.', ['name' => $record->name]))
                    ->body(__('Ask them to set it up again from Profile → Security.'))
                    ->success()
                    ->send();
            });
    }

    /* ------------------------------------------------------------------ *
     * Guards and options
     * ------------------------------------------------------------------ */

    public static function isSelf(?User $record): bool
    {
        return $record !== null
            && $record->exists
            && $record->getKey() === self::administrator()?->getKey();
    }

    /**
     * Whether this account is the only active platform administrator left.
     */
    public static function isLastAdministrator(User $record): bool
    {
        if (! $record->is_admin) {
            return false;
        }

        return ! User::query()
            ->where('is_admin', true)
            ->where('is_active', true)
            ->whereKeyNot($record->getKey())
            ->exists();
    }

    /**
     * Sessions are rows only under the database driver; under any other driver they live
     * where this query cannot reach, and the caller's warning has to stand on its own.
     */
    private static function revokeSessions(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->delete();
    }

    /**
     * @return array<string, string>
     */
    private static function timezones(): array
    {
        $identifiers = DateTimeZone::listIdentifiers();

        return array_combine($identifiers, $identifiers);
    }

    /**
     * The languages this installation offers, from `locales`.
     *
     * This used to list the directories under `lang/`, which offered `vendor` as a language
     * — Laravel's published-package folder is not a locale — and showed bare codes rather
     * than names. `locales` is the authority anyway: `users.locale` is honoured only when a
     * row there carries the code and is enabled (LOCALISATION.md §1), so a picker built from
     * anything else can write a value the middleware will silently skip.
     *
     * @return array<string, string>
     */
    private static function locales(): array
    {
        try {
            $locales = Locale::query()
                ->where('is_enabled', true)
                ->orderBy('position')
                ->orderBy('code')
                ->get()
                ->mapWithKeys(static fn (Locale $locale): array => [
                    (string) $locale->code => $locale->label(),
                ])
                ->all();
        } catch (Throwable) {
            // The panel must still render a usable form when the table cannot be read.
            $locales = [];
        }

        if ($locales === []) {
            $fallback = (string) config('planvio.defaults.workspace.locale', 'en');

            return [$fallback => $fallback];
        }

        return $locales;
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\AdminAudit;
use App\Filament\Support\NavigationGroup;
use App\Filament\Support\PlatformPage;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The database-backed maintenance switch.
 *
 * Laravel's own `php artisan down` needs shell access and writes a file the web user may not be
 * able to remove afterwards. Planvio is installed by uploading a ZIP to shared hosting, so the
 * switch lives in the `settings` table where this page can reach it — and it is written through
 * {@see Settings} so both cache layers are invalidated with it. A row updated directly would
 * leave the middleware reading the old value for up to an hour, which for this particular flag
 * means a site that will not come back up.
 *
 * Platform administrators keep full access while it is engaged, and so do the sign-in screens.
 * Both are deliberate: the point of the mode is to work on the installation, and locking out the
 * person doing the work — or the person who has not signed in yet — would make it a trap.
 */
final class MaintenanceMode extends PlatformPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::System;

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.maintenance-mode';

    public static function getNavigationLabel(): string
    {
        return __('Maintenance mode');
    }

    public function getTitle(): string
    {
        return __('Maintenance mode');
    }

    public function getSubheading(): ?string
    {
        return __('Close Planvio to everybody except platform administrators, with a message of your choosing.');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->engageAction(),
            $this->liftAction(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'engaged' => $this->engaged(),
            'message' => $this->message(),
            'defaultMessage' => $this->defaultMessage(),
        ];
    }

    /* ------------------------------------------------------------------ *
     * Actions
     * ------------------------------------------------------------------ */

    private function engageAction(): Action
    {
        return Action::make('engage')
            ->label(fn (): string => $this->engaged() ? __('Change the message') : __('Engage maintenance mode'))
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->color('warning')
            ->modalHeading(fn (): string => $this->engaged()
                ? __('Change the maintenance message')
                : __('Engage maintenance mode'))
            ->modalDescription(__('Everybody except platform administrators sees a 503 page with this message. Signing in stays possible, so you can get back in from another browser.'))
            ->modalSubmitActionLabel(fn (): string => $this->engaged() ? __('Save the message') : __('Close the site'))
            ->fillForm(fn (): array => ['message' => $this->message()])
            ->schema([
                Textarea::make('message')
                    ->label(__('Message shown to everybody else'))
                    ->rows(3)
                    ->maxLength(500)
                    ->placeholder($this->defaultMessage())
                    ->helperText(__('Leave blank to use the default. Say roughly when you expect to be back if you can — people wait better when they know.')),
            ])
            ->action(function (array $data): void {
                $settings = app(Settings::class);
                $message = is_string($data['message'] ?? null) ? trim($data['message']) : '';
                $wasEngaged = $this->engaged();

                if ($message === '') {
                    $settings->forget($this->messageKey());
                } else {
                    $settings->set($this->messageKey(), $message);
                }

                $settings->set($this->settingKey(), true);

                AdminAudit::record(
                    $wasEngaged ? 'admin.maintenance_message_changed' : 'admin.maintenance_engaged',
                    $wasEngaged
                        ? __('Maintenance message changed.')
                        : __('Maintenance mode engaged.'),
                    // The message is administrator-authored public copy, not a secret, but it is
                    // not recorded either: the trail is for who closed the site and when.
                    properties: ['has_custom_message' => $message !== ''],
                );

                Notification::make()
                    ->title($wasEngaged ? __('Message updated.') : __('Planvio is now closed.'))
                    ->body(__('You still have full access. Everybody else sees the maintenance page.'))
                    ->warning()
                    ->send();
            });
    }

    private function liftAction(): Action
    {
        return Action::make('lift')
            ->label(__('Lift maintenance mode'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('Reopen Planvio?'))
            ->modalDescription(__('Everybody gets access back immediately. The message is kept, so engaging the mode again reuses it.'))
            ->modalSubmitActionLabel(__('Reopen the site'))
            ->visible(fn (): bool => $this->engaged())
            ->action(function (): void {
                app(Settings::class)->set($this->settingKey(), false);

                AdminAudit::record(
                    'admin.maintenance_lifted',
                    __('Maintenance mode lifted.'),
                );

                Notification::make()
                    ->title(__('Planvio is open again.'))
                    ->success()
                    ->send();
            });
    }

    /* ------------------------------------------------------------------ *
     * State
     * ------------------------------------------------------------------ */

    private function engaged(): bool
    {
        return filter_var(app(Settings::class)->get($this->settingKey(), false), FILTER_VALIDATE_BOOL);
    }

    private function message(): ?string
    {
        $stored = app(Settings::class)->get($this->messageKey());

        return is_string($stored) && trim($stored) !== '' ? trim($stored) : null;
    }

    /**
     * The same sentence `App\Http\Middleware\MaintenanceMode` falls back to, so the preview on
     * this page is what a visitor would actually read.
     */
    private function defaultMessage(): string
    {
        return __('Planvio is temporarily unavailable while maintenance is carried out. Please try again shortly.');
    }

    private function settingKey(): string
    {
        $key = config('planvio.maintenance.setting_key');

        return is_string($key) && $key !== '' ? $key : 'maintenance.enabled';
    }

    private function messageKey(): string
    {
        $key = config('planvio.maintenance.message_key');

        return is_string($key) && $key !== '' ? $key : 'maintenance.message';
    }
}

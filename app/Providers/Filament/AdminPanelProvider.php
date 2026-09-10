<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Support\LocalAvatarProvider;
use App\Http\Middleware\ContentSecurityPolicy;
use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\MaintenanceMode;
use App\Http\Middleware\SetLocale;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The administration panel.
 *
 * This is deliberately NOT the product. Planvio's project-management experience is
 * purpose-built Livewire under App\Livewire\App. Filament is used here for what it is
 * genuinely good at: CRUD over platform records, system configuration, and log inspection.
 *
 * Access is restricted to platform super-admins (users.is_admin) by
 * User::canAccessPanel(). Workspace owners and admins manage their own workspace from the
 * product UI, not from here.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->brandName(config('planvio.brand.name', 'Planvio'))
            ->brandLogo(fn (): string => asset('img/brand/planvio-logo-h-ink.svg'))
            ->darkModeBrandLogo(fn (): string => asset('img/brand/planvio-logo-h-inverse.svg'))
            ->brandLogoHeight('1.6rem')
            ->favicon(asset('favicon.svg'))
            ->colors([
                // Anchored on the logo blue so the panel reads as part of Planvio
                // rather than a bolted-on admin tool.
                'primary' => Color::hex('#3F66B0'),
                'gray' => Color::Slate,
                'danger' => Color::Red,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'info' => Color::Blue,
            ])
            /*
             | No ->font() call, and that is the point. Naming a family switches Filament to
             | its Bunny font provider, which loads a stylesheet and four weights from
             | fonts.bunny.net: an outbound request on every administration screen, a broken
             | face on an installation with no internet access, and the one thing on /admin
             | the policy below would have to block. Left alone, Filament serves its own
             | Inter Variable from /fonts/filament — the same typeface, from this origin.
             |
             | The avatar is the same story: the shipped default asks ui-avatars.com to draw
             | the signed-in administrator's initials. LocalAvatarProvider returns what the
             | product already returns.
             */
            ->defaultAvatarProvider(LocalAvatarProvider::class)
            /*
             | The panel has its own Tailwind build and its own copy of Inter, so neither
             | the Arabic face nor the three corrections in resources/css/app.css reach it:
             | Arabic here was set in whatever the operating system offered, at Latin
             | leading, with the negative tracking Filament puts on every heading. The
             | partial carries the same rules and the same @font-face, and is emitted only
             | when the panel is actually rendering in Arabic.
             */
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): string => str_starts_with(app()->getLocale(), 'ar')
                    ? view('filament.partials.arabic-typography')->render()
                    : '',
            )
            ->maxContentWidth(Width::Full)
            ->sidebarCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->databaseNotifications(false)
            ->spa(false)
            /*
             | A Filament panel does not run through the `web` group — it assembles the stack
             | below verbatim — so the two gates bootstrap/app.php appends globally have to be
             | named here as well or `/admin` is the one surface neither of them covers.
             |
             | ContentSecurityPolicy is here for exactly that reason, and it is first so that
             | it wraps every response the rest of the stack can produce: the sign-in
             | redirect, the 503 from MaintenanceMode, the redirect to the installer. It runs
             | under its `panel` profile, which reads planvio.security.csp.panel and falls
             | back to the application's settings — the same directives, because Filament is
             | Alpine and Livewire too, and its assets come from /js/filament and
             | /css/filament on this origin. It writes nothing onto a response that already
             | carries a policy, so an attachment opened from a session established here
             | keeps the AttachmentController's `default-src 'none'; sandbox`.
             |
             | EnsureInstalled comes first among the gates, ahead of the session:
             | AuthenticateSession reads the user out of a database an unconfigured server does
             | not have yet. MaintenanceMode comes after it, because the flag it reads lives in
             | that database, and it lets a platform administrator straight through — which is
             | every visitor this panel has.
             |
             | SetLocale is here for the same reason, and it goes AFTER the session and the
             | authentication that populates it, because the locale it resolves is the signed-in
             | administrator's. Without it the panel renders in config('app.locale') for
             | everybody — which meant an Arabic-speaking administrator translating Planvio
             | into Arabic had to do it from an English screen.
             |
             | It goes AHEAD of MaintenanceMode, which answers 503 with `errors.503` instead
             | of calling the rest of the stack: behind it, the language and the direction
             | were never resolved for the one page an administrator sees while the
             | installation is closed.
             */
            ->middleware([
                ContentSecurityPolicy::class.':'.ContentSecurityPolicy::PANEL,
                EnsureInstalled::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                SetLocale::class,
                MaintenanceMode::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}

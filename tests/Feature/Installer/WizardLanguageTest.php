<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use App\Http\Middleware\SetLocale;
use App\Models\Locale;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The wizard's own language.
 *
 * Every other screen in Planvio resolves its language from the `locales` table. The
 * installer runs before that table exists, so it resolves from {@see Locale::SHIPPED} and a
 * session value the picker in the installer shell writes.
 *
 * Two things were broken before it existed, and both were silent. There was no control at
 * all, so a customer who had just extracted the ZIP read nine screens of English whatever
 * they spoke. And on an installation whose `.env` already said `ar`, `SetLocale` set no
 * locale and shared no direction, so the wizard came out as Arabic text laid out left to
 * right — the half-working state, which is worse than English.
 */
final class WizardLanguageTest extends InstallerTestCase
{
    #[Test]
    public function the_picker_switches_the_wizard_and_turns_it_around(): void
    {
        $before = $this->get(route('install.welcome'));

        $before->assertOk();
        $before->assertSee('lang="en"', escape: false);
        $before->assertSee('dir="ltr"', escape: false);

        $switch = $this->post(route('install.language'), ['locale' => 'ar']);

        $switch->assertRedirect();
        $switch->assertSessionHas(SetLocale::INSTALLER_SESSION_KEY, 'ar');

        $after = $this->get(route('install.welcome'));

        $after->assertOk();
        $after->assertSee('lang="ar"', escape: false);
        $after->assertSee('dir="rtl"', escape: false);
        $after->assertSee(__('Start installation', [], 'ar'));
    }

    #[Test]
    public function every_step_carries_the_choice(): void
    {
        $this->post(route('install.language'), ['locale' => 'ar']);

        // Requirements is the only other step that renders without collected data behind it.
        $response = $this->get(route('install.requirements'));

        $response->assertOk();
        $response->assertSee('dir="rtl"', escape: false);
    }

    #[Test]
    public function a_language_this_release_does_not_ship_is_ignored(): void
    {
        $this->post(route('install.language'), ['locale' => 'ar']);
        $this->post(route('install.language'), ['locale' => 'xx']);

        $this->assertSame('ar', session(SetLocale::INSTALLER_SESSION_KEY));

        $this->get(route('install.welcome'))->assertSee('dir="rtl"', escape: false);
    }

    #[Test]
    public function the_env_locale_still_decides_when_nobody_has_chosen(): void
    {
        config(['app.locale' => 'ar']);

        $response = $this->get(route('install.welcome'));

        $response->assertOk();
        $response->assertSee('lang="ar"', escape: false);
        $response->assertSee('dir="rtl"', escape: false);
    }

    #[Test]
    public function a_finished_installation_ignores_a_stale_wizard_choice(): void
    {
        /*
         * The session outlives the wizard. Once the lock exists the `locales` table is the
         * authority, and an empty one means the database is unreachable — not that whatever
         * the installer was last set to should decide what the product renders in.
         */
        $this->post(route('install.language'), ['locale' => 'ar']);
        $this->writeLockFile();

        $request = Request::create('/');
        $request->setLaravelSession($this->app['session']->driver());
        $request->session()->put(SetLocale::INSTALLER_SESSION_KEY, 'ar');

        app(SetLocale::class)->handle(
            $request,
            static fn (): Response => new Response,
        );

        $this->assertSame('en', app()->getLocale());
    }

    #[Test]
    public function the_languages_offered_are_the_ones_the_release_ships(): void
    {
        $shipped = Locale::shipped();

        $this->assertTrue($shipped->has('en'));
        $this->assertTrue($shipped->has('ar'));
        $this->assertTrue($shipped->get('ar')?->isRtl());
        $this->assertFalse($shipped->get('en')?->isRtl());

        // The picker names each language in its own script, so it can be recognised by
        // somebody who reads nothing else on the page.
        $this->get(route('install.welcome'))->assertSee('العربية', escape: false);
    }
}

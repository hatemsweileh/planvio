<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Locale;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\DefaultDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Database-backed maintenance mode, which is global middleware on the web group.
 *
 * The two things it must get right are that ordinary users are stopped, and that the people
 * and screens needed to turn it off again are not.
 */
final class MaintenanceModeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function nothing_changes_while_the_switch_is_off(): void
    {
        // Onboarding rather than `/`: the root is a redirect by design, and a 302 would say
        // nothing about whether maintenance mode was involved.
        $this->actingAs(User::factory()->create())
            ->get(route('workspaces.create'))
            ->assertOk();
    }

    #[Test]
    public function an_ordinary_user_gets_the_service_unavailable_page(): void
    {
        $this->engage();

        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertStatus(503)
            ->assertHeader('Retry-After');
    }

    #[Test]
    public function the_administrator_message_is_shown_when_one_is_set(): void
    {
        $this->engage();

        $this->app->make(Settings::class)->set(
            (string) config('planvio.maintenance.message_key'),
            'Upgrading the database, back at 14:00 UTC.',
        );

        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertStatus(503)
            ->assertSee('Upgrading the database, back at 14:00 UTC.', false);
    }

    #[Test]
    public function a_platform_administrator_keeps_working(): void
    {
        $this->engage();

        // The mode exists so somebody can work on the installation. Locking that somebody
        // out would make it a fault rather than a tool.
        $this->actingAs(User::factory()->platformAdmin()->create())
            ->get(route('workspaces.create'))
            ->assertOk();
    }

    #[Test]
    public function the_sign_in_screen_stays_open(): void
    {
        $this->engage();

        // An administrator who is not signed in yet has to be able to become one.
        $this->get(route('login'))->assertOk();

        $admin = User::factory()->platformAdmin()->create(['email' => 'root@example.com']);

        $this->post(route('login'), ['email' => 'root@example.com', 'password' => 'password'])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($admin);
    }

    #[Test]
    public function an_api_style_request_gets_json(): void
    {
        $this->engage();

        $this->actingAs(User::factory()->create())
            ->getJson('/')
            ->assertStatus(503)
            ->assertJsonStructure(['message']);
    }

    #[Test]
    public function the_closed_page_speaks_the_readers_language(): void
    {
        /*
         * MaintenanceMode answers 503 instead of calling the rest of the stack, so anything
         * behind it never runs. SetLocale used to be behind it, and the one page an Arabic
         * administrator sees while the installation is closed came out in English, laid out
         * left to right, with no `$textDirection` for the layout to put in `dir`.
         */
        Locale::query()->create([
            'code' => 'ar',
            'name' => 'Arabic',
            'native_name' => 'العربية',
            'direction' => Locale::RTL,
            'is_enabled' => true,
        ]);

        $this->engage();

        $response = $this->actingAs(User::factory()->create(['locale' => 'ar']))->get('/');

        $response->assertStatus(503);
        $response->assertSee('lang="ar"', escape: false);
        $response->assertSee('dir="rtl"', escape: false);
        $response->assertSee(__('Planvio is temporarily unavailable while maintenance is carried out. Please try again shortly.', [], 'ar'));
    }

    #[Test]
    public function no_message_is_seeded_over_the_translated_default(): void
    {
        /*
         * A seeded row is a fixed English sentence, and the middleware prefers a stored
         * value over its own fallback — so seeding one would replace a translated default
         * with an untranslatable row on the only page a closed installation can show.
         */
        $this->seed(DefaultDataSeeder::class);

        $this->assertNull(
            $this->app->make(Settings::class)->get((string) config('planvio.maintenance.message_key')),
        );
    }

    private function engage(): void
    {
        $this->app->make(Settings::class)->set((string) config('planvio.maintenance.setting_key'), true);
    }
}

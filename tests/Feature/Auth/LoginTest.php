<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Password sign-in: what succeeds, what fails, and what the failures leave behind.
 */
final class LoginTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_sign_in_screen_renders(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee(__('Sign in'), false);
    }

    #[Test]
    public function a_user_signs_in_with_valid_credentials(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->post(route('login'), [
            'email' => 'ada@example.com',
            'password' => 'password',
        ])->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);

        $user->refresh();
        $this->assertNotNull($user->last_login_at);
        $this->assertNotNull($user->last_login_ip);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'event' => 'auth.login',
        ]);
    }

    #[Test]
    public function the_email_is_matched_case_insensitively(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->post(route('login'), [
            'email' => '  ADA@Example.com ',
            'password' => 'password',
        ])->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function a_wrong_password_is_refused_and_recorded(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->post(route('login'), [
            'email' => 'ada@example.com',
            'password' => 'not-the-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'event' => 'auth.login_failed',
        ]);
    }

    #[Test]
    public function an_unknown_address_gets_the_same_message_as_a_wrong_password(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        // The same message for both, so the response cannot be used to test whether an
        // address is registered here.
        $this->post(route('login'), [
            'email' => 'ada@example.com',
            'password' => 'not-the-password',
        ])->assertSessionHasErrors(['email' => __('auth.failed')]);

        $this->flushSession();

        $this->post(route('login'), [
            'email' => 'nobody@example.com',
            'password' => 'not-the-password',
        ])->assertSessionHasErrors(['email' => __('auth.failed')]);

        // The attempt is still recorded, with no user attached because there is none.
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => null,
            'event' => 'auth.login_failed',
        ]);
    }

    #[Test]
    public function a_deactivated_account_cannot_sign_in(): void
    {
        $user = User::factory()->inactive()->create(['email' => 'ada@example.com']);

        $this->post(route('login'), [
            'email' => 'ada@example.com',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'event' => 'auth.login_failed',
        ]);
    }

    #[Test]
    public function the_attempt_after_the_configured_maximum_is_locked_out(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $attempts = (int) config('planvio.security.login.max_attempts');

        for ($i = 0; $i < $attempts; $i++) {
            $this->post(route('login'), [
                'email' => 'ada@example.com',
                'password' => 'wrong-'.$i,
            ])->assertSessionHasErrors('email');
        }

        // The correct password, on the attempt after the limit. It must still be refused —
        // otherwise the limit only slows an attacker down until they guess right.
        $response = $this->post(route('login'), [
            'email' => 'ada@example.com',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('email');

        /*
         * Asserting the rendered sentence verbatim makes this test time-dependent: the
         * limiter reports the seconds remaining, so a clock tick between building the
         * expected string and reading the actual one turns 900 into 899 and fails a run
         * that found nothing wrong. What matters is that the refusal is the throttle, and
         * that it names a delay in the right order of magnitude.
         */
        $message = (string) session('errors')->first('email');

        $this->assertMatchesRegularExpression('/^Too many login attempts\./', $message);

        preg_match('/(\d+) seconds/', $message, $seconds);
        $lockout = (int) config('planvio.security.login.lockout_minutes') * 60;

        $this->assertNotEmpty($seconds, "Throttle message did not state a delay: {$message}");
        $this->assertEqualsWithDelta($lockout, (int) $seconds[1], 5.0);

        $this->assertGuest();
        $this->assertDatabaseMissing('audit_logs', ['event' => 'auth.login']);
    }

    #[Test]
    public function the_limiter_is_keyed_on_the_address_as_well_as_the_client(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);
        User::factory()->create(['email' => 'grace@example.com']);

        $attempts = (int) config('planvio.security.login.max_attempts');

        for ($i = 0; $i <= $attempts; $i++) {
            $this->post(route('login'), ['email' => 'ada@example.com', 'password' => 'wrong']);
        }

        // Same client address, different account. Locking this one out too would hand
        // anybody a way to lock a colleague out by failing to sign in as them.
        $this->post(route('login'), [
            'email' => 'grace@example.com',
            'password' => 'password',
        ])->assertRedirect(route('home'));

        $this->assertAuthenticated();
    }

    #[Test]
    public function a_successful_sign_in_clears_the_limiter(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->post(route('login'), ['email' => 'ada@example.com', 'password' => 'wrong']);
        $this->post(route('login'), ['email' => 'ada@example.com', 'password' => 'password'])
            ->assertRedirect(route('home'));

        $this->post(route('logout'));
        $this->flushSession();

        // The one earlier failure must not still be on the counter.
        $attempts = (int) config('planvio.security.login.max_attempts');

        for ($i = 0; $i < $attempts - 1; $i++) {
            $this->post(route('login'), ['email' => 'ada@example.com', 'password' => 'wrong']);
        }

        $this->post(route('login'), ['email' => 'ada@example.com', 'password' => 'password'])
            ->assertRedirect(route('home'));

        $this->assertAuthenticated();
    }

    #[Test]
    public function an_authenticated_user_is_sent_away_from_the_sign_in_screen(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('login'))
            ->assertRedirect('/');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * The second factor.
 *
 * The property under test throughout is that a correct password alone never produces an
 * authenticated session for an account that has two-factor confirmed.
 */
final class TwoFactorChallengeTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const RECOVERY_CODES = ['ABCDE-FGHIJ', 'KLMNP-QRSTU'];

    private string $secret;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->secret = $this->google2fa()->generateSecretKey(32);

        $this->user = User::factory()->create(['email' => 'ada@example.com']);
        $this->user->forceFill([
            'two_factor_secret' => $this->secret,
            'two_factor_recovery_codes' => self::RECOVERY_CODES,
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    #[Test]
    public function a_correct_password_alone_does_not_authenticate(): void
    {
        $this->signIn()->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
        $this->assertDatabaseMissing('audit_logs', ['event' => 'auth.login']);
    }

    #[Test]
    public function the_challenge_screen_renders_once_a_sign_in_is_pending(): void
    {
        $this->signIn();

        $this->get(route('two-factor.login'))->assertOk();
    }

    #[Test]
    public function the_challenge_screen_is_not_reachable_without_a_pending_sign_in(): void
    {
        $this->get(route('two-factor.login'))->assertRedirect(route('login'));
    }

    #[Test]
    public function a_valid_code_completes_the_sign_in(): void
    {
        $this->signIn();

        $this->post(route('two-factor.login'), [
            'code' => $this->google2fa()->getCurrentOtp($this->secret),
        ])->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($this->user);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'event' => 'auth.login',
        ]);
    }

    #[Test]
    public function a_wrong_code_keeps_the_visitor_out(): void
    {
        $this->signIn();

        $this->post(route('two-factor.login'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertGuest();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'event' => 'auth.two_factor_failed',
        ]);
    }

    #[Test]
    public function a_recovery_code_signs_in_and_is_spent(): void
    {
        $this->signIn();

        $this->post(route('two-factor.login'), ['recovery_code' => self::RECOVERY_CODES[0]])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($this->user);

        $remaining = $this->user->refresh()->two_factor_recovery_codes;

        $this->assertIsArray($remaining);
        $this->assertNotContains(self::RECOVERY_CODES[0], $remaining);
        $this->assertContains(self::RECOVERY_CODES[1], $remaining);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'event' => 'auth.two_factor_recovery_code_used',
        ]);
    }

    #[Test]
    public function a_spent_recovery_code_cannot_be_used_again(): void
    {
        $this->signIn();
        $this->post(route('two-factor.login'), ['recovery_code' => self::RECOVERY_CODES[0]]);

        $this->post(route('logout'));
        $this->flushSession();

        $this->signIn();
        $this->post(route('two-factor.login'), ['recovery_code' => self::RECOVERY_CODES[0]])
            ->assertSessionHasErrors('recovery_code');

        $this->assertGuest();
    }

    #[Test]
    public function an_abandoned_challenge_expires(): void
    {
        $this->signIn();

        $this->travel(11)->minutes();

        $this->post(route('two-factor.login'), [
            'code' => $this->google2fa()->getCurrentOtp($this->secret),
        ])->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function the_challenge_is_rate_limited(): void
    {
        $this->signIn();

        $attempts = (int) config('planvio.security.login.max_attempts');

        for ($i = 0; $i < $attempts; $i++) {
            $this->post(route('two-factor.login'), ['code' => '000000'])
                ->assertSessionHasErrors('code');
        }

        // Six digits is a million combinations; without a limit here the password limit
        // would be the only thing in the way, and it has already been satisfied.
        $this->post(route('two-factor.login'), [
            'code' => $this->google2fa()->getCurrentOtp($this->secret),
        ])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    #[Test]
    public function a_submission_with_neither_field_is_rejected(): void
    {
        $this->signIn();

        $this->post(route('two-factor.login'), [])->assertSessionHasErrors(['code', 'recovery_code']);

        $this->assertGuest();
    }

    private function signIn(): TestResponse
    {
        return $this->post(route('login'), [
            'email' => 'ada@example.com',
            'password' => 'password',
        ]);
    }

    private function google2fa(): Google2FA
    {
        return $this->app->make(Google2FA::class);
    }
}

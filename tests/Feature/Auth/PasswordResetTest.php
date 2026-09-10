<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Password reset, and the property that matters most about it: the form must not tell a
 * stranger whether an address belongs to an account here.
 */
final class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_request_screen_renders(): void
    {
        $this->get(route('password.request'))->assertOk();
    }

    #[Test]
    public function a_known_and_an_unknown_address_get_identical_answers(): void
    {
        Notification::fake();

        User::factory()->create(['email' => 'ada@example.com']);

        $known = $this->post(route('password.email'), ['email' => 'ada@example.com']);
        $knownStatus = session('status');

        $this->flushSession();

        $unknown = $this->post(route('password.email'), ['email' => 'nobody@example.com']);
        $unknownStatus = session('status');

        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertNotNull($knownStatus);
        $this->assertSame($knownStatus, $unknownStatus);

        $known->assertSessionHasNoErrors();
        $unknown->assertSessionHasNoErrors();
    }

    #[Test]
    public function only_the_real_account_is_emailed(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->post(route('password.email'), ['email' => 'ada@example.com']);
        $this->post(route('password.email'), ['email' => 'nobody@example.com']);

        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertSentTimes(ResetPassword::class, 1);
    }

    #[Test]
    public function a_deactivated_account_is_not_emailed_and_is_not_revealed(): void
    {
        Notification::fake();

        $user = User::factory()->inactive()->create(['email' => 'ada@example.com']);

        $this->post(route('password.email'), ['email' => 'ada@example.com'])
            ->assertSessionHasNoErrors();

        Notification::assertNothingSentTo($user);
    }

    #[Test]
    public function the_outcome_is_recorded_for_an_administrator_to_read(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->post(route('password.email'), ['email' => 'ada@example.com']);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'event' => 'auth.password_reset_requested',
        ]);
    }

    #[Test]
    public function a_valid_token_sets_a_new_password(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $token = Password::broker()->createToken($user);

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))->assertOk();

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => 'ada@example.com',
            'password' => 'Correct-Horse-9',
            'password_confirmation' => 'Correct-Horse-9',
        ])->assertRedirect(route('login'))->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Correct-Horse-9', (string) $user->refresh()->password));

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'event' => 'auth.password_reset',
        ]);
    }

    #[Test]
    public function a_wrong_token_and_an_unknown_address_fail_the_same_way(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $token = Password::broker()->createToken($user);

        // Both come back as "this link is no longer valid". The broker's own `passwords.user`
        // line would otherwise confirm which addresses are registered.
        $this->post(route('password.store'), [
            'token' => 'not-a-real-token',
            'email' => 'ada@example.com',
            'password' => 'Correct-Horse-9',
            'password_confirmation' => 'Correct-Horse-9',
        ])->assertSessionHasErrors(['email' => __('passwords.token')]);

        $this->flushSession();

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => 'nobody@example.com',
            'password' => 'Correct-Horse-9',
            'password_confirmation' => 'Correct-Horse-9',
        ])->assertSessionHasErrors(['email' => __('passwords.token')]);

        $this->assertTrue(Hash::check('password', (string) $user->refresh()->password));
    }

    #[Test]
    public function the_new_password_must_satisfy_the_configured_policy(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $token = Password::broker()->createToken($user);

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => 'ada@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', (string) $user->refresh()->password));
    }
}

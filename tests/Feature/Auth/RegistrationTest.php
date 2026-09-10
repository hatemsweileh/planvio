<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\RegisterController;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Self-service registration, which is off until an administrator turns it on.
 */
final class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_form_does_not_exist_while_registration_is_disabled(): void
    {
        $this->get(route('register'))->assertNotFound();

        $this->post(route('register'), [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'Correct-Horse-9',
            'password_confirmation' => 'Correct-Horse-9',
        ])->assertNotFound();

        $this->assertDatabaseCount('users', 0);
    }

    #[Test]
    public function an_account_is_created_once_registration_is_enabled(): void
    {
        $this->enableRegistration();

        $this->get(route('register'))->assertOk();

        $this->post(route('register'), [
            'name' => 'Ada Lovelace',
            'email' => 'Ada@Example.com',
            'password' => 'Correct-Horse-9',
            'password_confirmation' => 'Correct-Horse-9',
        ])->assertRedirect(route('home'));

        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->is_admin, 'Registration must never mint a platform administrator.');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'event' => 'auth.register',
        ]);
    }

    #[Test]
    public function the_password_must_satisfy_the_configured_policy(): void
    {
        $this->enableRegistration();

        $this->post(route('register'), [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    #[Test]
    public function an_address_can_only_be_registered_once(): void
    {
        $this->enableRegistration();

        User::factory()->create(['email' => 'ada@example.com']);

        $this->post(route('register'), [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'Correct-Horse-9',
            'password_confirmation' => 'Correct-Horse-9',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseCount('users', 1);
    }

    private function enableRegistration(): void
    {
        $this->app->make(Settings::class)->set(RegisterController::SETTING_KEY, true);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sign-out has to end the session, not just the guard.
 */
final class LogoutTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function signing_out_clears_the_guard_and_destroys_the_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['planvio.probe' => 'still-here'])
            ->post(route('logout'))
            ->assertRedirect(route('login'))
            ->assertSessionMissing('planvio.probe');

        $this->assertGuest();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'event' => 'auth.logout',
        ]);
    }

    #[Test]
    public function a_guest_cannot_reach_the_sign_out_route(): void
    {
        $this->post(route('logout'))->assertRedirect(route('login'));
    }

    #[Test]
    public function the_authenticated_area_is_closed_again_after_signing_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('logout'));
        $this->flushSession();

        $this->get('/')->assertRedirect(route('login'));
    }
}

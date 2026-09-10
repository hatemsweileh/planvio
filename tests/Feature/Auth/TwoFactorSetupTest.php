<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Enrolling in two-factor, and the two properties that make it safe to hand to a user:
 * the secret never leaves the server, and it does not take effect until it is proven to work.
 */
final class TwoFactorSetupTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function enrolling_stores_a_secret_without_putting_it_in_force(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('two-factor.enable'))
            ->assertRedirect(route('two-factor.setup'));

        $user->refresh();

        $this->assertNotNull($user->two_factor_secret);
        $this->assertIsArray($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertFalse(
            $user->hasTwoFactorEnabled(),
            'A secret that was never confirmed must not gate sign-in, or a failed scan is a lockout.',
        );
    }

    #[Test]
    public function the_qr_code_is_rendered_locally_and_never_points_at_a_third_party(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('two-factor.enable'));

        $response = $this->actingAs($user)->get(route('two-factor.setup'))->assertOk();

        $content = $response->getContent();

        $this->assertIsString($content);
        $this->assertStringContainsString('<svg', $content);

        // Handing the otpauth URI to a chart service would post the shared secret to
        // somebody else's server inside a URL.
        $this->assertStringNotContainsString('chart.googleapis.com', $content);
        $this->assertStringNotContainsString('otpauth://', $content);
    }

    #[Test]
    public function a_valid_code_turns_two_factor_on_and_reveals_the_recovery_codes_once(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('two-factor.enable'));

        $secret = (string) $user->refresh()->two_factor_secret;

        $this->actingAs($user)->post(route('two-factor.confirm'), [
            'code' => $this->app->make(Google2FA::class)->getCurrentOtp($secret),
        ])->assertRedirect(route('two-factor.setup'))->assertSessionHas('recoveryCodes');

        $this->assertTrue($user->refresh()->hasTwoFactorEnabled());

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'event' => 'auth.two_factor_enabled',
        ]);

        $firstCode = $this->app->make(TwoFactorService::class)->recoveryCodes($user)[0];

        // Shown once, on the page the redirect lands on...
        $this->actingAs($user)->get(route('two-factor.setup'))->assertOk()->assertSee($firstCode);

        // ...and never again: a screen that re-reveals them turns any borrowed session into
        // a permanent bypass of the second factor.
        $this->actingAs($user)->get(route('two-factor.setup'))->assertOk()->assertDontSee($firstCode);
    }

    #[Test]
    public function a_wrong_code_does_not_turn_two_factor_on(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('two-factor.enable'));

        $this->actingAs($user)->post(route('two-factor.confirm'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse($user->refresh()->hasTwoFactorEnabled());
    }

    #[Test]
    public function turning_two_factor_off_costs_the_current_password(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->actingAs($user)->delete(route('two-factor.disable'), ['current_password' => 'wrong'])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue($user->refresh()->hasTwoFactorEnabled());

        $this->actingAs($user)->delete(route('two-factor.disable'), ['current_password' => 'password'])
            ->assertRedirect(route('two-factor.setup'));

        $user->refresh();

        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'event' => 'auth.two_factor_disabled',
        ]);
    }

    #[Test]
    public function regenerating_recovery_codes_retires_the_old_set(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $before = $this->app->make(TwoFactorService::class)->recoveryCodes($user);

        $this->actingAs($user)->post(route('two-factor.recovery-codes'), ['current_password' => 'password'])
            ->assertRedirect(route('two-factor.setup'))
            ->assertSessionHas('recoveryCodes');

        $after = $this->app->make(TwoFactorService::class)->recoveryCodes($user->refresh());

        $this->assertNotEmpty($after);
        $this->assertSame([], array_intersect($before, $after));
    }

    #[Test]
    public function a_guest_cannot_reach_the_enrolment_screen(): void
    {
        $this->get(route('two-factor.setup'))->assertRedirect(route('login'));
    }

    #[Test]
    public function a_role_that_requires_two_factor_is_held_at_enrolment(): void
    {
        config()->set('planvio.security.two_factor.required_for_roles', [WorkspaceRole::Owner->value]);

        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);

        $this->actingAs($owner)
            ->get(route('app.home', $workspace))
            ->assertRedirect(route('two-factor.setup'));

        // The screen the gate sends them to has to stay open, or the gate is a lockout.
        $this->actingAs($owner)->get(route('two-factor.setup'))->assertOk();
    }

    #[Test]
    public function a_role_that_does_not_require_two_factor_is_left_alone(): void
    {
        config()->set('planvio.security.two_factor.required_for_roles', [WorkspaceRole::Owner->value]);

        $workspace = $this->makeWorkspace();
        $member = $this->makeMember($workspace, WorkspaceRole::Member);

        $this->actingAs($member)->get(route('app.home', $workspace))->assertOk();
    }

    #[Test]
    public function an_account_that_must_keep_two_factor_is_not_offered_a_way_off(): void
    {
        config()->set('planvio.security.two_factor.required_for_platform_admins', true);

        $admin = User::factory()->platformAdmin()->withTwoFactor()->create();

        $this->actingAs($admin)
            ->get(route('two-factor.setup'))
            ->assertOk()
            ->assertDontSee(__('Turn off two-factor authentication'));
    }
}

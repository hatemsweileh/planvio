<?php

declare(strict_types=1);

namespace Tests\Feature\App\Settings;

use App\Enums\WorkspaceRole;
use App\Http\Middleware\EnsureTwoFactorConfirmed;
use App\Livewire\App\Settings\Security;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Settings;
use App\Support\TwoFactorRequirement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The screen behind `security.two_factor.required_for_roles` (docs/SECURITY.md §3).
 *
 * The enforcement has always worked; until now the only way to configure it was to edit
 * `config/planvio.php` on the server. Three things have to hold now that it has a screen:
 *
 *   1. **What is saved is what is enforced.** The middleware has to read the stored value,
 *      or the screen is a form that writes to nothing.
 *   2. **The installation outranks the workspace.** A role required by `config/planvio.php`
 *      cannot be unticked here, because the person who controls the server is not the person
 *      who administers one tenant.
 *   3. **It stays inside the tenant.** Saving a policy in one workspace must not force
 *      anybody in another to enrol. A tenant-scoped screen with an installation-wide effect
 *      is the shape of bug ARCHITECTURE.md §3 exists to prevent.
 */
final class TwoFactorPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->makeWorkspace(['slug' => 'acme']);
        $this->owner = $this->makeMember($this->workspace, WorkspaceRole::Owner);
    }

    /* ------------------------------------------------------------------ *
     * Saving, and being obeyed
     * ------------------------------------------------------------------ */

    #[Test]
    public function saving_a_role_makes_the_gate_require_it(): void
    {
        $manager = $this->makeMember($this->workspace, WorkspaceRole::Manager);

        $this->assertFalse(EnsureTwoFactorConfirmed::isRequiredFor($manager, $this->workspace));

        Livewire::actingAs($this->owner)
            ->test(Security::class, ['workspace' => $this->workspace])
            ->set('roles', ['manager'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(EnsureTwoFactorConfirmed::isRequiredFor($manager->fresh(), $this->workspace));
    }

    #[Test]
    public function a_member_whose_role_now_requires_it_is_held_at_enrolment(): void
    {
        $manager = $this->makeMember($this->workspace, WorkspaceRole::Manager);

        Livewire::actingAs($this->owner)
            ->test(Security::class, ['workspace' => $this->workspace])
            ->set('roles', ['manager'])
            ->call('save');

        $this->actingAs($manager)
            ->get(route('app.home', $this->workspace))
            ->assertRedirect(route('two-factor.setup'));
    }

    #[Test]
    public function somebody_who_has_already_enrolled_is_not_interrupted(): void
    {
        $manager = $this->makeMember($this->workspace, WorkspaceRole::Manager, [
            'two_factor_secret' => 'ABCDEFGHIJKLMNOP',
            'two_factor_confirmed_at' => now(),
        ]);

        Livewire::actingAs($this->owner)
            ->test(Security::class, ['workspace' => $this->workspace])
            ->set('roles', ['manager'])
            ->call('save');

        $this->actingAs($manager)->get(route('app.home', $this->workspace))->assertOk();
    }

    #[Test]
    public function clearing_the_selection_lifts_the_requirement(): void
    {
        $manager = $this->makeMember($this->workspace, WorkspaceRole::Manager);

        $requirement = app(TwoFactorRequirement::class);
        $requirement->store($this->workspace, ['manager']);

        $this->assertTrue(EnsureTwoFactorConfirmed::isRequiredFor($manager, $this->workspace));

        Livewire::actingAs($this->owner)
            ->test(Security::class, ['workspace' => $this->workspace])
            ->set('roles', [])
            ->call('save');

        $this->assertFalse(EnsureTwoFactorConfirmed::isRequiredFor($manager->fresh(), $this->workspace));
    }

    /* ------------------------------------------------------------------ *
     * The config floor
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_role_required_by_the_installation_cannot_be_unticked(): void
    {
        config(['planvio.security.two_factor.required_for_roles' => ['owner']]);

        $component = Livewire::actingAs($this->owner)
            ->test(Security::class, ['workspace' => $this->workspace]);

        $component->assertSet('roles', ['owner']);

        $component->set('roles', [])->call('save');

        $this->assertTrue(EnsureTwoFactorConfirmed::isRequiredFor($this->owner->fresh(), $this->workspace));
        $this->assertSame(['owner'], app(TwoFactorRequirement::class)->rolesFor($this->workspace));
    }

    /**
     * The stored row holds what the workspace chose. Copying the installation's own roles
     * into it would pin the old value if `config/planvio.php` were later changed.
     */
    #[Test]
    public function the_stored_row_holds_only_what_the_workspace_added(): void
    {
        config(['planvio.security.two_factor.required_for_roles' => ['owner']]);

        Livewire::actingAs($this->owner)
            ->test(Security::class, ['workspace' => $this->workspace])
            ->set('roles', ['owner', 'manager'])
            ->call('save');

        $stored = app(Settings::class)->get(TwoFactorRequirement::settingKey($this->workspace));

        $this->assertSame(['manager'], $stored);
    }

    /* ------------------------------------------------------------------ *
     * Tenancy
     * ------------------------------------------------------------------ */

    #[Test]
    public function one_workspaces_policy_does_not_reach_another(): void
    {
        $other = $this->makeWorkspace(['slug' => 'northwind']);
        $theirManager = $this->makeMember($other, WorkspaceRole::Manager);

        Livewire::actingAs($this->owner)
            ->test(Security::class, ['workspace' => $this->workspace])
            ->set('roles', ['manager'])
            ->call('save');

        $this->assertFalse(
            EnsureTwoFactorConfirmed::isRequiredFor($theirManager, $other),
            'A policy saved in one workspace forced enrolment in another.',
        );

        $this->assertFalse(
            EnsureTwoFactorConfirmed::isRequiredFor($theirManager),
            'With no workspace bound, a foreign policy still caught a member of another tenant.',
        );
    }

    #[Test]
    public function a_role_held_in_any_workspace_that_requires_it_counts_with_none_bound(): void
    {
        $other = $this->makeWorkspace(['slug' => 'northwind']);

        $person = $this->makeMember($this->workspace, WorkspaceRole::Member);
        $other->members()->attach($person->getKey(), [
            'role' => WorkspaceRole::Manager->value,
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(TwoFactorRequirement::class)->store($other, ['manager']);

        // Not required inside acme, where they are only a member...
        $this->assertFalse(EnsureTwoFactorConfirmed::isRequiredFor($person, $this->workspace));

        // ...but the credential being protected is the account, so it is required with no
        // workspace bound.
        $this->assertTrue(EnsureTwoFactorConfirmed::isRequiredFor($person));
    }

    /* ------------------------------------------------------------------ *
     * The head-count shown before saving
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_screen_counts_only_people_who_have_not_already_enrolled(): void
    {
        $this->makeMember($this->workspace, WorkspaceRole::Manager);
        $this->makeMember($this->workspace, WorkspaceRole::Manager);
        $this->makeMember($this->workspace, WorkspaceRole::Manager, [
            'two_factor_secret' => 'ABCDEFGHIJKLMNOP',
            'two_factor_confirmed_at' => now(),
        ]);

        $component = Livewire::actingAs($this->owner)
            ->test(Security::class, ['workspace' => $this->workspace])
            ->set('roles', ['manager']);

        $this->assertSame(2, $component->instance()->affected);

        $managers = collect($component->instance()->options)
            ->firstWhere('value', 'manager');

        $this->assertSame(3, $managers['total']);
        $this->assertSame(2, $managers['affected']);
    }

    #[Test]
    public function the_count_is_zero_before_anything_is_ticked(): void
    {
        $this->makeMember($this->workspace, WorkspaceRole::Manager);

        $component = Livewire::actingAs($this->owner)
            ->test(Security::class, ['workspace' => $this->workspace]);

        $this->assertSame(0, $component->instance()->affected);
    }

    /* ------------------------------------------------------------------ *
     * Authorization
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_section_renders_inside_the_settings_page(): void
    {
        $this->actingAs($this->owner)
            ->get(route('app.settings', $this->workspace).'?section=security')
            ->assertOk()
            ->assertSee('Require two-factor authentication');
    }

    #[Test]
    public function a_member_cannot_open_the_screen(): void
    {
        $member = $this->makeMember($this->workspace, WorkspaceRole::Member);

        Livewire::actingAs($member)
            ->test(Security::class, ['workspace' => $this->workspace])
            ->assertForbidden();
    }

    #[Test]
    public function a_role_that_is_not_a_role_is_refused(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Security::class, ['workspace' => $this->workspace])
            ->set('roles', ['superuser'])
            ->call('save')
            ->assertHasErrors('roles.0');

        $this->assertSame([], app(TwoFactorRequirement::class)->rolesFor($this->workspace));
    }

    #[Test]
    public function the_change_is_written_to_the_audit_log(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Security::class, ['workspace' => $this->workspace])
            ->set('roles', ['owner'])
            ->call('save');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->owner->getKey(),
            'workspace_id' => $this->workspace->getKey(),
            'event' => 'two_factor.required_roles_changed',
        ]);
    }
}

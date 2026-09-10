<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Support\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SetCurrentWorkspace: the middleware that turns a slug in the path into a bound tenant, and
 * refuses everybody who is not a member of it.
 */
final class WorkspaceBindingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_member_reaches_their_workspace(): void
    {
        $workspace = $this->makeWorkspace();
        $member = $this->makeMember($workspace, WorkspaceRole::Manager);

        $this->actingAs($member)
            ->get(route('app.home', $workspace))
            ->assertOk()
            // Escaped, because the shell prints the name through Blade: a factory company
            // name containing an apostrophe is rendered as `&#039;` and an unescaped
            // assertion would fail on a name that is on the page.
            ->assertSee($workspace->name);

        $this->assertSame(
            (int) $workspace->getKey(),
            $this->app->make(CurrentWorkspace::class)->id(),
        );
    }

    #[Test]
    public function a_stranger_is_refused(): void
    {
        $workspace = $this->makeWorkspace();
        $outsider = $this->makeMember($this->makeWorkspace());

        $this->actingAs($outsider)->get(route('app.home', $workspace))->assertForbidden();
    }

    #[Test]
    public function an_unknown_slug_is_a_not_found(): void
    {
        $member = $this->makeMember($this->makeWorkspace());

        $this->actingAs($member)->get('/w/no-such-workspace')->assertNotFound();
    }

    #[Test]
    public function a_guest_is_sent_to_the_sign_in_screen(): void
    {
        $workspace = $this->makeWorkspace();

        $this->get(route('app.home', $workspace))->assertRedirect(route('login'));
    }

    #[Test]
    public function a_suspended_workspace_is_closed_to_its_members(): void
    {
        $workspace = $this->makeWorkspace();
        $member = $this->makeMember($workspace);

        $workspace->forceFill(['is_suspended' => true])->save();

        $this->actingAs($member)->get(route('app.home', $workspace))->assertForbidden();
    }

    #[Test]
    public function activity_is_stamped_once_and_then_left_alone_for_an_hour(): void
    {
        $workspace = $this->makeWorkspace();
        $member = $this->makeMember($workspace);

        $this->actingAs($member)->get(route('app.home', $workspace))->assertOk();

        $first = $this->membership($workspace->getKey(), $member->getKey())->last_active_at;
        $this->assertNotNull($first);

        $this->travel(10)->minutes();
        $this->actingAs($member)->get(route('app.home', $workspace))->assertOk();

        $this->assertTrue(
            $first->equalTo($this->membership($workspace->getKey(), $member->getKey())->last_active_at),
            'last_active_at must not be rewritten on every request.',
        );

        $this->travel(61)->minutes();
        $this->actingAs($member)->get(route('app.home', $workspace))->assertOk();

        $this->assertTrue(
            $first->lessThan($this->membership($workspace->getKey(), $member->getKey())->last_active_at),
        );
    }

    #[Test]
    public function a_route_without_the_parameter_falls_back_to_the_last_used_workspace(): void
    {
        $member = $this->makeMember($this->makeWorkspace());
        $recent = $this->makeWorkspace();
        $recent->addMember($member->fresh(), WorkspaceRole::Member);

        WorkspaceMember::withoutWorkspaceScope()
            ->where('workspace_id', $recent->getKey())
            ->where('user_id', $member->getKey())
            ->update(['last_active_at' => Carbon::now()]);

        $this->actingAs($member)->get('/')->assertRedirect(route('app.home', $recent));
    }

    #[Test]
    public function a_user_with_no_workspace_is_sent_to_onboarding(): void
    {
        // Nothing is bound, and nothing can be: every screen in the product is addressed
        // per workspace. The only honest destination is the one that creates the first one.
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertRedirect(route('workspaces.create'));

        $this->actingAs($user)->get(route('workspaces.create'))->assertOk();
    }

    private function membership(mixed $workspaceId, mixed $userId): WorkspaceMember
    {
        return WorkspaceMember::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->firstOrFail();
    }
}

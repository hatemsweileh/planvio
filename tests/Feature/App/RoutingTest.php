<?php

declare(strict_types=1);

namespace Tests\Feature\App;

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WikiPage;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The application route table.
 *
 * Every name here is a contract: the shell layout resolves them, notifications link to
 * them, and every other UI component generates URLs from them. A rename is a breaking
 * change, so each one is asserted by name rather than by path.
 *
 * The suite runs each route twice — once as a member of the workspace, who must get a page,
 * and once as somebody from another workspace, who must get 403 or 404 and never a 200.
 */
final class RoutingTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $member;

    private User $outsider;

    private Project $project;

    private Task $task;

    private WikiPage $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->makeWorkspace(['slug' => 'acme']);
        $this->member = $this->makeMember($this->workspace, WorkspaceRole::Owner);
        $this->outsider = $this->makeMember($this->makeWorkspace(['slug' => 'northwind']));

        $this->project = $this->makeProject($this->workspace, [], ['slug' => 'website', 'key' => 'WEB']);
        $this->task = $this->makeTask($this->project);

        $this->page = WikiPage::factory()
            ->forProject($this->project)
            ->create(['slug' => 'brief', 'author_id' => $this->member->getKey()]);
    }

    /**
     * Every workspace-scoped route, by name. The parameters are filled in by
     * {@see self::parametersFor()} so the list stays readable.
     *
     * @return list<array{0: string}>
     */
    public static function workspaceRoutes(): array
    {
        return array_map(static fn (string $name): array => [$name], [
            'app.home',
            'app.inbox',
            'app.my-tasks',
            'app.calendar',
            'app.reports',
            'app.ai',
            'app.teams',
            'app.settings',
            'app.projects.index',
            'app.projects.create',
            'app.projects.show',
            'app.projects.tasks',
            'app.projects.board',
            'app.projects.calendar',
            'app.projects.timeline',
            'app.projects.files',
            'app.projects.wiki',
            'app.projects.wiki.show',
            'app.projects.activity',
            'app.projects.settings',
            'app.projects.ai',
            'app.tasks.show',
        ]);
    }

    #[Test]
    #[DataProvider('workspaceRoutes')]
    public function a_member_reaches_every_application_route(string $name): void
    {
        $this->actingAs($this->member)
            ->get(route($name, $this->parametersFor($name)))
            ->assertOk();
    }

    #[Test]
    #[DataProvider('workspaceRoutes')]
    public function somebody_from_another_workspace_reaches_none_of_them(string $name): void
    {
        $this->assertDeniedAccess($this->outsider, route($name, $this->parametersFor($name)));
    }

    #[Test]
    public function the_account_routes_render_for_a_member(): void
    {
        foreach (['profile.edit', 'profile.notifications', 'profile.security', 'workspaces.create'] as $name) {
            $this->actingAs($this->member)->get(route($name))->assertOk();
        }
    }

    /* ------------------------------------------------------------------ *
     * The root
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_root_forwards_to_the_last_used_workspace(): void
    {
        $this->actingAs($this->member)
            ->get('/')
            ->assertRedirect(route('app.home', $this->workspace));
    }

    #[Test]
    public function the_root_forwards_an_account_with_no_workspace_to_onboarding(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get('/')
            ->assertRedirect(route('workspaces.create'));
    }

    #[Test]
    public function a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('app.home', $this->workspace))->assertRedirect(route('login'));
    }

    /* ------------------------------------------------------------------ *
     * Scoped bindings
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_project_from_another_workspace_does_not_resolve_under_this_one(): void
    {
        $foreign = $this->makeProject($this->makeWorkspace(), [], ['slug' => 'foreign-project']);

        // The member is an owner here, so a 403 would mean the binding resolved and only
        // the policy stopped it. Scoped bindings make it a 404: within this workspace that
        // slug does not exist.
        $this->actingAs($this->member)
            ->get('/w/'.$this->workspace->slug.'/projects/'.$foreign->slug)
            ->assertNotFound();

        $this->assertDatabaseHas('projects', ['id' => $foreign->getKey()]);
    }

    #[Test]
    public function a_task_from_another_workspace_does_not_resolve_under_this_one(): void
    {
        $foreignProject = $this->makeProject($this->makeWorkspace());
        $foreignTask = $this->makeTask($foreignProject);

        $this->actingAs($this->member)
            ->get('/w/'.$this->workspace->slug.'/tasks/'.$foreignTask->getKey())
            ->assertNotFound();
    }

    #[Test]
    public function a_wiki_page_from_another_project_does_not_resolve_under_this_one(): void
    {
        $otherProject = $this->makeProject($this->workspace, [], ['slug' => 'other', 'key' => 'OTH']);

        $otherPage = WikiPage::factory()
            ->forProject($otherProject)
            ->create(['slug' => 'somewhere-else', 'author_id' => $this->member->getKey()]);

        $this->actingAs($this->member)
            ->get('/w/'.$this->workspace->slug.'/projects/'.$this->project->slug.'/wiki/'.$otherPage->slug)
            ->assertNotFound();
    }

    #[Test]
    public function an_unknown_workspace_slug_is_a_not_found(): void
    {
        $this->actingAs($this->member)->get('/w/no-such-workspace')->assertNotFound();
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * @return list<mixed>
     */
    private function parametersFor(string $name): array
    {
        return match (true) {
            $name === 'app.tasks.show' => [$this->workspace, $this->task],
            $name === 'app.projects.wiki.show' => [$this->workspace, $this->project, $this->page],
            str_starts_with($name, 'app.projects.') && $name !== 'app.projects.index' && $name !== 'app.projects.create' => [$this->workspace, $this->project],
            default => [$this->workspace],
        };
    }
}

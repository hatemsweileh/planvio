<?php

declare(strict_types=1);

namespace Tests\Feature\App;

use App\Enums\WorkspaceRole;
use App\Livewire\App\Shared\CommandPalette;
use App\Livewire\App\Shared\NotificationsMenu;
use App\Models\Favorite;
use App\Models\Project;
use App\Models\RecentItem;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The application shell: the sidebar data every page carries, the command palette and the
 * notifications menu.
 *
 * The query budget is the interesting assertion. The sidebar renders on every request in
 * the product, so anything it costs is paid on every page — and the easy way to lose that
 * is a partial that quietly queries. A ceiling in a test is the only thing that keeps a
 * later "just one more list in the sidebar" honest.
 */
final class ShellTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->makeWorkspace(['slug' => 'acme']);
        $this->member = $this->makeMember($this->workspace, WorkspaceRole::Owner);
    }

    /* ------------------------------------------------------------------ *
     * Shared data and its cost
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_sidebar_data_is_shared_with_every_view(): void
    {
        $starred = $this->makeProject($this->workspace, [], ['name' => 'Starred project', 'slug' => 'starred', 'key' => 'STR']);
        $opened = $this->makeProject($this->workspace, [], ['name' => 'Opened project', 'slug' => 'opened', 'key' => 'OPN']);
        $untouched = $this->makeProject($this->workspace, [], ['name' => 'Untouched project', 'slug' => 'untouched', 'key' => 'UNT']);

        $this->star($starred);
        $this->open($opened);

        $response = $this->actingAs($this->member)->get(route('app.home', $this->workspace));

        $response->assertOk();

        // The middleware shares these with every view in the request; a Livewire page has
        // no view data of its own to read them back from.
        $favourites = view()->shared('sidebarFavourites');
        $recents = view()->shared('sidebarRecents');

        $this->assertSame(['Starred project'], $favourites->pluck('name')->all());
        $this->assertSame(['Opened project'], $recents->pluck('name')->all());

        // A project nobody starred or opened is not sidebar material.
        $this->assertNotContains($untouched->name, $recents->pluck('name')->all());
        $this->assertNotContains($untouched->name, $favourites->pluck('name')->all());
    }

    #[Test]
    public function an_archived_project_leaves_the_sidebar(): void
    {
        $archived = $this->makeProject($this->workspace, [], ['slug' => 'gone', 'key' => 'GON', 'is_archived' => true]);

        $this->star($archived);
        $this->open($archived);

        $this->actingAs($this->member)->get(route('app.home', $this->workspace))->assertOk();

        $this->assertCount(0, view()->shared('sidebarFavourites'));
        $this->assertCount(0, view()->shared('sidebarRecents'));
    }

    /**
     * The shell's three queries — switcher, sidebar projects, unread count — are measured as
     * a *difference* rather than as a ceiling, because the page they are measured through is
     * no longer empty: `app.home` is the dashboard, and its own fixed cost lands in the same
     * request. What has to hold is that neither the shell nor the page pays more for a
     * workspace with eleven projects than for one with a single project. A count that grows
     * is the N+1 this test exists to catch; a constant is the property that matters.
     */
    #[Test]
    public function the_shell_costs_the_same_however_many_projects_there_are(): void
    {
        // One project, starred and opened: the smallest shape the sidebar actually draws.
        $first = $this->makeProject($this->workspace, [], [
            'name' => 'Project 0',
            'slug' => 'project-0',
            'key' => 'P0',
        ]);

        $this->star($first);
        $this->open($first);

        $this->seedNotifications(4);

        // A second workspace, so the switcher has something to list.
        $second = $this->makeWorkspace(['slug' => 'northwind']);
        $second->addMember($this->member->fresh(), WorkspaceRole::Member);

        $withOne = $this->countShellQueries();

        // Ten more, all starred and all opened: the shape that would expose an N+1 in the
        // sidebar, the switcher or the palette.
        foreach (range(1, 10) as $n) {
            $project = $this->makeProject($this->workspace, [], [
                'name' => 'Project '.$n,
                'slug' => 'project-'.$n,
                'key' => 'P'.$n,
            ]);

            $this->star($project);
            $this->open($project);
        }

        $withMany = $this->countShellQueries();

        $this->assertSame(
            $withOne,
            $withMany,
            "One project cost {$withOne} queries and eleven cost {$withMany}. The switcher, the "
            .'sidebar projects and the unread count are one query each however long the lists '
            .'get; a difference means a partial is querying per row.',
        );
    }

    /* ------------------------------------------------------------------ *
     * Command palette
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_palette_opens_on_recent_work_before_anything_is_typed(): void
    {
        $project = $this->makeProject($this->workspace, [], ['name' => 'Website', 'slug' => 'website', 'key' => 'WEB']);

        $this->inWorkspace($this->workspace, function () use ($project): void {
            Livewire::actingAs($this->member)
                ->test(CommandPalette::class, [
                    'recent' => [[
                        'id' => (int) $project->getKey(),
                        'name' => $project->name,
                        'slug' => $project->slug,
                        'color' => $project->color,
                        'starred' => false,
                    ]],
                ])
                ->assertSee('Website')
                ->assertSee('Jump back in')
                ->assertSee('Go to my tasks');
        });
    }

    #[Test]
    public function typing_searches_across_the_workspace(): void
    {
        $project = $this->makeProject($this->workspace, [], ['name' => 'Rebrand', 'slug' => 'rebrand', 'key' => 'RBR']);
        $this->makeTask($project, ['title' => 'Rebrand the invoice template']);

        $this->inWorkspace($this->workspace, function (): void {
            Livewire::actingAs($this->member)
                ->test(CommandPalette::class)
                ->set('query', 'rebrand')
                ->assertSee('Rebrand the invoice template')
                ->assertSee('Ask Planvio AI');
        });
    }

    #[Test]
    public function the_palette_never_shows_another_workspaces_work(): void
    {
        $foreignWorkspace = $this->makeWorkspace(['slug' => 'foreign']);
        $foreignProject = $this->makeProject($foreignWorkspace, [], ['name' => 'Confidential merger', 'slug' => 'merger', 'key' => 'MRG']);
        $this->makeTask($foreignProject, ['title' => 'Confidential merger diligence']);

        $this->inWorkspace($this->workspace, function (): void {
            Livewire::actingAs($this->member)
                ->test(CommandPalette::class)
                ->set('query', 'confidential')
                ->assertDontSee('Confidential merger diligence')
                ->assertDontSee('merger');
        });
    }

    #[Test]
    public function a_member_without_ai_access_is_not_offered_the_assistant(): void
    {
        $guest = $this->makeMember($this->workspace, WorkspaceRole::Guest);

        $this->inWorkspace($this->workspace, function () use ($guest): void {
            Livewire::actingAs($guest)
                ->test(CommandPalette::class)
                ->set('query', 'anything at all')
                ->assertDontSee('Ask Planvio AI');
        });
    }

    /* ------------------------------------------------------------------ *
     * Notifications
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_menu_fetches_nothing_until_it_is_opened(): void
    {
        $this->seedNotifications(3);

        $this->inWorkspace($this->workspace, function (): void {
            $component = Livewire::actingAs($this->member)
                ->test(NotificationsMenu::class, ['unread' => 3]);

            $component->assertDontSee('Something happened 1');

            $component->call('load')->assertSee('Something happened 1');
        });
    }

    #[Test]
    public function marking_one_read_lowers_the_badge(): void
    {
        $ids = $this->seedNotifications(2);

        $this->inWorkspace($this->workspace, function () use ($ids): void {
            Livewire::actingAs($this->member)
                ->test(NotificationsMenu::class, ['unread' => 2])
                ->call('load')
                ->call('markRead', $ids[0])
                ->assertSet('unread', 1);
        });

        $this->assertNotNull(DB::table('notifications')->where('id', $ids[0])->value('read_at'));
        $this->assertNull(DB::table('notifications')->where('id', $ids[1])->value('read_at'));
    }

    #[Test]
    public function marking_all_read_clears_the_badge(): void
    {
        $this->seedNotifications(3);

        $this->inWorkspace($this->workspace, function (): void {
            Livewire::actingAs($this->member)
                ->test(NotificationsMenu::class, ['unread' => 3])
                ->call('load')
                ->call('markAllRead')
                ->assertSet('unread', 0)
                ->assertDispatched('planvio-notify');
        });

        $this->assertSame(0, DB::table('notifications')->whereNull('read_at')->count());
    }

    #[Test]
    public function one_person_cannot_mark_another_persons_notification_read(): void
    {
        $other = $this->makeMember($this->workspace);
        $ids = $this->seedNotifications(1, $other);

        $this->inWorkspace($this->workspace, function () use ($ids): void {
            Livewire::actingAs($this->member)
                ->test(NotificationsMenu::class, ['unread' => 0])
                ->call('load')
                ->call('markRead', $ids[0]);
        });

        $this->assertNull(DB::table('notifications')->where('id', $ids[0])->value('read_at'));
    }

    #[Test]
    public function an_ai_notification_is_marked_as_one(): void
    {
        $this->seedNotifications(1, null, true);

        $this->inWorkspace($this->workspace, function (): void {
            Livewire::actingAs($this->member)
                ->test(NotificationsMenu::class, ['unread' => 1])
                ->call('load')
                ->assertSee('AI');
        });
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * The number of queries a full shell render costs, minus the ones that are not the
     * shell's: resolving the session user, binding the workspace from the slug and checking
     * membership all happen before any of this is shared.
     */
    private function countShellQueries(): int
    {
        // Warm the route and view caches so compilation is not counted as a query.
        $this->actingAs($this->member)->get(route('app.home', $this->workspace))->assertOk();

        $queries = [];

        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->actingAs($this->member)->get(route('app.home', $this->workspace))->assertOk();

        $shellQueries = array_values(array_filter($queries, static function (string $sql): bool {
            // Authentication and tenant resolution are the cost of being signed in at all,
            // not the cost of drawing the shell.
            return ! str_contains($sql, 'from "users"')
                && ! str_contains($sql, 'from "sessions"')
                && ! (str_contains($sql, 'from "workspaces"') && ! str_contains($sql, 'join "workspace_members"'))
                && ! (str_contains($sql, 'from "workspace_members"') && ! str_contains($sql, 'join'))
                && ! str_starts_with($sql, 'update');
        }));

        return count($shellQueries);
    }

    private function star(Project $project): void
    {
        Favorite::query()->create([
            'user_id' => $this->member->getKey(),
            'favoritable_type' => $project->getMorphClass(),
            'favoritable_id' => $project->getKey(),
            'position' => 0,
        ]);
    }

    private function open(Project $project): void
    {
        RecentItem::withoutWorkspaceScope()->create([
            'user_id' => $this->member->getKey(),
            'workspace_id' => $project->workspace_id,
            'viewable_type' => $project->getMorphClass(),
            'viewable_id' => $project->getKey(),
            'viewed_at' => Carbon::now(),
        ]);
    }

    /**
     * @return list<string> the ids written, newest last
     */
    private function seedNotifications(int $count, ?User $for = null, bool $ai = false): array
    {
        $user = $for ?? $this->member;
        $ids = [];

        foreach (range(1, $count) as $n) {
            $id = (string) Str::uuid();
            $ids[] = $id;

            DB::table('notifications')->insert([
                'id' => $id,
                'type' => Task::class,
                'notifiable_type' => $user->getMorphClass(),
                'notifiable_id' => $user->getKey(),
                'data' => json_encode([
                    'title' => 'Something happened '.$n,
                    'body' => 'A short description of event '.$n,
                    'url' => route('app.home', $this->workspace),
                    'actor_name' => 'Dana Reed',
                ], JSON_THROW_ON_ERROR),
                'read_at' => null,
                'workspace_id' => $this->workspace->getKey(),
                'project_id' => null,
                'category' => $ai ? 'ai.action_executed' : 'task.assigned',
                'is_ai' => $ai,
                'created_at' => Carbon::now()->subMinutes($count - $n),
                'updated_at' => Carbon::now()->subMinutes($count - $n),
            ]);
        }

        return $ids;
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Projects\CreateProject;
use App\Actions\Projects\ProjectAttributes;
use App\Actions\Recurring\RecurringTaskTemplate;
use App\Actions\Workspaces\CreateWorkspace;
use App\Actions\Workspaces\WorkspaceAttributes;
use App\Enums\AuthorType;
use App\Enums\DependencyType;
use App\Enums\MilestoneStatus;
use App\Enums\Priority;
use App\Enums\ProjectHealth;
use App\Enums\ProjectRole;
use App\Enums\ProjectType;
use App\Enums\RecurrenceFrequency;
use App\Enums\ViewType;
use App\Enums\WikiVisibility;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\Expense;
use App\Models\Favorite;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\RecentItem;
use App\Models\RecurringTask;
use App\Models\SavedView;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskDependency;
use App\Models\TaskStatus;
use App\Models\TaskWatcher;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WikiPage;
use App\Models\Workspace;
use App\Services\ActivityLogger;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The optional demo workspace (master specification §93).
 *
 * This seeder is never reached by `db:seed` or `migrate --seed`: {@see DatabaseSeeder} calls
 * {@see DefaultDataSeeder} and nothing else. Demo data arrives only through `planvio:demo`,
 * which refuses to run in production without `--force`, or by naming this class explicitly
 * — and `db:seed` already demands confirmation in production before it will do that.
 *
 * What it builds is a workspace three weeks into its life rather than a screenshot: work is
 * finished, in flight, blocked and overdue at the same time; one project is behind and says
 * why; time has been logged against real tasks and one timer is still running. A demo where
 * every bar is green teaches nobody what the product does on a bad Tuesday.
 *
 * Everything is written inside one transaction and recorded in a settings row, so
 * `planvio:demo --remove` can take exactly this data back out and nothing else.
 *
 * # No factories here, deliberately
 *
 * Model factories look like the obvious tool and cannot be used. `fakerphp/faker` is a
 * `require-dev` dependency, Laravel defines the `fake()` helper only
 * `if (class_exists(\Faker\Factory::class))`, and every release ships `vendor/` built with
 * `composer install --no-dev`. So on the installations this command exists to serve —
 * a real one, on shared hosting, where the customer cannot run Composer — the helper is
 * simply not defined and the first factory call is a fatal error.
 *
 * That is what made this worth fixing rather than documenting: the command is shipped, is
 * advertised in the README, and has an explicit `--force` path for production, and it had
 * never once been able to run there. The test suite could not see it, because tests run
 * with dev dependencies present.
 *
 * Nothing was lost in the change. Every faker-generated value at these four call sites was
 * already being overridden by the seeder — the demo's names, amounts and dates are all
 * written by hand, because a demo assembled from random words teaches nobody anything.
 * Faker was computing values that were thrown away.
 */
final class DemoDataSeeder extends Seeder
{
    public const WORKSPACE_NAME = 'Acme Company';

    public const WORKSPACE_SLUG = 'acme-company';

    /**
     * Printed at the end of the install and never used anywhere else. Long enough for the
     * shipped password policy (ten characters, mixed case, digits) so the demo accounts do
     * not need an exception to the rule everybody else is held to.
     */
    public const PASSWORD = 'PlanvioDemo123';

    /**
     * Where the installed-demo marker lives, so removal is exact rather than a guess based
     * on a workspace name somebody may legitimately have chosen for themselves.
     */
    public const SETTINGS_KEY = 'demo.installation';

    private const EMAIL_DOMAIN = 'planvio.test';

    /** @var array<string, User> */
    private array $people = [];

    /** @var array<string, Tag> */
    private array $tags = [];

    /** @var array<string, TaskStatus> */
    private array $statuses = [];

    public function __construct(
        private readonly Settings $settings,
        private readonly CreateWorkspace $createWorkspace,
        private readonly CreateProject $createProject,
        private readonly ActivityLogger $activity,
    ) {}

    /* ------------------------------------------------------------------ *
     * Install
     * ------------------------------------------------------------------ */

    public function run(): void
    {
        if ($this->installation() !== null) {
            $this->command?->getOutput()->writeln(
                '  <fg=yellow>!</> '.__('Demo data is already installed. Remove it first with `php artisan planvio:demo --remove`.'),
            );

            return;
        }

        $this->assertEmailsAvailable();

        $installed = DB::transaction(function (): array {
            $this->people = $this->createPeople();
            $workspace = $this->createDemoWorkspace();

            $this->createTeam($workspace);
            $this->createTags($workspace);

            $website = $this->createWebsiteRedesign($workspace);
            $marketing = $this->createMarketingCampaign($workspace);
            $launch = $this->createProductLaunch($workspace);

            $this->createWorkspaceWiki($workspace);
            $this->createAuditTrail($workspace);
            $this->createShortcuts($workspace, [$website, $marketing, $launch]);

            return [
                'workspace_id' => (int) $workspace->getKey(),
                'user_ids' => array_map(static fn (User $user): int => (int) $user->getKey(), array_values($this->people)),
                'installed_at' => Carbon::now()->toIso8601String(),
            ];
        });

        $this->settings->set(self::SETTINGS_KEY, $installed);
        $this->settings->flush();

        $this->printCredentials();
    }

    /* ------------------------------------------------------------------ *
     * Remove
     * ------------------------------------------------------------------ */

    /**
     * Take the demo data back out. Returns false when none was installed by this seeder.
     *
     * Deleting the workspace does most of the work: every tenant table carries a
     * `workspace_id` with `cascadeOnDelete`, and the join tables that do not — `taggables`,
     * `comment_reactions`, `task_checklist_items` — cascade from their own parent. The
     * accounts are removed afterwards, which also clears the per-user rows (favourites,
     * recent items) that hang off a user rather than off a workspace.
     */
    public function remove(): bool
    {
        $installation = $this->installation();

        if ($installation === null) {
            return false;
        }

        DB::transaction(function () use ($installation): void {
            // Audit rows deliberately survive the workspace they describe — their foreign key
            // is nullOnDelete, because a security trail that disappears with its subject is
            // not a trail. Demo rows are not evidence of anything, so they go first, by id,
            // while they can still be identified.
            AuditLog::query()->where('workspace_id', $installation['workspace_id'])->delete();

            $workspace = Workspace::withTrashed()->find($installation['workspace_id']);

            $workspace?->forceDelete();

            $users = User::withTrashed()
                ->whereIn('id', $installation['user_ids'])
                ->get();

            foreach ($users as $user) {
                $user->forceDelete();
            }
        });

        $this->settings->forget(self::SETTINGS_KEY);
        $this->settings->flush();

        return true;
    }

    /**
     * The stored marker, or null when the demo is not installed — or when its workspace has
     * since been deleted by hand, which leaves the marker meaningless.
     *
     * @return array{workspace_id: int, user_ids: list<int>, installed_at: string}|null
     */
    public function installation(): ?array
    {
        $stored = $this->settings->get(self::SETTINGS_KEY);

        if (! is_array($stored) || ! isset($stored['workspace_id'])) {
            return null;
        }

        $workspaceId = (int) $stored['workspace_id'];

        $exists = Workspace::withTrashed()->whereKey($workspaceId)->exists();

        if (! $exists) {
            return null;
        }

        $userIds = [];

        foreach ((array) ($stored['user_ids'] ?? []) as $id) {
            if (is_numeric($id)) {
                $userIds[] = (int) $id;
            }
        }

        return [
            'workspace_id' => $workspaceId,
            'user_ids' => $userIds,
            'installed_at' => (string) ($stored['installed_at'] ?? ''),
        ];
    }

    /**
     * The accounts the demo creates, in the order they are printed.
     *
     * @return list<array{key: string, name: string, email: string, title: string, role: WorkspaceRole, platform_admin: bool}>
     */
    public static function accounts(): array
    {
        return [
            [
                'key' => 'admin',
                'name' => 'Dana Whitfield',
                'email' => 'admin@'.self::EMAIL_DOMAIN,
                'title' => 'Operations Director',
                'role' => WorkspaceRole::Owner,
                'platform_admin' => true,
            ],
            [
                'key' => 'manager',
                'name' => 'Marcus Reyes',
                'email' => 'manager@'.self::EMAIL_DOMAIN,
                'title' => 'Delivery Manager',
                'role' => WorkspaceRole::Manager,
                'platform_admin' => false,
            ],
            [
                'key' => 'member',
                'name' => 'Priya Nadar',
                'email' => 'member@'.self::EMAIL_DOMAIN,
                'title' => 'Senior Designer',
                'role' => WorkspaceRole::Member,
                'platform_admin' => false,
            ],
        ];
    }

    /* ------------------------------------------------------------------ *
     * People and tenancy
     * ------------------------------------------------------------------ */

    /**
     * Refuse rather than collide: these addresses are fixed, so an existing account on one
     * of them belongs to somebody else and must not be quietly reused or overwritten.
     */
    private function assertEmailsAvailable(): void
    {
        $emails = array_column(self::accounts(), 'email');

        $taken = User::withTrashed()
            ->whereIn('email', $emails)
            ->pluck('email')
            ->all();

        if ($taken !== []) {
            throw new RuntimeException(
                'Cannot install demo data: these accounts already exist — '.implode(', ', $taken).'.',
            );
        }
    }

    /**
     * @return array<string, User>
     */
    private function createPeople(): array
    {
        $people = [];

        foreach (self::accounts() as $account) {
            $user = new User;

            /*
             | `forceFill` rather than `create`: `email_verified_at`, `remember_token`,
             | `last_login_at` and `last_login_ip` are deliberately not mass-assignable,
             | and `create()` would drop them without a word. Factories get away with the
             | same attributes because `Factory::make()` wraps instantiation in
             | `Model::unguarded()`; this says so out loud instead.
             |
             | The `hashed` cast still applies, so the password is stored hashed exactly
             | as it was when this went through the factory.
             */
            $user->forceFill([
                'name' => $account['name'],
                'email' => $account['email'],
                'email_verified_at' => now(),
                'password' => self::PASSWORD,
                'job_title' => $account['title'],
                'is_admin' => $account['platform_admin'],
                'is_active' => true,
                'timezone' => 'UTC',
                'locale' => 'en',
                'theme' => 'system',
                'remember_token' => Str::random(10),
                'last_login_at' => $this->at(-1, 8, 42),
                'last_login_ip' => '127.0.0.1',
            ])->save();

            $people[$account['key']] = $user;
        }

        return $people;
    }

    private function createDemoWorkspace(): Workspace
    {
        $workspace = ($this->createWorkspace)(
            $this->person('admin'),
            new WorkspaceAttributes(
                name: self::WORKSPACE_NAME,
                slug: self::WORKSPACE_SLUG,
                description: 'A small agency running client delivery, marketing and a product launch side by side.',
                accentColor: '#3F66B0',
                timezone: 'UTC',
                locale: 'en',
                currency: 'USD',
                dateFormat: 'Y-m-d',
                weekStartsOn: 1,
            ),
        );

        foreach (self::accounts() as $account) {
            if ($account['role'] === WorkspaceRole::Owner) {
                continue;
            }

            $workspace->members()->attach($this->person($account['key'])->getKey(), [
                'role' => $account['role']->value,
                'title' => $account['title'],
                'joined_at' => $this->at(-52, 9, 0),
                'last_active_at' => $this->at(0, 9, 12),
                'created_at' => $this->at(-52, 9, 0),
                'updated_at' => $this->at(0, 9, 12),
            ]);
        }

        return $workspace->refresh();
    }

    private function createTeam(Workspace $workspace): void
    {
        $team = Team::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Delivery',
            'slug' => 'delivery',
            'description' => 'Everyone who ships client work.',
            'color' => 'brand',
        ]);

        foreach (['admin' => false, 'manager' => true, 'member' => false] as $key => $isLead) {
            TeamMember::query()->create([
                'team_id' => $team->getKey(),
                'user_id' => $this->person($key)->getKey(),
                'is_lead' => $isLead,
            ]);
        }
    }

    /**
     * The workspace already has the shipped default tags. These are the ones this particular
     * business would have added for itself.
     */
    private function createTags(Workspace $workspace): void
    {
        foreach (Tag::withoutWorkspaceScope()->where('workspace_id', $workspace->getKey())->get() as $tag) {
            $this->tags[mb_strtolower((string) $tag->name)] = $tag;
        }

        $extra = [
            'Content' => 'blue',
            'Development' => 'brand',
            'SEO' => 'green',
            'Accessibility' => 'purple',
            'Research' => 'amber',
            'Launch' => 'pink',
        ];

        foreach ($extra as $name => $color) {
            $key = mb_strtolower($name);

            if (isset($this->tags[$key])) {
                continue;
            }

            $this->tags[$key] = Tag::query()->create([
                'workspace_id' => $workspace->getKey(),
                'name' => $name,
                'slug' => Str::slug($name),
                'color' => $color,
                'description' => null,
            ]);
        }
    }

    /* ------------------------------------------------------------------ *
     * Project: Website Redesign — healthy, mid-build
     * ------------------------------------------------------------------ */

    private function createWebsiteRedesign(Workspace $workspace): Project
    {
        $project = $this->makeProject($workspace, new ProjectAttributes(
            name: 'Website Redesign',
            key: 'WEB',
            description: 'Rebuild northwindtrading.com on the new design system: faster, accessible, and editable by the client without us.',
            icon: '🌐',
            color: '#1D4ED8',
            type: ProjectType::Creative,
            statusId: $this->projectStatusId($workspace, 'Active'),
            health: ProjectHealth::OnTrack,
            priority: Priority::High,
            managerId: (int) $this->person('manager')->getKey(),
            clientName: 'Northwind Trading',
            department: 'Client Services',
            startDate: $this->day(-45),
            targetDate: $this->day(25),
            budget: '48000.00',
            currency: 'USD',
        ));

        $milestones = $this->createMilestones($project, [
            ['key' => 'discovery', 'name' => 'Discovery complete', 'description' => 'Goals, audiences, sitemap and requirements agreed.', 'status' => MilestoneStatus::Completed, 'start' => -45, 'due' => -32, 'completed' => -31, 'owner' => 'admin'],
            ['key' => 'design', 'name' => 'Design signed off', 'description' => 'Every template designed, reviewed and approved in writing.', 'status' => MilestoneStatus::Completed, 'start' => -31, 'due' => -14, 'completed' => -12, 'owner' => 'member'],
            ['key' => 'build', 'name' => 'Build complete', 'description' => 'Templates built, wired to the CMS and through QA.', 'status' => MilestoneStatus::InProgress, 'start' => -11, 'due' => 10, 'owner' => 'manager'],
            ['key' => 'launch', 'name' => 'Launch', 'description' => 'Live on the client domain with redirects and analytics working.', 'status' => MilestoneStatus::Planned, 'start' => 11, 'due' => 25, 'owner' => 'manager'],
        ]);

        $tasks = $this->createTasks($project, $milestones, [
            $this->spec('Interview the client stakeholders', 'Completed', milestone: 'discovery', assignee: 'admin', created: -45, start: -44, due: -38, completed: -38, estimate: 480, tags: ['Research'],
                description: 'Six conversations across sales, support and the warehouse team. Notes are in the project wiki.'),
            $this->spec('Audit the current site content and analytics', 'Completed', milestone: 'discovery', assignee: 'member', created: -45, start: -44, due: -36, completed: -35, estimate: 420, tags: ['Content', 'SEO'],
                checklist: [['Page inventory exported', true], ['Traffic per page pulled', true], ['Keep, rewrite or retire decided', true]]),
            $this->spec('Agree the sitemap and navigation model', 'Completed', milestone: 'discovery', assignee: 'member', created: -40, start: -36, due: -32, completed: -32, estimate: 300, tags: ['Content']),
            $this->spec('Build the design system', 'Completed', milestone: 'design', assignee: 'member', created: -32, start: -30, due: -20, completed: -19, estimate: 900, tags: ['Design'],
                description: 'Type scale, colour palette with contrast checked, spacing scale and the twelve core components.',
                checklist: [['Type scale', true], ['Colour palette', true], ['Spacing scale', true], ['Core components', true], ['Dark mode decided', true]]),
            $this->spec('Design the six key page templates', 'Completed', milestone: 'design', assignee: 'member', created: -30, start: -22, due: -14, completed: -13, estimate: 1200, tags: ['Design'], priority: Priority::High),
            $this->spec('Set up the repository, environments and deploys', 'Completed', milestone: 'build', assignee: 'manager', created: -25, start: -14, due: -8, completed: -8, estimate: 360, tags: ['Development']),
            $this->spec('Build the page templates', 'In Progress', milestone: 'build', assignee: 'manager', created: -20, start: -11, due: 6, estimate: 1920, progress: 55, tags: ['Development'], priority: Priority::High,
                description: 'Four of the six templates are built and reviewed. The product listing and checkout templates are left.',
                watchers: ['admin', 'member']),
            $this->spec('Migrate the blog archive into the new CMS', 'In Progress', milestone: 'build', assignee: 'admin', created: -18, start: -6, due: 3, estimate: 600, progress: 30, tags: ['Content'],
                description: 'Two hundred and eleven posts. The importer handles the body; images and author records need checking by hand.'),
            $this->spec('Accessibility audit against WCAG 2.2 AA', 'Review', milestone: 'build', assignee: 'member', created: -14, start: -3, due: 2, estimate: 480, progress: 80, tags: ['Accessibility'], priority: Priority::High,
                description: 'Keyboard only, screen reader, contrast, focus order and form error announcements.',
                checklist: [['Keyboard navigation', true], ['Contrast checked', true], ['Screen reader pass', false], ['Forms labelled and errors announced', false]]),
            $this->spec('Connect the payment provider sandbox', 'Blocked', milestone: 'build', assignee: 'manager', created: -16, start: -10, due: -2, estimate: 240, progress: 20, tags: ['Development', 'Client'], priority: Priority::High,
                description: 'Waiting on sandbox credentials from the client\'s finance team. Chased twice; escalated to their sponsor.'),
            $this->spec('Sign off the photography shortlist', 'To Do', milestone: 'build', assignee: 'member', created: -12, due: -4, estimate: 120, tags: ['Design', 'Client'],
                description: 'Twelve images shortlisted. The client asked for a second option on the warehouse shot.'),
            $this->spec('Write and test the redirect map', 'To Do', milestone: 'launch', assignee: 'admin', created: -10, start: 8, due: 12, estimate: 360, tags: ['SEO'], priority: Priority::High,
                description: 'Every old URL with traffic or a backlink maps to its new home. This is where rankings get lost.',
                checklist: [['Old URLs exported', false], ['Destination decided per URL', false], ['Redirects implemented', false], ['Tested from the live sitemap', false]]),
            $this->spec('Load the content into the CMS and proof it', 'To Do', milestone: 'launch', assignee: 'member', created: -10, start: 7, due: 15, estimate: 720, tags: ['Content']),
            $this->spec('Work through the launch checklist and cut over DNS', 'To Do', milestone: 'launch', assignee: 'manager', created: -8, start: 22, due: 24, estimate: 300, tags: ['Development'], priority: Priority::Urgent,
                checklist: [['Backup taken', false], ['SSL certificate valid', false], ['robots.txt correct', false], ['404 page in place', false], ['Forms tested on production', false]],
                watchers: ['admin']),
            $this->spec('Add a customer story template', 'Backlog', assignee: null, created: -6, priority: Priority::Low, estimate: 240, tags: ['Design']),
            $this->spec('Investigate a hosted search provider', 'Backlog', assignee: null, created: -5, priority: Priority::Low, estimate: 180, tags: ['Development', 'Research']),
        ]);

        $this->createDependencies($project, [
            ['Load the content into the CMS and proof it', 'Build the page templates', DependencyType::FinishToStart],
            ['Work through the launch checklist and cut over DNS', 'Write and test the redirect map', DependencyType::FinishToStart],
            ['Work through the launch checklist and cut over DNS', 'Connect the payment provider sandbox', DependencyType::Blocks],
            ['Accessibility audit against WCAG 2.2 AA', 'Build the page templates', DependencyType::RelatesTo],
        ], $tasks);

        $this->createComments($tasks, [
            ['Build the page templates', 'admin', 'Product listing template is the risky one — it has four filter states nobody has designed yet. Can we get a decision this week?', -4],
            ['Build the page templates', 'manager', 'Priya sketched the four states yesterday. I will pick them up on Thursday once the archive migration is off my desk.', -3],
            ['Connect the payment provider sandbox', 'manager', 'Still nothing from their finance team. I have asked their sponsor directly and set a reminder for Friday.', -2],
            ['Connect the payment provider sandbox', 'admin', 'Flagging this on the weekly call. If the credentials do not land by Monday the launch date moves — better to say that now than in three weeks.', -1],
            ['Accessibility audit against WCAG 2.2 AA', 'member', 'Contrast and keyboard passes are clean. The screen reader run found two unlabelled form controls on the contact page; raising them as fixes rather than blocking the audit.', -1],
            ['Sign off the photography shortlist', 'member', 'Second warehouse option sent to the client this morning.', 0],
        ]);

        $this->createTimeEntries([
            ['Build the page templates', 'manager', -12, 390], ['Build the page templates', 'manager', -11, 420],
            ['Build the page templates', 'manager', -8, 360], ['Build the page templates', 'manager', -5, 300],
            ['Build the page templates', 'manager', -2, 270],
            ['Design the six key page templates', 'member', -20, 420], ['Design the six key page templates', 'member', -18, 480],
            ['Design the six key page templates', 'member', -16, 360],
            ['Build the design system', 'member', -28, 450], ['Build the design system', 'member', -25, 480],
            ['Migrate the blog archive into the new CMS', 'admin', -6, 240], ['Migrate the blog archive into the new CMS', 'admin', -3, 180],
            ['Accessibility audit against WCAG 2.2 AA', 'member', -3, 300], ['Accessibility audit against WCAG 2.2 AA', 'member', -1, 180],
            ['Interview the client stakeholders', 'admin', -42, 300], ['Interview the client stakeholders', 'admin', -40, 240],
        ], $tasks);

        $running = $tasks['Build the page templates'];

        TimeEntry::create([
            'workspace_id' => $running->workspace_id,
            'project_id' => $running->project_id,
            'task_id' => $running->getKey(),
            'user_id' => $this->person('manager')->getKey(),
            'minutes' => 0,
            'description' => 'Product listing template — filter states',
            'spent_on' => Carbon::today()->toDateString(),
            'started_at' => now()->subMinutes(25),
            'ended_at' => null,
            'is_running' => true,
            'is_billable' => true,
        ]);

        $this->createExpenses($project, [
            ['Stock photography licence — twelve images', 'software', '640.00', -18],
            ['CMS annual licence, client rebilled', 'software', '1200.00', -30],
            ['Accessibility audit tooling', 'software', '290.00', -9],
        ]);

        $this->createProjectWiki($project, 'Working agreements', 'working-agreements', 'How this project runs day to day: the call, the definition of done, the escalation path.', <<<'HTML'
            <h2>How we run this project</h2>
            <p>Weekly client call on Tuesdays at 14:00 UTC. Marcus chairs it, Dana takes the actions and adds them here the same day.</p>
            <h3>Definition of done</h3>
            <ul>
              <li>Reviewed by a second person.</li>
              <li>Checked at 360px, 768px and 1280px.</li>
              <li>Keyboard reachable, with a visible focus state.</li>
              <li>Content loaded — a template with placeholder text is not done.</li>
            </ul>
            <h3>Environments</h3>
            <p>Staging is rebuilt on every merge. Production is deployed by hand, by Marcus, never on a Friday.</p>
            <h3>Escalation</h3>
            <p>Anything blocked for more than two working days goes on the weekly call agenda, not into a private message.</p>
            HTML);

        $this->createViews($project, [
            ['Board', ViewType::Board, [], 'status', true],
            ['Overdue and blocked', ViewType::List, ['status' => ['blocked'], 'overdue' => true], null, true],
            ['Launch plan', ViewType::Timeline, [], null, false],
        ]);

        RecurringTask::query()->create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->getKey(),
            'template' => (new RecurringTaskTemplate(
                title: 'Send the weekly client status update',
                description: 'Progress since last week, what is next, and anything we are waiting on from the client.',
                priority: Priority::Medium,
                assigneeId: (int) $this->person('manager')->getKey(),
                estimateMinutes: 45,
                dueDayOffset: 1,
            ))->toArray(),
            'frequency' => RecurrenceFrequency::Weekly,
            'interval' => 1,
            'by_weekday' => [Carbon::MONDAY],
            'starts_on' => $this->day(-42),
            'next_run_on' => $this->day(-42)->next(Carbon::MONDAY),
            'last_run_on' => null,
            'occurrences_generated' => 0,
            'is_active' => true,
            'created_by' => $this->person('manager')->getKey(),
        ]);

        return $this->finishProject($project);
    }

    /* ------------------------------------------------------------------ *
     * Project: Marketing Campaign — at risk
     * ------------------------------------------------------------------ */

    private function createMarketingCampaign(Workspace $workspace): Project
    {
        $project = $this->makeProject($workspace, new ProjectAttributes(
            name: 'Marketing Campaign',
            key: 'MKT',
            description: 'Spring demand campaign: paid search, paid social and a three-part email sequence driving to a new landing page.',
            icon: '📣',
            color: '#C2410C',
            type: ProjectType::Marketing,
            statusId: $this->projectStatusId($workspace, 'Active'),
            health: ProjectHealth::AtRisk,
            healthNote: 'Creative is nine days late and legal review has not started. The live date holds only if the video cut-downs land this week.',
            priority: Priority::High,
            managerId: (int) $this->person('manager')->getKey(),
            department: 'Marketing',
            startDate: $this->day(-30),
            targetDate: $this->day(12),
            budget: '25000.00',
            currency: 'USD',
        ));

        $project->forceFill(['health_set_manually' => true])->save();

        $milestones = $this->createMilestones($project, [
            ['key' => 'brief', 'name' => 'Brief approved', 'description' => 'Goal, audience, budget and channels signed off.', 'status' => MilestoneStatus::Completed, 'start' => -30, 'due' => -24, 'completed' => -23, 'owner' => 'admin'],
            ['key' => 'creative', 'name' => 'Creative complete', 'description' => 'Every asset written, designed, reviewed and cleared.', 'status' => MilestoneStatus::Delayed, 'start' => -22, 'due' => -3, 'owner' => 'member'],
            ['key' => 'live', 'name' => 'Campaign live', 'description' => 'Scheduled, tracking verified, campaign running.', 'status' => MilestoneStatus::Planned, 'start' => 2, 'due' => 5, 'owner' => 'manager'],
            ['key' => 'wrap', 'name' => 'Results reported', 'description' => 'Performance measured against the KPIs and written up.', 'status' => MilestoneStatus::Planned, 'start' => 6, 'due' => 30, 'owner' => 'admin'],
        ]);

        $tasks = $this->createTasks($project, $milestones, [
            $this->spec('Set the campaign goal and KPIs', 'Completed', milestone: 'brief', assignee: 'admin', created: -30, start: -30, due: -27, completed: -27, estimate: 180, tags: ['Marketing'],
                description: 'Primary KPI is 240 qualified sign-ups. Baseline for the same period last year was 96.'),
            $this->spec('Agree the budget and the channel split', 'Completed', milestone: 'brief', assignee: 'admin', created: -30, due: -25, completed: -25, estimate: 120, tags: ['Finance'],
                checklist: [['Total budget confirmed', true], ['Split per channel', true], ['Production costs included', true], ['Contingency held back', true]]),
            $this->spec('Write and circulate the campaign brief', 'Completed', milestone: 'brief', assignee: 'admin', created: -29, start: -27, due: -24, completed: -23, estimate: 240, tags: ['Marketing']),
            $this->spec('Develop the messaging and proof points', 'Completed', milestone: 'creative', assignee: 'admin', created: -24, start: -22, due: -17, completed: -16, estimate: 300, tags: ['Content']),
            $this->spec('Design the creative concepts', 'Completed', milestone: 'creative', assignee: 'member', created: -24, start: -20, due: -12, completed: -11, estimate: 600, tags: ['Design']),
            $this->spec('Produce the video cut-downs', 'In Progress', milestone: 'creative', assignee: 'member', created: -20, start: -10, due: -2, estimate: 720, progress: 65, tags: ['Design'], priority: Priority::Urgent,
                description: 'Six-second and fifteen-second cuts for paid social. The edit is done; the voiceover recording slipped a week.',
                watchers: ['admin', 'manager']),
            $this->spec('Build and test the landing page', 'In Progress', milestone: 'creative', assignee: 'manager', created: -18, start: -8, due: 1, estimate: 480, progress: 70, tags: ['Development'],
                checklist: [['Page built', true], ['Form submits and routes correctly', true], ['Mobile checked', false], ['Page speed under three seconds', false]]),
            $this->spec('Brand, legal and accessibility review of the claims', 'Review', milestone: 'creative', assignee: 'admin', created: -16, start: -4, due: -1, estimate: 180, progress: 25, tags: ['Urgent'], priority: Priority::Urgent,
                description: 'Two claims in the headline need substantiation before anything runs. Legal has the evidence pack.'),
            $this->spec('Get paid media account access from the client', 'Blocked', milestone: 'live', assignee: 'manager', created: -19, start: -14, due: -6, estimate: 60, tags: ['Client'], priority: Priority::Urgent,
                description: 'Their agency of record still owns the ad accounts. Access request raised twice; no reply in eleven days.'),
            $this->spec('Set up conversion tracking and the dashboard', 'To Do', milestone: 'live', assignee: 'manager', created: -14, start: 1, due: 3, estimate: 300, tags: ['Development'], priority: Priority::High,
                description: 'Verify with a real test conversion before launch. If this is wrong the campaign cannot be judged.',
                checklist: [['UTM convention agreed', true], ['Conversion events firing', false], ['Dashboard built', false], ['Test conversion verified', false]]),
            $this->spec('Build the email nurture sequence', 'To Do', milestone: 'live', assignee: 'admin', created: -12, start: 1, due: 6, estimate: 420, tags: ['Content']),
            $this->spec('Schedule the organic social posts', 'To Do', milestone: 'live', assignee: 'member', created: -10, start: 2, due: 4, estimate: 240, tags: ['Marketing']),
            $this->spec('Launch the campaign', 'To Do', milestone: 'live', assignee: 'manager', created: -10, due: 5, estimate: 120, tags: ['Launch'], priority: Priority::Urgent, watchers: ['admin', 'member']),
            $this->spec('Analyse the results against the KPIs', 'To Do', milestone: 'wrap', assignee: 'admin', created: -8, start: 24, due: 28, estimate: 300, tags: ['Marketing']),
            $this->spec('Write the wrap-up and update the playbook', 'To Do', milestone: 'wrap', assignee: 'admin', created: -8, due: 30, estimate: 240, priority: Priority::Low),
            $this->spec('Explore an influencer partnership for the autumn push', 'Backlog', assignee: null, created: -7, priority: Priority::Low, tags: ['Research']),
        ]);

        $this->createDependencies($project, [
            ['Launch the campaign', 'Brand, legal and accessibility review of the claims', DependencyType::FinishToStart],
            ['Launch the campaign', 'Set up conversion tracking and the dashboard', DependencyType::FinishToStart],
            ['Launch the campaign', 'Get paid media account access from the client', DependencyType::Blocks],
            ['Schedule the organic social posts', 'Produce the video cut-downs', DependencyType::FinishToStart],
        ], $tasks);

        $this->createComments($tasks, [
            ['Produce the video cut-downs', 'admin', 'Where are we on the voiceover? The live date is five days out and legal still needs to see the finished cuts.', -3],
            ['Produce the video cut-downs', 'member', 'Recording booked for tomorrow morning. Edits done the same afternoon, so legal has them Thursday.', -3],
            ['Get paid media account access from the client', 'manager', 'Eleven days with no reply. I would rather build the campaigns in our own account and rebill than keep waiting.', -2],
            ['Get paid media account access from the client', 'admin', 'Agreed. Raise it as a change of approach on Tuesday\'s call and get it in writing.', -2],
            ['Brand, legal and accessibility review of the claims', 'admin', 'Evidence pack sent to legal. Both claims are supportable; the wording of the second one probably needs softening.', -1],
        ]);

        $this->createTimeEntries([
            ['Design the creative concepts', 'member', -19, 420], ['Design the creative concepts', 'member', -17, 480],
            ['Design the creative concepts', 'member', -14, 300],
            ['Produce the video cut-downs', 'member', -9, 360], ['Produce the video cut-downs', 'member', -6, 420],
            ['Produce the video cut-downs', 'member', -2, 240],
            ['Build and test the landing page', 'manager', -7, 300], ['Build and test the landing page', 'manager', -4, 360],
            ['Write and circulate the campaign brief', 'admin', -26, 240],
            ['Brand, legal and accessibility review of the claims', 'admin', -1, 120],
        ], $tasks);

        $this->createExpenses($project, [
            ['Voiceover artist — two cuts', 'contractor', '850.00', -4],
            ['Paid social creative production', 'contractor', '3200.00', -12],
            ['Landing page template licence', 'software', '180.00', -16],
        ]);

        $this->createProjectWiki($project, 'Campaign brief', 'campaign-brief', 'Goal, audience, message, channel split and the two risks we are watching.', <<<'HTML'
            <h2>Spring demand campaign</h2>
            <p><strong>Goal.</strong> 240 qualified sign-ups in six weeks, against a baseline of 96 for the same period last year.</p>
            <h3>Audience</h3>
            <p>Operations leads at 50–500 person distributors who are still running their scheduling on spreadsheets and have already felt the pain of it.</p>
            <h3>Message</h3>
            <p>Stop rebuilding the same schedule every Monday. One plan, everyone on it, updated as the week changes.</p>
            <h3>Channels and split</h3>
            <ul>
              <li>Paid search — 40%</li>
              <li>Paid social — 35%</li>
              <li>Email to the existing list — 15%</li>
              <li>Production and contingency — 10%</li>
            </ul>
            <h3>Risks</h3>
            <p>Creative is behind. Client-side ad account access is unresolved. Both are on the weekly call.</p>
            HTML);

        $this->createViews($project, [
            ['Campaign board', ViewType::Board, [], 'status', true],
            ['Content calendar', ViewType::Calendar, [], null, true],
            ['Needs attention', ViewType::List, ['priority' => ['high', 'urgent']], null, false],
        ]);

        return $this->finishProject($project);
    }

    /* ------------------------------------------------------------------ *
     * Project: Product Launch — early, mostly ahead of itself
     * ------------------------------------------------------------------ */

    private function createProductLaunch(Workspace $workspace): Project
    {
        $project = $this->makeProject($workspace, new ProjectAttributes(
            name: 'Product Launch',
            key: 'LAUNCH',
            description: 'Take the scheduling module from private beta to general availability, with pricing, docs and an enabled sales team.',
            icon: '🚀',
            color: '#7C3AED',
            type: ProjectType::ProductLaunch,
            statusId: $this->projectStatusId($workspace, 'Planning'),
            health: ProjectHealth::OnTrack,
            priority: Priority::Medium,
            managerId: (int) $this->person('admin')->getKey(),
            department: 'Product',
            startDate: $this->day(-8),
            targetDate: $this->day(75),
            budget: '60000.00',
            currency: 'USD',
        ));

        $milestones = $this->createMilestones($project, [
            ['key' => 'plan', 'name' => 'Launch plan approved', 'description' => 'Positioning, pricing, audience and date agreed across product, marketing and sales.', 'status' => MilestoneStatus::InProgress, 'start' => -8, 'due' => 14, 'owner' => 'admin'],
            ['key' => 'beta', 'name' => 'Beta complete', 'description' => 'Real customers have used it and their feedback has been acted on.', 'status' => MilestoneStatus::Planned, 'start' => 15, 'due' => 40, 'owner' => 'manager'],
            ['key' => 'launch', 'name' => 'Launch day', 'description' => 'Announced, available and supported.', 'status' => MilestoneStatus::Planned, 'start' => 60, 'due' => 70, 'owner' => 'admin'],
        ]);

        $tasks = $this->createTasks($project, $milestones, [
            $this->spec('Confirm the launch scope with engineering', 'Completed', milestone: 'plan', assignee: 'manager', created: -8, start: -8, due: -3, completed: -2, estimate: 180, tags: ['Internal']),
            $this->spec('Agree the positioning and the one-line pitch', 'In Progress', milestone: 'plan', assignee: 'admin', created: -7, start: -4, due: 7, estimate: 300, progress: 40, tags: ['Marketing'], priority: Priority::High,
                description: 'Who it is for, what it replaces, why it is better. Everything downstream is written from this.',
                checklist: [['Target segment named', true], ['Alternative it replaces named', true], ['Three differentiators agreed', false], ['One-line pitch written', false]]),
            $this->spec('Decide pricing and packaging', 'To Do', milestone: 'plan', assignee: 'admin', created: -7, start: 2, due: 12, estimate: 480, tags: ['Finance'], priority: Priority::High,
                checklist: [['Price points modelled', false], ['Packaging tiers agreed', false], ['Discount policy agreed', false], ['Finance signed off', false]],
                watchers: ['manager']),
            $this->spec('Write the launch plan and set the date', 'To Do', milestone: 'plan', assignee: 'manager', created: -6, start: 8, due: 14, estimate: 240, tags: ['Internal']),
            $this->spec('Recruit the beta cohort', 'To Do', milestone: 'beta', assignee: 'manager', created: -5, start: 15, due: 22, estimate: 240, tags: ['Research']),
            $this->spec('Run the beta and collect structured feedback', 'To Do', milestone: 'beta', assignee: 'manager', created: -5, start: 22, due: 38, estimate: 720, tags: ['Research']),
            $this->spec('Draft the product documentation', 'To Do', milestone: 'beta', assignee: 'member', created: -4, start: 20, due: 35, estimate: 960, tags: ['Content']),
            $this->spec('Design the product page and pricing page', 'To Do', milestone: 'launch', assignee: 'member', created: -4, start: 40, due: 55, estimate: 720, tags: ['Design']),
            $this->spec('Prepare sales enablement', 'To Do', milestone: 'launch', assignee: 'admin', created: -3, start: 45, due: 60, estimate: 600, tags: ['Internal']),
            $this->spec('Hold the go/no-go review', 'To Do', milestone: 'launch', assignee: 'admin', created: -3, due: 66, estimate: 120, priority: Priority::High,
                checklist: [['Engineering ready', false], ['Support ready', false], ['Sales enabled', false], ['Docs published', false], ['Pricing live in billing', false], ['Rollback plan agreed', false]]),
            $this->spec('Run launch day', 'To Do', milestone: 'launch', assignee: 'admin', created: -3, due: 70, estimate: 480, tags: ['Launch'], priority: Priority::High, watchers: ['manager', 'member']),
            $this->spec('Record the demo and walkthrough video', 'Backlog', assignee: null, created: -2, priority: Priority::Low, estimate: 480, tags: ['Design']),
            $this->spec('Plan the press and analyst briefings', 'Backlog', assignee: null, created: -2, priority: Priority::Low, estimate: 420, tags: ['Marketing']),
            $this->spec('Instrument adoption and revenue reporting', 'Backlog', assignee: null, created: -1, priority: Priority::Medium, estimate: 360, tags: ['Development']),
        ]);

        $this->createDependencies($project, [
            ['Decide pricing and packaging', 'Agree the positioning and the one-line pitch', DependencyType::FinishToStart],
            ['Write the launch plan and set the date', 'Decide pricing and packaging', DependencyType::FinishToStart],
            ['Run launch day', 'Hold the go/no-go review', DependencyType::FinishToStart],
            ['Run the beta and collect structured feedback', 'Recruit the beta cohort', DependencyType::FinishToStart],
        ], $tasks);

        $this->createComments($tasks, [
            ['Agree the positioning and the one-line pitch', 'manager', 'The two differentiators we are sure of are the shared plan and the change history. The third one keeps moving — can we drop it rather than pad it?', -2],
            ['Agree the positioning and the one-line pitch', 'admin', 'Two strong ones beats three weak ones. Rewriting the pitch on that basis and bringing it to Thursday.', -1],
            ['Decide pricing and packaging', 'admin', 'Modelling three tiers. The open question is whether scheduling is a tier or an add-on — that decision changes the whole launch narrative.', -1],
        ]);

        $this->createTimeEntries([
            ['Confirm the launch scope with engineering', 'manager', -6, 180],
            ['Agree the positioning and the one-line pitch', 'admin', -4, 150],
            ['Agree the positioning and the one-line pitch', 'admin', -2, 120],
            ['Decide pricing and packaging', 'admin', -1, 90],
        ], $tasks);

        $this->createProjectWiki($project, 'Launch readiness checklist', 'launch-readiness-checklist', 'Every line has an owner and is answered yes or no at the go/no-go review.', <<<'HTML'
            <h2>Launch readiness</h2>
            <p>Every line has a named owner and is answered yes or no at the go/no-go review. "Nearly" counts as no.</p>
            <h3>Product</h3>
            <ul>
              <li>Feature flag tested on and off in production.</li>
              <li>Migration rehearsed against a copy of production data.</li>
              <li>Rollback plan written and read by someone who did not write it.</li>
            </ul>
            <h3>Commercial</h3>
            <ul>
              <li>Pricing live in billing, including the annual option.</li>
              <li>Sales trained, with the recording available to anyone who missed it.</li>
              <li>Support macros written and the escalation path agreed.</li>
            </ul>
            <h3>Communications</h3>
            <ul>
              <li>Announcement approved and scheduled.</li>
              <li>Docs published and linked from the product.</li>
              <li>Existing customers told before the public announcement.</li>
            </ul>
            HTML);

        $this->createViews($project, [
            ['Launch board', ViewType::Board, [], 'status', true],
            ['Countdown', ViewType::Timeline, [], null, true],
        ]);

        return $this->finishProject($project);
    }

    /* ------------------------------------------------------------------ *
     * Project plumbing
     * ------------------------------------------------------------------ */

    private function makeProject(Workspace $workspace, ProjectAttributes $attributes): Project
    {
        $project = ($this->createProject)($workspace, $this->person('admin'), $attributes);

        // The designer works on every project but runs none of them.
        $project->members()->syncWithoutDetaching([
            $this->person('member')->getKey() => ['role' => ProjectRole::Member->value],
        ]);

        return $project;
    }

    /**
     * The workspace lifecycle stage with this name, from the set CreateWorkspace seeded.
     */
    private function projectStatusId(Workspace $workspace, string $name): ?int
    {
        $status = ProjectStatus::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->getKey())
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        return $status === null ? null : (int) $status->getKey();
    }

    /**
     * Refresh the denormalised caches the product keeps on a project once its tasks exist.
     */
    private function finishProject(Project $project): Project
    {
        $tasks = Task::query()
            ->withoutWorkspaceScope()
            ->where('project_id', $project->getKey())
            ->get(['id', 'milestone_id', 'completed_at']);

        $total = $tasks->count();
        $done = $tasks->whereNotNull('completed_at')->count();

        $project->forceFill([
            'progress' => $total === 0 ? 0 : (int) round($done / $total * 100),
        ])->save();

        foreach (Milestone::query()->withoutWorkspaceScope()->where('project_id', $project->getKey())->get() as $milestone) {
            $scoped = $tasks->where('milestone_id', (int) $milestone->getKey());
            $count = $scoped->count();

            $milestone->forceFill([
                'progress' => $count === 0 ? 0 : (int) round($scoped->whereNotNull('completed_at')->count() / $count * 100),
            ])->save();
        }

        return $project->refresh();
    }

    /**
     * @param list<array{key: string, name: string, description: string, status: MilestoneStatus, start: int, due: int, completed?: int, owner: string}> $rows
     * @return array<string, Milestone>
     */
    private function createMilestones(Project $project, array $rows): array
    {
        $milestones = [];
        $position = 0;

        foreach ($rows as $row) {
            $milestones[$row['key']] = Milestone::query()->create([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->getKey(),
                'name' => $row['name'],
                'description' => $row['description'],
                'status' => $row['status'],
                'start_date' => $this->day($row['start']),
                'due_date' => $this->day($row['due']),
                'completed_at' => isset($row['completed']) ? $this->at($row['completed'], 16, 30) : null,
                'owner_id' => $this->person($row['owner'])->getKey(),
                'position' => $position,
                'progress' => 0,
            ]);

            $position++;
        }

        return $milestones;
    }

    /**
     * @param array<string, Milestone> $milestones
     * @param list<array<string, mixed>> $specs
     * @return array<string, Task>
     */
    private function createTasks(Project $project, array $milestones, array $specs): array
    {
        $tasks = [];
        $number = 0;
        $positions = [];

        foreach ($specs as $spec) {
            $status = $this->taskStatus($project, (string) $spec['status']);
            $statusId = (int) $status->getKey();
            $positions[$statusId] = ($positions[$statusId] ?? 0) + 1;
            $number++;

            $createdAt = $this->at((int) $spec['created'], 9, 30);
            $completedAt = $spec['completed'] === null
                ? null
                : $this->at((int) $spec['completed'], 16, 45);

            $assignee = $spec['assignee'] === null ? null : $this->person((string) $spec['assignee']);
            $reporter = $this->person((string) $spec['reporter']);

            /** @var Task $task */
            $task = Task::query()->forceCreate([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->getKey(),
                'number' => $number,
                'title' => $spec['title'],
                'description' => $spec['description'] === null ? null : '<p>'.e((string) $spec['description']).'</p>',
                'status_id' => $statusId,
                'priority' => $spec['priority'],
                'assignee_id' => $assignee?->getKey(),
                'reporter_id' => $reporter->getKey(),
                'parent_id' => null,
                'milestone_id' => $spec['milestone'] === null ? null : $milestones[$spec['milestone']]->getKey(),
                'start_date' => $spec['start'] === null ? null : $this->day((int) $spec['start']),
                'due_date' => $spec['due'] === null ? null : $this->day((int) $spec['due']),
                'completed_at' => $completedAt,
                'estimate_minutes' => $spec['estimate'],
                'position' => $positions[$statusId] * 1000,
                'progress' => $completedAt !== null ? 100 : (int) $spec['progress'],
                'recurring_task_id' => null,
                'created_by' => $reporter->getKey(),
                'ai_generated' => false,
                'created_at' => $createdAt,
                'updated_at' => $completedAt ?? $createdAt,
            ]);

            $tasks[(string) $spec['title']] = $task;

            $this->attachTags($task, (array) $spec['tags']);
            $this->attachChecklist($task, (array) $spec['checklist'], $assignee ?? $reporter, $completedAt);
            $this->attachWatchers($task, (array) $spec['watchers']);
            $this->recordTaskActivity($task, $status, $reporter, $assignee, $createdAt, $completedAt);
        }

        $project->forceFill(['task_number_seq' => $number])->save();

        return $tasks;
    }

    /**
     * One spec row. Named arguments keep the project definitions above readable — a demo
     * nobody can read is a demo nobody can correct.
     *
     * `created`, `start`, `due` and `completed` are day offsets from today: negative is the
     * past. Relative dates are what make the demo look mid-flight whenever it is installed,
     * rather than three years stale by the second release.
     *
     * @param list<string> $tags
     * @param list<array{0: string, 1: bool}> $checklist
     * @param list<string> $watchers
     * @return array<string, mixed>
     */
    private function spec(
        string $title,
        string $status,
        ?string $milestone = null,
        ?string $assignee = null,
        string $reporter = 'admin',
        Priority $priority = Priority::Medium,
        int $created = 0,
        ?int $start = null,
        ?int $due = null,
        ?int $completed = null,
        ?int $estimate = null,
        int $progress = 0,
        ?string $description = null,
        array $tags = [],
        array $checklist = [],
        array $watchers = [],
    ): array {
        return [
            'title' => $title,
            'status' => $status,
            'milestone' => $milestone,
            'assignee' => $assignee,
            'reporter' => $reporter,
            'priority' => $priority,
            'created' => $created,
            'start' => $start,
            'due' => $due,
            'completed' => $completed,
            'estimate' => $estimate,
            'progress' => $progress,
            'description' => $description,
            'tags' => $tags,
            'checklist' => $checklist,
            'watchers' => $watchers,
        ];
    }

    /**
     * A tag the demo asks for and the workspace does not have is created rather than skipped:
     * the shipped default tags come from config, and an installation that has edited that list
     * should still get a complete demo instead of one quietly missing its labels.
     *
     * @param list<string> $names
     */
    private function attachTags(Task $task, array $names): void
    {
        $ids = [];

        foreach ($names as $name) {
            $key = mb_strtolower($name);

            $tag = $this->tags[$key] ??= Tag::withoutWorkspaceScope()->firstOrCreate(
                ['workspace_id' => $task->workspace_id, 'slug' => Str::slug($name)],
                ['name' => $name, 'color' => 'gray'],
            );

            $ids[] = $tag->getKey();
        }

        if ($ids !== []) {
            $task->tags()->syncWithoutDetaching($ids);
        }
    }

    /**
     * @param list<array{0: string, 1: bool}> $items
     */
    private function attachChecklist(Task $task, array $items, User $completer, ?Carbon $completedAt): void
    {
        $position = 0;

        foreach ($items as [$title, $done]) {
            TaskChecklistItem::query()->create([
                'task_id' => $task->getKey(),
                'title' => $title,
                'is_done' => $done,
                'position' => $position,
                'completed_at' => $done ? ($completedAt ?? $this->at(-2, 11, 15)) : null,
                'completed_by' => $done ? $completer->getKey() : null,
            ]);

            $position++;
        }
    }

    /**
     * @param list<string> $keys
     */
    private function attachWatchers(Task $task, array $keys): void
    {
        foreach ($keys as $key) {
            TaskWatcher::query()->create([
                'task_id' => $task->getKey(),
                'user_id' => $this->person($key)->getKey(),
            ]);
        }
    }

    /**
     * The feed entries the product would have written had somebody done this by hand, dated
     * to when it happened rather than to when the seeder ran.
     */
    private function recordTaskActivity(
        Task $task,
        TaskStatus $status,
        User $reporter,
        ?User $assignee,
        Carbon $createdAt,
        ?Carbon $completedAt,
    ): void {
        $this->backdate(
            $this->activity->record($task, 'created', $reporter, ['title' => $task->title]),
            $createdAt,
        );

        if ($assignee !== null) {
            $this->backdate(
                $this->activity->record($task, 'assigned', $reporter, [
                    'assignee_id' => (int) $assignee->getKey(),
                    'assignee' => $assignee->name,
                ]),
                $createdAt->copy()->addMinutes(4),
            );
        }

        if ($completedAt !== null) {
            $this->backdate(
                $this->activity->record($task, 'status_changed', $assignee ?? $reporter, [
                    'changes' => ['status' => ['old' => 'In Progress', 'new' => (string) $status->name]],
                ]),
                $completedAt,
            );
        }
    }

    /**
     * @param list<array{0: string, 1: string, 2: DependencyType}> $rows
     * @param array<string, Task> $tasks
     */
    private function createDependencies(Project $project, array $rows, array $tasks): void
    {
        foreach ($rows as [$title, $dependsOn, $type]) {
            if (! isset($tasks[$title], $tasks[$dependsOn])) {
                continue;
            }

            TaskDependency::query()->create([
                'workspace_id' => $project->workspace_id,
                'task_id' => $tasks[$title]->getKey(),
                'depends_on_task_id' => $tasks[$dependsOn]->getKey(),
                'type' => $type,
            ]);
        }
    }

    /**
     * @param array<string, Task> $tasks
     * @param list<array{0: string, 1: string, 2: string, 3: int}> $rows
     */
    private function createComments(array $tasks, array $rows): void
    {
        foreach ($rows as $index => [$title, $author, $body, $daysAgo]) {
            $task = $tasks[$title] ?? null;

            if ($task === null) {
                continue;
            }

            // Minutes spread by row order so a reply always sorts after the comment it answers.
            $at = $this->at($daysAgo, 10, min(59, 12 + ($index * 7)));

            Comment::query()->forceCreate([
                'workspace_id' => $task->workspace_id,
                'commentable_id' => $task->getKey(),
                'commentable_type' => $task->getMorphClass(),
                'user_id' => $this->person($author)->getKey(),
                'body' => '<p>'.e($body).'</p>',
                'author_type' => AuthorType::User,
                'ai_run_id' => null,
                'parent_id' => null,
                'edited_at' => null,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }
    }

    /**
     * @param list<array{0: string, 1: string, 2: int, 3: int}> $rows task title, person, days ago, minutes
     * @param array<string, Task> $tasks
     */
    private function createTimeEntries(array $rows, array $tasks): void
    {
        foreach ($rows as [$title, $person, $daysAgo, $minutes]) {
            $task = $tasks[$title] ?? null;

            if ($task === null) {
                continue;
            }

            TimeEntry::create([
                'workspace_id' => $task->workspace_id,
                'project_id' => $task->project_id,
                'task_id' => $task->getKey(),
                'user_id' => $this->person($person)->getKey(),
                'minutes' => $minutes,
                'description' => $task->title,
                'spent_on' => $this->day($daysAgo)->toDateString(),
                'started_at' => null,
                'ended_at' => null,
                'is_running' => false,
                'is_billable' => true,
            ]);
        }
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: int}> $rows description, category, amount, days ago
     */
    private function createExpenses(Project $project, array $rows): void
    {
        foreach ($rows as [$description, $category, $amount, $daysAgo]) {
            Expense::create([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->getKey(),
                'user_id' => $this->person('admin')->getKey(),
                'amount' => $amount,
                'currency' => (string) ($project->currency ?? 'USD'),
                'category' => $category,
                'description' => $description,
                'incurred_on' => $this->day($daysAgo)->toDateString(),
            ]);
        }
    }

    private function createProjectWiki(Project $project, string $title, string $slug, string $excerpt, string $content): void
    {
        WikiPage::query()->create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->getKey(),
            'parent_id' => null,
            'title' => $title,
            'slug' => $slug,
            'content' => $content,
            'excerpt' => $excerpt,
            'position' => 0,
            'visibility' => WikiVisibility::Project,
            'author_id' => $this->person('admin')->getKey(),
            'last_edited_by' => $this->person('manager')->getKey(),
            'ai_generated' => false,
        ]);
    }

    /**
     * @param list<array{0: string, 1: ViewType, 2: array<string, mixed>, 3: string|null, 4: bool}> $rows
     */
    private function createViews(Project $project, array $rows): void
    {
        $position = 0;

        foreach ($rows as [$name, $type, $filters, $groupBy, $pinned]) {
            SavedView::create([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->getKey(),
                // Shared views belong to the workspace rather than to a person.
                'user_id' => null,
                'name' => $name,
                'type' => $type,
                'filters' => $filters,
                'sorts' => [['field' => 'due_date', 'direction' => 'asc']],
                'columns' => ['title', 'assignee', 'status', 'priority', 'due_date'],
                'group_by' => $groupBy,
                'is_shared' => true,
                'is_pinned' => $pinned,
                'position' => $position,
            ]);

            $position++;
        }
    }

    /* ------------------------------------------------------------------ *
     * Workspace-level extras
     * ------------------------------------------------------------------ */

    private function createWorkspaceWiki(Workspace $workspace): void
    {
        WikiPage::query()->create([
            'workspace_id' => $workspace->getKey(),
            'project_id' => null,
            'parent_id' => null,
            'title' => 'How we work at Acme',
            'slug' => 'how-we-work-at-acme',
            'content' => <<<'HTML'
                <h2>How we work</h2>
                <p>Three people, three projects, one week at a time. This page is the short version of everything we have agreed so far.</p>
                <h3>The week</h3>
                <ul>
                  <li><strong>Monday.</strong> Fifteen minutes, all three of us, what is at risk this week.</li>
                  <li><strong>Tuesday.</strong> Client calls. Actions land in the project before the call ends.</li>
                  <li><strong>Friday.</strong> Log your time. A week logged on Monday is a week guessed.</li>
                </ul>
                <h3>Tasks</h3>
                <p>Every task has one assignee. If it has none, it is not work yet — it is an idea, and it belongs in the backlog.</p>
                <p>If something is blocked, say so on the task the same day, and say who you are waiting on. Blocked and silent is the only failure mode we actually care about.</p>
                <h3>Estimates</h3>
                <p>Estimates are a planning tool, not a promise. When one is wrong, change it — nobody is scored on it.</p>
                HTML,
            'excerpt' => 'The short version of how the three of us run the week.',
            'position' => 0,
            'visibility' => WikiVisibility::Workspace,
            'author_id' => $this->person('admin')->getKey(),
            'last_edited_by' => $this->person('admin')->getKey(),
            'ai_generated' => false,
        ]);
    }

    /**
     * A handful of security-relevant events, so the admin panel's audit view has something
     * truthful to show. These describe what this seeder actually did.
     */
    private function createAuditTrail(Workspace $workspace): void
    {
        $rows = [
            ['workspace.created', 'Workspace "Acme Company" created.', 'admin', -52],
            ['user.created', 'Account created for Marcus Reyes.', 'admin', -52],
            ['user.created', 'Account created for Priya Nadar.', 'admin', -51],
            ['member.role_changed', 'Marcus Reyes promoted to manager.', 'admin', -48],
            ['settings.updated', 'Workspace currency and week start confirmed.', 'admin', -47],
            ['auth.login', 'Signed in from the office network.', 'manager', -1],
        ];

        foreach ($rows as [$event, $description, $person, $daysAgo]) {
            $at = $this->at($daysAgo, 9, 5);

            AuditLog::query()->forceCreate([
                'user_id' => $this->person($person)->getKey(),
                'workspace_id' => $workspace->getKey(),
                'event' => $event,
                'description' => $description,
                'ip' => '127.0.0.1',
                'user_agent' => 'Mozilla/5.0 (Planvio demo data)',
                'properties' => ['source' => 'demo'],
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }
    }

    /**
     * Pinned projects and a recent-items trail, so the sidebar and the "jump back in" list
     * are not empty on first login.
     *
     * @param list<Project> $projects
     */
    private function createShortcuts(Workspace $workspace, array $projects): void
    {
        foreach ($projects as $position => $project) {
            foreach (['admin', 'manager'] as $key) {
                Favorite::query()->create([
                    'user_id' => $this->person($key)->getKey(),
                    'favoritable_id' => $project->getKey(),
                    'favoritable_type' => $project->getMorphClass(),
                    'position' => $position,
                ]);
            }

            foreach (array_keys($this->people) as $key) {
                RecentItem::query()->create([
                    'user_id' => $this->person($key)->getKey(),
                    'workspace_id' => $workspace->getKey(),
                    'viewable_id' => $project->getKey(),
                    'viewable_type' => $project->getMorphClass(),
                    'viewed_at' => $this->at(0, 9, 40 - ($position * 7)),
                ]);
            }
        }
    }

    /* ------------------------------------------------------------------ *
     * Output
     * ------------------------------------------------------------------ */

    private function printCredentials(): void
    {
        $command = $this->command;

        if ($command === null) {
            return;
        }

        $command->newLine();
        $command->info(__('Demo workspace ":name" installed.', ['name' => self::WORKSPACE_NAME]));
        $command->newLine();

        $command->table(
            [__('Role'), __('Name'), __('Email'), __('Password')],
            array_map(
                static fn (array $account): array => [
                    $account['platform_admin']
                        ? $account['role']->label().' · '.__('platform admin')
                        : $account['role']->label(),
                    $account['name'],
                    $account['email'],
                    self::PASSWORD,
                ],
                self::accounts(),
            ),
        );

        $command->warn(__('These are demo credentials. Remove the demo data before using this installation for real work.'));
    }

    /* ------------------------------------------------------------------ *
     * Small helpers
     * ------------------------------------------------------------------ */

    private function person(string $key): User
    {
        return $this->people[$key] ?? throw new RuntimeException("Unknown demo person [{$key}].");
    }

    /**
     * The project's board column with this name. Board columns come from the workspace
     * template, so a column the demo asks for and the workspace does not have is a bug in
     * the demo rather than something to paper over with a fallback.
     */
    private function taskStatus(Project $project, string $name): TaskStatus
    {
        $cacheKey = $project->getKey().':'.mb_strtolower($name);

        if (isset($this->statuses[$cacheKey])) {
            return $this->statuses[$cacheKey];
        }

        $statuses = TaskStatus::query()
            ->withoutWorkspaceScope()
            ->where('project_id', $project->getKey())
            ->get();

        foreach ($statuses as $status) {
            $this->statuses[$project->getKey().':'.mb_strtolower((string) $status->name)] = $status;
        }

        return $this->statuses[$cacheKey]
            ?? throw new RuntimeException("Project [{$project->name}] has no board column named [{$name}].");
    }

    private function day(int $offset): Carbon
    {
        return Carbon::today()->addDays($offset);
    }

    private function at(int $dayOffset, int $hour, int $minute): Carbon
    {
        return Carbon::today()->addDays($dayOffset)->setTime($hour, $minute);
    }

    private function backdate(Model $model, Carbon $at): void
    {
        $model->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }
}

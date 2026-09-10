<?php

declare(strict_types=1);

namespace Tests\Feature\App;

use App\Enums\MilestoneStatus;
use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Livewire\App\Projects\Timeline;
use App\Livewire\App\Reports\Index as Reports;
use App\Livewire\App\Reports\StatusReport;
use App\Models\Expense;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\TaskDependency;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reporting, the printable status report and the Gantt.
 */
final class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->makeWorkspace([
            'slug' => 'acme',
            'timezone' => 'UTC',
            'week_starts_on' => 1,
            'currency' => 'EUR',
        ]);
        $this->owner = $this->makeMember($this->workspace, WorkspaceRole::Owner, ['timezone' => 'UTC']);
        $this->project = $this->makeProject($this->workspace, [], [
            'slug' => 'website',
            'key' => 'WEB',
            'budget' => '10000.00',
            'currency' => 'EUR',
            'start_date' => '2026-03-01',
            'target_date' => '2026-05-01',
        ]);
    }

    /* ------------------------------------------------------------------ *
     * Routes
     * ------------------------------------------------------------------ */

    #[Test]
    public function every_new_route_renders_for_a_member(): void
    {
        foreach ([
            route('app.reports', $this->workspace),
            route('app.projects.report', [$this->workspace, $this->project]),
            route('app.projects.timeline', [$this->workspace, $this->project]),
        ] as $url) {
            $this->actingAs($this->owner)->get($url)->assertOk();
        }
    }

    #[Test]
    public function none_of_them_are_reachable_from_another_workspace(): void
    {
        $outsider = $this->makeMember($this->makeWorkspace(['slug' => 'northwind']));

        $this->assertDeniedAccess($outsider, route('app.reports', $this->workspace));
        $this->assertDeniedAccess($outsider, route('app.projects.report', [$this->workspace, $this->project]));
        $this->assertDeniedAccess($outsider, route('app.projects.timeline', [$this->workspace, $this->project]));
    }

    /* ------------------------------------------------------------------ *
     * Reports
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_progress_report_counts_completed_open_and_overdue_work(): void
    {
        $done = TaskStatus::factory()->completed()->create([
            'project_id' => $this->project->getKey(),
            'workspace_id' => $this->workspace->getKey(),
        ]);

        $this->makeTask($this->project, ['due_date' => '2000-01-01']);
        $this->makeTask($this->project, ['due_date' => '2999-01-01']);
        $this->makeTask($this->project, [
            'status_id' => $done->getKey(),
            'completed_at' => now(),
        ]);

        $rows = Livewire::actingAs($this->owner)
            ->test(Reports::class, ['workspace' => $this->workspace])
            ->instance()
            ->progress;

        $this->assertCount(1, $rows);
        $this->assertSame(3, $rows[0]['total']);
        $this->assertSame(1, $rows[0]['completed']);
        $this->assertSame(1, $rows[0]['overdue']);
    }

    #[Test]
    public function every_tab_renders(): void
    {
        $component = Livewire::actingAs($this->owner)
            ->test(Reports::class, ['workspace' => $this->workspace]);

        foreach (['progress', 'workload', 'health', 'time', 'budget'] as $tab) {
            $component->call('selectTab', $tab)->assertSet('tab', $tab)->assertOk();
        }
    }

    #[Test]
    public function a_member_without_budget_view_is_not_offered_the_budget_tab(): void
    {
        $member = $this->makeMember($this->workspace, WorkspaceRole::Member);

        $component = Livewire::actingAs($member)
            ->test(Reports::class, ['workspace' => $this->workspace]);

        $keys = array_column($component->instance()->tabs(), 'key');

        $this->assertNotContains('budget', $keys);
        $this->assertFalse($component->instance()->canSeeBudget());

        // And asking for it anyway does not open it.
        $component->call('selectTab', 'budget')->assertSet('tab', 'progress');
    }

    #[Test]
    public function the_budget_report_reads_planned_against_actual(): void
    {
        Expense::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'project_id' => $this->project->getKey(),
            'amount' => '2500.00',
            'currency' => 'EUR',
            'incurred_on' => now()->toDateString(),
        ]);

        $budgets = Livewire::actingAs($this->owner)
            ->test(Reports::class, ['workspace' => $this->workspace])
            ->instance()
            ->budget;

        $budget = $budgets[(int) $this->project->getKey()];

        $this->assertSame('10000.00', $budget->planned());
        $this->assertSame('2500.00', $budget->actual());
        $this->assertSame('7500.00', $budget->variance());
    }

    #[Test]
    public function the_time_report_narrows_to_your_own_hours_without_view_all_time(): void
    {
        $member = $this->makeMember($this->workspace, WorkspaceRole::Member);

        TimeEntry::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'project_id' => $this->project->getKey(),
            'user_id' => $this->owner->getKey(),
            'minutes' => 120,
            'spent_on' => now()->toDateString(),
            'is_running' => false,
        ]);

        $mine = Livewire::actingAs($member)
            ->test(Reports::class, ['workspace' => $this->workspace])
            ->instance()
            ->time;

        $this->assertTrue($mine['own']);
        $this->assertSame(0, $mine['total']);

        $theirs = Livewire::actingAs($this->owner)
            ->test(Reports::class, ['workspace' => $this->workspace])
            ->instance()
            ->time;

        $this->assertFalse($theirs['own']);
        $this->assertSame(120, $theirs['total']);
    }

    #[Test]
    public function the_export_returns_a_csv_of_the_report_on_screen(): void
    {
        $this->makeTask($this->project, ['due_date' => '2000-01-01']);

        $response = Livewire::actingAs($this->owner)
            ->test(Reports::class, ['workspace' => $this->workspace])
            ->call('export');

        $body = $this->captureDownload($response->effects['download']['content'] ?? null);

        $this->assertNotNull($body, 'The export did not produce a download.');
        $this->assertStringContainsString('Project', $body);
        $this->assertStringContainsString($this->project->name, $body);
    }

    #[Test]
    public function a_project_name_with_a_comma_survives_the_export(): void
    {
        $this->project->update(['name' => 'Acme, Inc. rebuild']);

        $response = Livewire::actingAs($this->owner)
            ->test(Reports::class, ['workspace' => $this->workspace])
            ->call('export');

        $body = $this->captureDownload($response->effects['download']['content'] ?? null);

        $this->assertNotNull($body);
        $this->assertStringContainsString('"Acme, Inc. rebuild"', $body);
    }

    /* ------------------------------------------------------------------ *
     * Status report
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_status_report_separates_recorded_figures_from_the_calculated_verdict(): void
    {
        $this->makeTask($this->project, ['due_date' => '2000-01-01']);
        $this->makeTask($this->project, ['due_date' => '2000-01-02']);
        $this->makeTask($this->project, ['due_date' => '2000-01-03']);

        $component = Livewire::actingAs($this->owner)
            ->test(StatusReport::class, ['workspace' => $this->workspace, 'project' => $this->project]);

        $component->assertOk()
            ->assertSee('Status report')
            ->assertSee('Risks and signals');

        $this->assertSame(3, $component->instance()->counts['overdue']);
        $this->assertNotSame([], $component->instance()->health->reasons);
    }

    #[Test]
    public function the_status_report_omits_the_budget_from_somebody_who_may_not_see_it(): void
    {
        $member = $this->makeMember($this->workspace, WorkspaceRole::Member);

        $component = Livewire::actingAs($member)
            ->test(StatusReport::class, ['workspace' => $this->workspace, 'project' => $this->project]);

        $this->assertFalse($component->instance()->canSeeBudget());
        $this->assertNull($component->instance()->budget);
        $component->assertSee('Sections left out');
    }

    #[Test]
    public function a_guest_outside_the_project_cannot_open_its_status_report(): void
    {
        $guest = $this->makeMember($this->workspace, WorkspaceRole::Guest);

        $this->assertDeniedAccess($guest, route('app.projects.report', [$this->workspace, $this->project]));
    }

    #[Test]
    public function a_guest_inside_the_project_can_open_its_calendar_but_still_not_its_report(): void
    {
        $guest = $this->makeMember($this->workspace, WorkspaceRole::Guest);
        $this->project->members()->attach($guest->id, [
            'role' => ProjectRole::Guest->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // `reports.view` is blank for a guest in the capability matrix, in every project.
        $this->assertDeniedAccess($guest, route('app.projects.report', [$this->workspace, $this->project]));
        $this->actingAs($guest)
            ->get(route('app.projects.calendar', [$this->workspace, $this->project]))
            ->assertOk();
    }

    /* ------------------------------------------------------------------ *
     * Timeline
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_timeline_places_a_bar_on_the_day_the_task_says(): void
    {
        // The axis is clamped around today at fine zooms, so these tests state which day
        // "today" is rather than depending on the day the suite happens to run.
        $this->travelTo(CarbonImmutable::parse('2026-03-15 12:00:00', 'UTC'));

        $task = $this->makeTask($this->project, [
            'start_date' => '2026-03-10',
            'due_date' => '2026-03-12',
            'title' => 'Build the thing',
        ]);

        $chart = Livewire::actingAs($this->owner)
            ->test(Timeline::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->set('zoom', 'day')
            ->instance()
            ->timeline;

        $row = collect($chart['rows'])->firstWhere('id', (int) $task->getKey());

        $this->assertNotNull($row);
        $this->assertNotNull($row['bar']);
        $this->assertSame('2026-03-10', $row['bar']['from']);
        $this->assertSame('2026-03-12', $row['bar']['to']);

        // Three days at the day zoom's pixels-per-day, and the offset is whole days from
        // the start of the axis — never a fraction, which is what a timezone shift produces.
        $this->assertEqualsWithDelta(3 * 34.0, $row['bar']['width'], 0.01);
        $this->assertSame(0.0, fmod($row['bar']['left'], 34.0));
    }

    #[Test]
    public function a_task_with_only_a_due_date_is_still_drawn(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-15 12:00:00', 'UTC'));

        $task = $this->makeTask($this->project, ['start_date' => null, 'due_date' => '2026-03-12']);

        $chart = Livewire::actingAs($this->owner)
            ->test(Timeline::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->instance()
            ->timeline;

        $row = collect($chart['rows'])->firstWhere('id', (int) $task->getKey());

        $this->assertNotNull($row['bar']);
        $this->assertSame('2026-03-12', $row['bar']['from']);
        $this->assertSame('2026-03-12', $row['bar']['to']);
    }

    #[Test]
    public function a_task_with_no_dates_is_counted_rather_than_drawn(): void
    {
        $this->makeTask($this->project, ['start_date' => null, 'due_date' => null]);

        $chart = Livewire::actingAs($this->owner)
            ->test(Timeline::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->instance()
            ->timeline;

        $this->assertSame(1, $chart['undated']);
    }

    #[Test]
    public function a_dependency_between_two_visible_bars_becomes_an_arrow(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-15 12:00:00', 'UTC'));

        $first = $this->makeTask($this->project, ['start_date' => '2026-03-02', 'due_date' => '2026-03-04']);
        $second = $this->makeTask($this->project, ['start_date' => '2026-03-06', 'due_date' => '2026-03-08']);

        TaskDependency::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'task_id' => $second->getKey(),
            'depends_on_task_id' => $first->getKey(),
        ]);

        $chart = Livewire::actingAs($this->owner)
            ->test(Timeline::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->instance()
            ->timeline;

        $this->assertCount(1, $chart['dependencies']);
        $this->assertStringStartsWith('M ', $chart['dependencies'][0]['path']);
    }

    #[Test]
    public function milestones_are_drawn_above_the_tasks(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-15 12:00:00', 'UTC'));

        Milestone::factory()->create([
            'project_id' => $this->project->getKey(),
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'Beta',
            'status' => MilestoneStatus::InProgress,
            'start_date' => '2026-03-01',
            'due_date' => '2026-03-20',
        ]);

        $this->makeTask($this->project, ['start_date' => '2026-03-02', 'due_date' => '2026-03-04']);

        $chart = Livewire::actingAs($this->owner)
            ->test(Timeline::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->instance()
            ->timeline;

        $this->assertSame('milestone', $chart['rows'][0]['kind']);
        $this->assertSame('Beta', $chart['rows'][0]['label']);
    }

    #[Test]
    public function shifting_a_bar_moves_both_of_its_dates_together(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-15 12:00:00', 'UTC'));

        $task = $this->makeTask($this->project, ['start_date' => '2026-03-10', 'due_date' => '2026-03-12']);

        Livewire::actingAs($this->owner)
            ->test(Timeline::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->call('shiftTask', (string) $task->getKey(), 3)
            ->assertDispatched('planvio-notify');

        $fresh = $task->fresh();

        $this->assertSame('2026-03-13', $fresh?->start_date?->format('Y-m-d'));
        $this->assertSame('2026-03-15', $fresh?->due_date?->format('Y-m-d'));
    }

    #[Test]
    public function the_axis_always_contains_today(): void
    {
        $this->makeTask($this->project, ['start_date' => '2020-01-01', 'due_date' => '2020-01-05']);

        $chart = Livewire::actingAs($this->owner)
            ->test(Timeline::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->set('zoom', 'quarter')
            ->instance()
            ->timeline;

        $this->assertLessThanOrEqual(now()->toDateString(), $chart['start']);
        $this->assertGreaterThanOrEqual(now()->toDateString(), $chart['end']);
        $this->assertNotNull($chart['todayLeft']);
    }

    /**
     * Livewire hands a download back base64-encoded in the response effects.
     */
    private function captureDownload(mixed $content): ?string
    {
        if (! is_string($content)) {
            return null;
        }

        $decoded = base64_decode($content, true);

        return $decoded === false ? $content : $decoded;
    }
}

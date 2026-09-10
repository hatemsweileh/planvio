<?php

declare(strict_types=1);

namespace Tests\Feature\App;

use App\Enums\WorkspaceRole;
use App\Livewire\App\Calendar\Index as WorkspaceCalendar;
use App\Livewire\App\Projects\Calendar as ProjectCalendar;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The calendar.
 *
 * The bulk of this suite is about one thing: a `date` column is a day, and the calendar
 * must draw it on that day. Every timezone in the provider below has been chosen because
 * a naive implementation gets it wrong — +14 pushes a UTC midnight into tomorrow, −11
 * pulls it into yesterday, and a half-hour zone breaks anything that reasons in whole
 * hours.
 */
final class CalendarTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $member;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->makeWorkspace(['slug' => 'acme', 'timezone' => 'UTC', 'week_starts_on' => 1]);
        $this->member = $this->makeMember($this->workspace, WorkspaceRole::Owner);
        $this->project = $this->makeProject($this->workspace, [], ['slug' => 'website', 'key' => 'WEB']);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function timezones(): array
    {
        return [
            ['UTC'],
            ['Pacific/Kiritimati'],   // +14, the furthest ahead there is
            ['Pacific/Niue'],         // −11
            ['Asia/Kathmandu'],       // +05:45
            ['America/Los_Angeles'],  // −08/−07, with a DST switch
            ['Australia/Sydney'],     // +10/+11, southern DST
        ];
    }

    #[Test]
    #[DataProvider('timezones')]
    public function a_due_date_lands_on_its_own_day_in_every_timezone(string $timezone): void
    {
        $this->workspace->update(['timezone' => $timezone]);

        // The first and the last day of a month are where an off-by-one shows: a shift in
        // either direction moves the task into a neighbouring month entirely.
        foreach (['2026-03-01', '2026-03-31'] as $due) {
            $task = $this->makeTask($this->project, ['due_date' => $due, 'title' => 'Due '.$due]);

            $component = Livewire::actingAs($this->member)
                ->test(WorkspaceCalendar::class, ['workspace' => $this->workspace])
                ->set('anchor', '2026-03-15');

            $buckets = $component->instance()->events['buckets'];

            $this->assertArrayHasKey(
                $due,
                $buckets,
                "A task due {$due} did not appear on {$due} with the workspace in {$timezone}.",
            );

            $found = collect($buckets[$due])->firstWhere('id', (int) $task->getKey());

            $this->assertNotNull($found, "Task {$task->getKey()} is missing from the {$due} cell.");
            $this->assertSame($due, $found['date']);
        }
    }

    #[Test]
    public function the_month_grid_opens_on_the_day_the_workspace_starts_its_week(): void
    {
        // March 2026 begins on a Sunday.
        $this->workspace->update(['week_starts_on' => 1]);

        $monday = Livewire::actingAs($this->member)
            ->test(WorkspaceCalendar::class, ['workspace' => $this->workspace])
            ->set('anchor', '2026-03-15')
            ->instance()
            ->calendar;

        $this->assertSame('Monday', $monday['weekdays'][0]['label']);
        $this->assertSame('2026-02-23', $monday['from']);

        $this->workspace->update(['week_starts_on' => 0]);

        $sunday = Livewire::actingAs($this->member)
            ->test(WorkspaceCalendar::class, ['workspace' => $this->workspace])
            ->set('anchor', '2026-03-15')
            ->instance()
            ->calendar;

        $this->assertSame('Sunday', $sunday['weekdays'][0]['label']);
        $this->assertSame('2026-03-01', $sunday['from']);
    }

    #[Test]
    public function every_row_of_the_month_grid_is_a_whole_week(): void
    {
        $calendar = Livewire::actingAs($this->member)
            ->test(WorkspaceCalendar::class, ['workspace' => $this->workspace])
            ->set('anchor', '2026-03-15')
            ->instance()
            ->calendar;

        $this->assertSame(7, $calendar['columns']);

        foreach ($calendar['rows'] as $row) {
            $this->assertCount(7, $row);
        }
    }

    #[Test]
    public function dragging_a_task_to_another_day_moves_its_due_date(): void
    {
        $task = $this->makeTask($this->project, ['due_date' => '2026-03-10', 'start_date' => null]);

        Livewire::actingAs($this->member)
            ->test(WorkspaceCalendar::class, ['workspace' => $this->workspace])
            ->set('anchor', '2026-03-15')
            ->call('moveTask', (string) $task->getKey(), '2026-03-18')
            ->assertDispatched('planvio-notify');

        $this->assertSame('2026-03-18', $task->fresh()?->due_date?->format('Y-m-d'));
    }

    #[Test]
    public function the_keyboard_shortcut_shifts_the_same_date_the_drag_does(): void
    {
        $task = $this->makeTask($this->project, ['due_date' => '2026-03-10', 'start_date' => null]);

        Livewire::actingAs($this->member)
            ->test(WorkspaceCalendar::class, ['workspace' => $this->workspace])
            ->call('shiftTask', (string) $task->getKey(), 1);

        $this->assertSame('2026-03-11', $task->fresh()?->due_date?->format('Y-m-d'));
    }

    #[Test]
    public function a_move_that_breaks_a_domain_rule_is_refused_and_explained(): void
    {
        $task = $this->makeTask($this->project, ['start_date' => '2026-03-10', 'due_date' => '2026-03-20']);

        Livewire::actingAs($this->member)
            ->test(WorkspaceCalendar::class, ['workspace' => $this->workspace])
            ->call('moveTask', (string) $task->getKey(), '2026-03-01')
            ->assertDispatched('planvio-notify', type: 'error');

        $this->assertSame('2026-03-20', $task->fresh()?->due_date?->format('Y-m-d'));
    }

    #[Test]
    public function a_task_from_another_workspace_cannot_be_moved_through_this_calendar(): void
    {
        $other = $this->makeWorkspace(['slug' => 'northwind']);
        $otherProject = $this->makeProject($other);
        $foreign = $this->makeTask($otherProject, ['due_date' => '2026-03-10']);

        Livewire::actingAs($this->member)
            ->test(WorkspaceCalendar::class, ['workspace' => $this->workspace])
            ->call('moveTask', (string) $foreign->getKey(), '2026-03-18');

        $this->assertSame(
            '2026-03-10',
            Task::withoutWorkspaceScope()->find($foreign->getKey())?->due_date?->format('Y-m-d'),
        );
    }

    #[Test]
    public function milestones_and_project_dates_appear_beside_the_tasks(): void
    {
        $this->project->update(['start_date' => '2026-03-02', 'target_date' => '2026-03-27']);

        Milestone::factory()->create([
            'project_id' => $this->project->getKey(),
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'Beta',
            'due_date' => '2026-03-12',
        ]);

        $buckets = Livewire::actingAs($this->member)
            ->test(WorkspaceCalendar::class, ['workspace' => $this->workspace])
            ->set('anchor', '2026-03-15')
            ->instance()
            ->events['buckets'];

        $this->assertSame('milestone', $buckets['2026-03-12'][0]['type']);
        $this->assertSame('project', $buckets['2026-03-02'][0]['type']);
        $this->assertSame('start', $buckets['2026-03-02'][0]['kind']);
        $this->assertSame('target', $buckets['2026-03-27'][0]['kind']);
    }

    #[Test]
    public function the_type_filter_narrows_what_the_grid_draws(): void
    {
        $this->project->update(['start_date' => '2026-03-02']);
        $this->makeTask($this->project, ['due_date' => '2026-03-10']);

        $component = Livewire::actingAs($this->member)
            ->test(WorkspaceCalendar::class, ['workspace' => $this->workspace])
            ->set('anchor', '2026-03-15')
            ->set('typeFilter', 'tasks');

        $buckets = $component->instance()->events['buckets'];

        $this->assertArrayHasKey('2026-03-10', $buckets);
        $this->assertArrayNotHasKey('2026-03-02', $buckets);
    }

    #[Test]
    public function the_project_calendar_shows_only_that_project(): void
    {
        $other = $this->makeProject($this->workspace, [], ['slug' => 'mobile', 'key' => 'MOB']);

        $mine = $this->makeTask($this->project, ['due_date' => '2026-03-10']);
        $theirs = $this->makeTask($other, ['due_date' => '2026-03-10']);

        $buckets = Livewire::actingAs($this->member)
            ->test(ProjectCalendar::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->set('anchor', '2026-03-15')
            ->instance()
            ->events['buckets'];

        $ids = collect($buckets['2026-03-10'])->pluck('id')->all();

        $this->assertContains((int) $mine->getKey(), $ids);
        $this->assertNotContains((int) $theirs->getKey(), $ids);
    }

    #[Test]
    public function opening_a_task_fills_the_drawer(): void
    {
        $task = $this->makeTask($this->project, ['due_date' => '2026-03-10']);

        Livewire::actingAs($this->member)
            ->test(WorkspaceCalendar::class, ['workspace' => $this->workspace])
            ->call('openTask', (string) $task->getKey())
            ->assertSet('openTaskId', (int) $task->getKey())
            ->assertSet('drawerOpen', true)
            ->assertSet('rescheduleTo', '2026-03-10')
            ->call('closeTask')
            ->assertSet('drawerOpen', false);
    }

    #[Test]
    public function the_view_switches_without_losing_the_day_you_were_on(): void
    {
        Livewire::actingAs($this->member)
            ->test(WorkspaceCalendar::class, ['workspace' => $this->workspace])
            ->set('anchor', '2026-03-15')
            ->call('setMode', 'week')
            ->assertSet('mode', 'week')
            ->assertSet('anchor', '2026-03-15')
            ->call('goNext')
            ->assertSet('anchor', '2026-03-22');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\App;

use App\Enums\WorkspaceRole;
use App\Livewire\App\Time\Concerns\ManagesTime;
use App\Livewire\App\Time\ProjectTime;
use App\Livewire\App\Time\Sheet;
use App\Livewire\App\Time\Timer;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Timers, timesheets and the project time screen.
 */
final class TimeTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $member;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->makeWorkspace(['slug' => 'acme', 'timezone' => 'UTC', 'week_starts_on' => 1]);
        $this->member = $this->makeMember($this->workspace, WorkspaceRole::Owner, ['timezone' => 'UTC']);
        $this->project = $this->makeProject($this->workspace, [], ['slug' => 'website', 'key' => 'WEB']);
    }

    /**
     * @return list<array{0: string, 1: int|null}>
     */
    public static function durations(): array
    {
        return [
            ['90', 90],
            ['1:30', 90],
            ['1h30', 90],
            ['1h 30m', 90],
            ['1.5h', 90],
            ['1,5h', 90],
            ['2h', 120],
            ['45m', 45],
            ['0:45', 45],
            ['', null],
            ['soon', null],
            ['-30', null],
        ];
    }

    #[Test]
    #[DataProvider('durations')]
    public function a_duration_is_read_the_way_people_type_it(string $input, ?int $expected): void
    {
        $this->assertSame($expected, ManagesTime::parseMinutes($input));
    }

    #[Test]
    public function the_timesheet_renders_for_a_member(): void
    {
        $this->actingAs($this->member)
            ->get(route('app.time', $this->workspace))
            ->assertOk();
    }

    #[Test]
    public function the_project_time_screen_renders_for_a_member(): void
    {
        $this->actingAs($this->member)
            ->get(route('app.projects.time', [$this->workspace, $this->project]))
            ->assertOk();
    }

    #[Test]
    public function neither_time_screen_is_reachable_from_another_workspace(): void
    {
        $outsider = $this->makeMember($this->makeWorkspace(['slug' => 'northwind']));

        $this->assertDeniedAccess($outsider, route('app.time', $this->workspace));
        $this->assertDeniedAccess($outsider, route('app.projects.time', [$this->workspace, $this->project]));
    }

    #[Test]
    public function logging_time_records_whole_minutes(): void
    {
        Livewire::actingAs($this->member)
            ->test(Sheet::class, ['workspace' => $this->workspace])
            ->call('openEntryForm')
            ->set('formProject', (string) $this->project->getKey())
            ->set('formDuration', '1h 30m')
            ->set('formDescription', 'Wireframes')
            ->call('saveEntry')
            ->assertHasNoErrors()
            ->assertSet('formOpen', false);

        $entry = TimeEntry::query()->firstOrFail();

        $this->assertSame(90, (int) $entry->minutes);
        $this->assertSame((int) $this->member->getKey(), (int) $entry->user_id);
        $this->assertSame('Wireframes', $entry->description);
        $this->assertTrue((bool) $entry->is_billable);
    }

    #[Test]
    public function a_duration_that_is_not_a_duration_is_refused_without_writing_anything(): void
    {
        Livewire::actingAs($this->member)
            ->test(Sheet::class, ['workspace' => $this->workspace])
            ->call('openEntryForm')
            ->set('formProject', (string) $this->project->getKey())
            ->set('formDuration', 'a while')
            ->call('saveEntry')
            ->assertHasErrors('formDuration');

        $this->assertSame(0, TimeEntry::query()->count());
    }

    #[Test]
    public function an_entry_longer_than_a_day_is_refused(): void
    {
        Livewire::actingAs($this->member)
            ->test(Sheet::class, ['workspace' => $this->workspace])
            ->call('openEntryForm')
            ->set('formProject', (string) $this->project->getKey())
            ->set('formDuration', '25h')
            ->call('saveEntry')
            ->assertHasErrors('formDuration');

        $this->assertSame(0, TimeEntry::query()->count());
    }

    #[Test]
    public function starting_a_timer_stops_the_one_already_running(): void
    {
        $other = $this->makeProject($this->workspace, [], ['slug' => 'mobile', 'key' => 'MOB']);

        $component = Livewire::actingAs($this->member)
            ->test(Sheet::class, ['workspace' => $this->workspace])
            ->set('formProject', (string) $this->project->getKey())
            ->call('startTimer');

        $first = TimeEntry::query()->where('project_id', $this->project->getKey())->firstOrFail();
        $this->assertTrue((bool) $first->is_running);

        $component
            ->set('formProject', (string) $other->getKey())
            ->call('startTimer');

        $this->assertFalse((bool) $first->fresh()?->is_running);
        $this->assertSame(
            1,
            TimeEntry::query()->where('user_id', $this->member->getKey())->where('is_running', true)->count(),
            'A person must never have two clocks running at once.',
        );
    }

    #[Test]
    public function stopping_a_timer_writes_the_minutes(): void
    {
        $component = Livewire::actingAs($this->member)
            ->test(Sheet::class, ['workspace' => $this->workspace])
            ->set('formProject', (string) $this->project->getKey())
            ->call('startTimer');

        $entry = TimeEntry::query()->firstOrFail();
        $entry->forceFill(['started_at' => now()->subMinutes(45)])->save();

        $component->call('stopTimer')->assertDispatched('planvio-notify');

        $stopped = $entry->fresh();

        $this->assertFalse((bool) $stopped?->is_running);
        $this->assertGreaterThanOrEqual(45, (int) $stopped?->minutes);
    }

    #[Test]
    public function an_entry_can_be_corrected_and_deleted(): void
    {
        $entry = TimeEntry::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'project_id' => $this->project->getKey(),
            'user_id' => $this->member->getKey(),
            'minutes' => 30,
            'spent_on' => now()->toDateString(),
            'is_running' => false,
        ]);

        $component = Livewire::actingAs($this->member)
            ->test(Sheet::class, ['workspace' => $this->workspace])
            ->call('editEntry', (string) $entry->getKey())
            ->assertSet('editingId', (int) $entry->getKey())
            ->set('formDuration', '2h')
            ->call('saveEntry')
            ->assertHasNoErrors();

        $this->assertSame(120, (int) $entry->fresh()?->minutes);

        $component->call('deleteEntry', (string) $entry->getKey());

        $this->assertNull(TimeEntry::query()->find($entry->getKey()));
    }

    #[Test]
    public function a_time_entry_from_another_workspace_cannot_be_touched_here(): void
    {
        $other = $this->makeWorkspace(['slug' => 'northwind']);
        $otherProject = $this->makeProject($other);

        $foreign = TimeEntry::factory()->create([
            'workspace_id' => $other->getKey(),
            'project_id' => $otherProject->getKey(),
            'user_id' => $this->makeMember($other)->getKey(),
            'minutes' => 60,
            'spent_on' => now()->toDateString(),
            'is_running' => false,
        ]);

        Livewire::actingAs($this->member)
            ->test(Sheet::class, ['workspace' => $this->workspace])
            ->call('deleteEntry', (string) $foreign->getKey());

        $this->assertNotNull(
            TimeEntry::withoutWorkspaceScope()->find($foreign->getKey()),
            'A time entry from another workspace was deleted through this workspace\'s timesheet.',
        );
    }

    #[Test]
    public function the_week_grid_always_has_seven_days_and_starts_where_the_workspace_says(): void
    {
        $this->workspace->update(['week_starts_on' => 0]);

        $week = Livewire::actingAs($this->member)
            ->test(Sheet::class, ['workspace' => $this->workspace])
            ->set('anchor', '2026-03-11')
            ->instance()
            ->week;

        $this->assertCount(7, $week['days']);
        $this->assertSame('2026-03-08', $week['from']);
        $this->assertSame('2026-03-14', $week['to']);
    }

    #[Test]
    public function the_standalone_timer_renders_and_starts_a_clock_where_it_is_embedded(): void
    {
        Livewire::actingAs($this->member)
            ->test(Timer::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->assertOk()
            ->call('startTimer')
            ->assertDispatched('planvio-notify');

        $this->assertSame(
            (int) $this->project->getKey(),
            (int) TimeEntry::query()->where('is_running', true)->value('project_id'),
        );
    }

    #[Test]
    public function a_member_without_view_all_time_only_sees_their_own_entries(): void
    {
        $colleague = $this->makeMember($this->workspace, WorkspaceRole::Member);
        $viewer = $this->makeMember($this->workspace, WorkspaceRole::Member);

        foreach ([$colleague, $viewer] as $person) {
            TimeEntry::factory()->create([
                'workspace_id' => $this->workspace->getKey(),
                'project_id' => $this->project->getKey(),
                'user_id' => $person->getKey(),
                'minutes' => 60,
                'spent_on' => now()->toDateString(),
                'is_running' => false,
            ]);
        }

        $component = Livewire::actingAs($viewer)
            ->test(ProjectTime::class, ['workspace' => $this->workspace, 'project' => $this->project]);

        $this->assertTrue($component->instance()->breakdown['own']);
        $this->assertSame(1, $component->instance()->entries->total());
    }

    #[Test]
    public function a_manager_with_view_all_time_sees_the_whole_project(): void
    {
        $colleague = $this->makeMember($this->workspace, WorkspaceRole::Member);

        foreach ([$colleague, $this->member] as $person) {
            TimeEntry::factory()->create([
                'workspace_id' => $this->workspace->getKey(),
                'project_id' => $this->project->getKey(),
                'user_id' => $person->getKey(),
                'minutes' => 60,
                'spent_on' => now()->toDateString(),
                'is_running' => false,
            ]);
        }

        $component = Livewire::actingAs($this->member)
            ->test(ProjectTime::class, ['workspace' => $this->workspace, 'project' => $this->project]);

        $this->assertFalse($component->instance()->breakdown['own']);
        $this->assertSame(2, $component->instance()->entries->total());
        $this->assertSame(120, $component->instance()->breakdown['total']);
    }
}

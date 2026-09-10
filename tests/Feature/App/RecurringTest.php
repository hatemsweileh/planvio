<?php

declare(strict_types=1);

namespace Tests\Feature\App;

use App\Actions\Recurring\RecurrenceCalculator;
use App\Actions\Recurring\RecurrenceSchedule;
use App\Actions\Recurring\RecurringTaskTemplate;
use App\Enums\ProjectRole;
use App\Enums\RecurrenceFrequency;
use App\Enums\WorkspaceRole;
use App\Livewire\App\Recurring\Index as Recurring;
use App\Models\Project;
use App\Models\RecurringTask;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The recurrence screen.
 *
 * The assertion that matters most is that the preview and the scheduler agree. A preview
 * computed by anything other than {@see RecurrenceCalculator} would be a second
 * implementation of the hardest logic in the product — month-end clamping, interval
 * anchoring, filters that never coincide — and the two would drift silently, which is worse
 * than showing nothing at all.
 */
final class RecurringTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->makeWorkspace(['slug' => 'acme', 'timezone' => 'UTC', 'week_starts_on' => 1]);
        $this->owner = $this->makeMember($this->workspace, WorkspaceRole::Owner);
        $this->project = $this->makeProject($this->workspace, [], ['slug' => 'website', 'key' => 'WEB']);
    }

    /* ------------------------------------------------------------------ *
     * The preview
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_preview_matches_the_calculator(): void
    {
        $component = $this->screen()
            ->call('create')
            ->set('frequency', RecurrenceFrequency::Weekly->value)
            ->set('interval', 2)
            ->set('startsOn', '2026-03-02')
            ->set('weekdays', [1, 4]);

        $schedule = RecurrenceSchedule::make(
            frequency: RecurrenceFrequency::Weekly,
            startsOn: '2026-03-02',
            interval: 2,
            byWeekday: [1, 4],
        );

        $this->assertSame(
            $this->fromCalculator($schedule, 5),
            $this->previewDates($component),
        );
    }

    #[Test]
    public function a_monthly_rule_on_the_thirty_first_clamps_the_way_the_calculator_does(): void
    {
        $component = $this->screen()
            ->call('create')
            ->set('frequency', RecurrenceFrequency::Monthly->value)
            ->set('interval', 1)
            ->set('startsOn', '2026-01-31')
            ->set('monthdays', [31]);

        $schedule = RecurrenceSchedule::make(
            frequency: RecurrenceFrequency::Monthly,
            startsOn: '2026-01-31',
            interval: 1,
            byMonthday: [31],
        );

        $dates = $this->previewDates($component);

        $this->assertSame($this->fromCalculator($schedule, 5), $dates);

        // The proof that the clamp is real rather than incidental: February has no 31st.
        $this->assertSame('2026-02-28', $dates[1]);
    }

    #[Test]
    public function the_preview_stops_where_the_rule_stops(): void
    {
        $component = $this->screen()
            ->call('create')
            ->set('frequency', RecurrenceFrequency::Daily->value)
            ->set('interval', 1)
            ->set('startsOn', '2026-03-02')
            ->set('maxOccurrences', '3');

        $this->assertSame(
            ['2026-03-02', '2026-03-03', '2026-03-04'],
            $this->previewDates($component),
        );

        $component->set('maxOccurrences', '')->set('endsOn', '2026-03-03');

        $this->assertSame(['2026-03-02', '2026-03-03'], $this->previewDates($component));
    }

    #[Test]
    public function a_rule_that_can_never_fire_says_so_instead_of_showing_nothing(): void
    {
        $this->screen()
            ->call('create')
            ->set('frequency', RecurrenceFrequency::Custom->value)
            ->set('interval', 7)
            ->set('startsOn', '2026-03-02')
            ->set('weekdays', [2])
            ->set('monthdays', [1])
            ->assertSee('No date satisfies every restriction');
    }

    /* ------------------------------------------------------------------ *
     * Writing
     * ------------------------------------------------------------------ */

    #[Test]
    public function creating_a_recurrence_stores_the_template_and_the_first_occurrence(): void
    {
        $this->screen()
            ->call('create')
            ->set('title', 'Weekly status note')
            ->set('description', 'What moved this week.')
            ->set('priority', 'high')
            ->set('frequency', RecurrenceFrequency::Weekly->value)
            ->set('interval', 1)
            ->set('weekdays', [1])
            ->set('startsOn', '2026-03-02')
            ->set('estimate', '1.5')
            ->call('save')
            ->assertHasNoErrors();

        $rule = RecurringTask::query()->firstOrFail();
        $template = RecurringTaskTemplate::fromArray($rule->template ?? []);

        $this->assertSame('Weekly status note', $template->title);
        $this->assertSame(90, $template->estimateMinutes);
        $this->assertSame(RecurrenceFrequency::Weekly, $rule->frequency);
        $this->assertSame([1], $rule->by_weekday);
        $this->assertSame('2026-03-02', $rule->next_run_on?->format('Y-m-d'));
        $this->assertTrue($rule->is_active);
    }

    #[Test]
    public function a_rule_with_no_title_is_refused(): void
    {
        $this->screen()
            ->call('create')
            ->set('title', '')
            ->call('save')
            ->assertHasErrors('title');

        $this->assertSame(0, RecurringTask::query()->count());
    }

    #[Test]
    public function an_end_date_before_the_start_is_refused(): void
    {
        $this->screen()
            ->call('create')
            ->set('title', 'Backwards')
            ->set('startsOn', '2026-03-10')
            ->set('endsOn', '2026-03-01')
            ->call('save')
            ->assertHasErrors('endsOn');
    }

    #[Test]
    public function pausing_and_resuming_moves_the_cursor_rather_than_replaying_history(): void
    {
        $rule = $this->rule(['starts_on' => '2026-01-05', 'last_run_on' => '2026-03-02', 'next_run_on' => '2026-03-09']);

        $component = $this->screen()->call('toggleActive', $rule->getKey());

        $this->assertFalse($rule->fresh()?->is_active);
        $component->assertSee('Paused');

        $component->call('toggleActive', $rule->getKey());

        $resumed = $rule->fresh();

        $this->assertTrue($resumed?->is_active);
        $this->assertSame('2026-03-09', $resumed?->next_run_on?->format('Y-m-d'));
    }

    #[Test]
    public function deleting_a_recurrence_confirms_first_and_leaves_its_tasks_alone(): void
    {
        $rule = $this->rule();
        $task = $this->makeTask($this->project, ['recurring_task_id' => $rule->getKey()]);

        $component = $this->screen()->call('confirmDelete', $rule->getKey());

        $component->assertSee('Delete this recurrence?');
        $this->assertSame(1, RecurringTask::query()->count());

        $component->call('delete');

        $this->assertSame(0, RecurringTask::query()->count());
        $this->assertNotNull($task->fresh());
        $this->assertNull($task->fresh()?->recurring_task_id);
    }

    #[Test]
    public function the_list_summarises_a_rule_in_a_sentence(): void
    {
        $this->rule([
            'frequency' => RecurrenceFrequency::Weekly,
            'interval' => 2,
            'by_weekday' => [1, 4],
        ]);

        $this->screen()
            ->assertSee('Every 2 weeks')
            ->assertSee('Mon')
            ->assertSee('Thu');
    }

    #[Test]
    public function an_empty_project_gets_a_designed_empty_state(): void
    {
        $this->screen()
            ->assertSee('Nothing repeats here yet')
            ->assertSee('Create the first one');
    }

    /* ------------------------------------------------------------------ *
     * Permissions
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_plain_member_may_look_but_not_create(): void
    {
        $member = $this->makeMember($this->workspace, WorkspaceRole::Member);

        $this->actingAs($member)
            ->get(route('app.projects.recurring', [$this->workspace, $this->project]))
            ->assertOk()
            ->assertDontSee('New recurrence');

        Livewire::actingAs($member)
            ->test(Recurring::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->call('create')
            ->assertForbidden();
    }

    #[Test]
    public function a_project_manager_may_create(): void
    {
        $manager = $this->makeMember($this->workspace, WorkspaceRole::Manager);

        $this->project->members()->attach($manager->id, [
            'role' => ProjectRole::Manager->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($manager)
            ->test(Recurring::class, ['workspace' => $this->workspace, 'project' => $this->project])
            ->call('create')
            ->set('title', 'Manager rule')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, RecurringTask::query()->count());
    }

    #[Test]
    public function somebody_from_another_workspace_cannot_reach_it(): void
    {
        $outsider = $this->makeMember($this->makeWorkspace(['slug' => 'northwind']));

        $this->assertDeniedAccess(
            $outsider,
            route('app.projects.recurring', [$this->workspace, $this->project]),
        );
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    private function screen(): Testable
    {
        return Livewire::actingAs($this->owner)
            ->test(Recurring::class, ['workspace' => $this->workspace, 'project' => $this->project]);
    }

    /**
     * @return list<string>
     */
    private function previewDates(Testable $component): array
    {
        return array_map(
            static fn (CarbonImmutable $date): string => $date->toDateString(),
            $component->instance()->preview,
        );
    }

    /**
     * @return list<string>
     */
    private function fromCalculator(RecurrenceSchedule $schedule, int $count): array
    {
        $calculator = app(RecurrenceCalculator::class);
        $dates = [];
        $cursor = $calculator->first($schedule);

        while ($cursor !== null && count($dates) < $count) {
            $dates[] = $cursor->toDateString();
            $cursor = $calculator->next($schedule, $cursor);
        }

        return $dates;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function rule(array $attributes = []): RecurringTask
    {
        // `$attributes` first: the union operator keeps the left-hand value for a duplicate
        // key, which is what lets a caller override any of the defaults below.
        return RecurringTask::query()->create($attributes + [
            'workspace_id' => $this->workspace->getKey(),
            'project_id' => $this->project->getKey(),
            'template' => (new RecurringTaskTemplate(title: 'Weekly status note'))->toArray(),
            'frequency' => RecurrenceFrequency::Weekly,
            'interval' => 1,
            'by_weekday' => [1],
            'by_monthday' => null,
            'starts_on' => '2026-03-02',
            'ends_on' => null,
            'next_run_on' => '2026-03-02',
            'last_run_on' => null,
            'occurrences_generated' => 0,
            'max_occurrences' => null,
            'is_active' => true,
            'created_by' => $this->owner->getKey(),
        ]);
    }
}

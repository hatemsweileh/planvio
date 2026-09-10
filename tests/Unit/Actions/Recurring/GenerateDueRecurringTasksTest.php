<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Recurring;

use App\Actions\Recurring\CreateRecurringTask;
use App\Actions\Recurring\GenerateDueRecurringTasks;
use App\Actions\Recurring\InvalidRecurrence;
use App\Actions\Recurring\RecurrenceSchedule;
use App\Actions\Recurring\RecurringTaskTemplate;
use App\Actions\Recurring\UpdateRecurringTask;
use App\Enums\Priority;
use App\Enums\RecurrenceFrequency;
use App\Enums\StatusCategory;
use App\Models\Project;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GenerateDueRecurringTasksTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Project $project;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->makeWorkspace();
        $this->actor = $this->makeMember($this->workspace);
        $this->project = $this->makeProject($this->workspace);

        TaskStatus::factory()->asDefault()->create([
            'project_id' => $this->project->getKey(),
            'workspace_id' => $this->workspace->getKey(),
            'category' => StatusCategory::Todo,
        ]);
    }

    #[Test]
    public function it_generates_the_occurrence_that_is_due(): void
    {
        $rule = $this->rule('2026-03-01');

        $created = $this->generate('2026-03-01');

        $this->assertSame(1, $created);

        $task = Task::query()->where('recurring_task_id', $rule->getKey())->firstOrFail();

        $this->assertSame('Weekly report', $task->title);
        $this->assertSame('2026-03-01', $task->start_date->toDateString());
        $this->assertSame((int) $this->actor->getKey(), (int) $task->reporter_id);
    }

    /**
     * The one property the scheduler cannot do without: a cron that fires twice, or a job
     * retried after a timeout, must not mint the same occurrence again.
     */
    #[Test]
    public function a_second_tick_on_the_same_day_generates_nothing(): void
    {
        $this->rule('2026-03-01');

        $this->assertSame(1, $this->generate('2026-03-01'));
        $this->assertSame(0, $this->generate('2026-03-01'));
        $this->assertSame(1, Task::query()->count());
    }

    /**
     * Even with the rule's cursor wound back — the state a process killed between the insert
     * and the cursor update would leave behind — the occurrence is not created twice.
     */
    #[Test]
    public function an_occurrence_that_already_exists_is_never_recreated(): void
    {
        $rule = $this->rule('2026-03-01');

        $this->generate('2026-03-01');

        $rule->forceFill([
            'next_run_on' => '2026-03-01',
            'last_run_on' => null,
            'occurrences_generated' => 0,
            'is_active' => true,
        ])->save();

        $this->assertSame(0, $this->generate('2026-03-01'));
        $this->assertSame(1, Task::query()->count());
    }

    /**
     * A deleted occurrence was still generated. Regenerating it would resurrect work somebody
     * deliberately threw away.
     */
    #[Test]
    public function a_deleted_occurrence_is_not_regenerated(): void
    {
        $rule = $this->rule('2026-03-01');

        $this->generate('2026-03-01');

        Task::query()->firstOrFail()->delete();

        $rule->forceFill(['next_run_on' => '2026-03-01', 'is_active' => true])->save();

        $this->assertSame(0, $this->generate('2026-03-01'));
    }

    #[Test]
    public function a_cron_that_missed_several_days_catches_up(): void
    {
        $this->rule('2026-03-01');

        $created = $this->generate('2026-03-05');

        $this->assertSame(5, $created);
        $this->assertSame(
            ['2026-03-01', '2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05'],
            Task::query()->orderBy('id')->pluck('start_date')
                ->map(static fn ($date): string => $date->toDateString())
                ->all(),
        );
    }

    #[Test]
    public function it_stops_and_deactivates_at_the_occurrence_limit(): void
    {
        $rule = $this->rule('2026-03-01', maxOccurrences: 3);

        $created = $this->generate('2026-03-10');

        $this->assertSame(3, $created);

        $fresh = $rule->fresh();

        $this->assertFalse($fresh->is_active);
        $this->assertNull($fresh->next_run_on);
        $this->assertSame(3, (int) $fresh->occurrences_generated);
    }

    #[Test]
    public function it_stops_at_the_end_date(): void
    {
        $rule = $this->rule('2026-03-01', endsOn: '2026-03-03');

        $created = $this->generate('2026-03-10');

        $this->assertSame(3, $created);
        $this->assertFalse($rule->fresh()->is_active);
    }

    #[Test]
    public function it_advances_the_cursor_and_the_counter(): void
    {
        $rule = $this->rule('2026-03-01');

        $this->generate('2026-03-02');

        $fresh = $rule->fresh();

        $this->assertSame('2026-03-02', $fresh->last_run_on->toDateString());
        $this->assertSame('2026-03-03', $fresh->next_run_on->toDateString());
        $this->assertSame(2, (int) $fresh->occurrences_generated);
    }

    #[Test]
    public function an_inactive_rule_generates_nothing(): void
    {
        $this->rule('2026-03-01', active: false);

        $this->assertSame(0, $this->generate('2026-03-05'));
    }

    #[Test]
    public function a_rule_that_is_not_due_yet_generates_nothing(): void
    {
        $this->rule('2026-04-01');

        $this->assertSame(0, $this->generate('2026-03-05'));
    }

    #[Test]
    public function it_can_be_narrowed_to_one_workspace(): void
    {
        $this->rule('2026-03-01');

        $otherWorkspace = $this->makeWorkspace();
        $otherActor = $this->makeMember($otherWorkspace);
        $otherProject = $this->makeProject($otherWorkspace);

        TaskStatus::factory()->asDefault()->create([
            'project_id' => $otherProject->getKey(),
            'workspace_id' => $otherWorkspace->getKey(),
        ]);

        $this->app->make(CreateRecurringTask::class)(
            $otherProject,
            new RecurringTaskTemplate(title: 'Elsewhere'),
            RecurrenceSchedule::make(RecurrenceFrequency::Daily, '2026-03-01'),
            $otherActor,
        );

        $created = $this->app->make(GenerateDueRecurringTasks::class)('2026-03-01', $this->workspace);

        $this->assertSame(1, $created);
        $this->assertSame(1, Task::withoutWorkspaceScope()->count());
    }

    /**
     * An assignee who has left the workspace would fail the invariant in CreateTask and stall
     * every future tick, so the occurrence is generated unassigned instead.
     */
    #[Test]
    public function an_assignee_who_left_the_workspace_is_dropped(): void
    {
        $stranger = User::factory()->create();

        $this->app->make(CreateRecurringTask::class)(
            $this->project,
            new RecurringTaskTemplate(title: 'Weekly report', assigneeId: (int) $stranger->getKey()),
            RecurrenceSchedule::make(RecurrenceFrequency::Daily, '2026-03-01'),
            $this->actor,
        );

        $this->assertSame(1, $this->generate('2026-03-01'));
        $this->assertNull(Task::query()->firstOrFail()->assignee_id);
    }

    #[Test]
    public function a_due_offset_becomes_a_due_date_on_each_occurrence(): void
    {
        $this->app->make(CreateRecurringTask::class)(
            $this->project,
            new RecurringTaskTemplate(title: 'Weekly report', dueDayOffset: 4),
            RecurrenceSchedule::make(RecurrenceFrequency::Daily, '2026-03-01'),
            $this->actor,
        );

        $this->generate('2026-03-01');

        $this->assertSame('2026-03-05', Task::query()->firstOrFail()->due_date->toDateString());
    }

    /* ----------------------------------------------------------------- *
     * Rule management
     * ----------------------------------------------------------------- */

    #[Test]
    public function creating_a_rule_computes_its_first_run(): void
    {
        $rule = $this->rule('2026-03-01');

        $this->assertSame('2026-03-01', $rule->next_run_on->toDateString());
        $this->assertSame(0, (int) $rule->occurrences_generated);
    }

    #[Test]
    public function it_refuses_a_schedule_that_ends_before_it_starts(): void
    {
        $this->expectException(InvalidRecurrence::class);

        $this->app->make(CreateRecurringTask::class)(
            $this->project,
            new RecurringTaskTemplate(title: 'Weekly report'),
            RecurrenceSchedule::make(RecurrenceFrequency::Daily, '2026-03-10', endsOn: '2026-03-01'),
            $this->actor,
        );
    }

    #[Test]
    public function it_refuses_a_rule_with_no_title(): void
    {
        $this->expectException(InvalidRecurrence::class);

        $this->app->make(CreateRecurringTask::class)(
            $this->project,
            new RecurringTaskTemplate(title: '   '),
            RecurrenceSchedule::make(RecurrenceFrequency::Daily, '2026-03-01'),
            $this->actor,
        );
    }

    /**
     * A rule that has already produced tasks picks up after the last one rather than
     * replaying its own history under the new schedule.
     */
    #[Test]
    public function changing_the_schedule_moves_the_cursor_forward_not_back(): void
    {
        $rule = $this->rule('2026-03-01');

        $this->generate('2026-03-03');

        $this->app->make(UpdateRecurringTask::class)(
            $rule->fresh(),
            $this->actor,
            schedule: RecurrenceSchedule::make(RecurrenceFrequency::Weekly, '2026-03-01', byWeekday: [1]),
        );

        $this->assertSame('2026-03-09', $rule->fresh()->next_run_on->toDateString());
    }

    #[Test]
    public function the_template_can_be_edited_without_touching_the_schedule(): void
    {
        $rule = $this->rule('2026-03-01');

        $this->app->make(UpdateRecurringTask::class)(
            $rule,
            $this->actor,
            template: new RecurringTaskTemplate(title: 'Renamed', priority: Priority::Urgent),
        );

        $this->generate('2026-03-01');

        $task = Task::query()->firstOrFail();

        $this->assertSame('Renamed', $task->title);
        $this->assertSame(Priority::Urgent, $task->priority);
        $this->assertSame('2026-03-01', $rule->fresh()->last_run_on->toDateString());
    }

    private function rule(
        string $startsOn,
        ?string $endsOn = null,
        ?int $maxOccurrences = null,
        bool $active = true,
    ): RecurringTask {
        return $this->app->make(CreateRecurringTask::class)(
            $this->project,
            new RecurringTaskTemplate(title: 'Weekly report'),
            RecurrenceSchedule::make(
                RecurrenceFrequency::Daily,
                $startsOn,
                endsOn: $endsOn,
                maxOccurrences: $maxOccurrences,
            ),
            $this->actor,
            $active,
        );
    }

    private function generate(string $asOf): int
    {
        return $this->app->make(GenerateDueRecurringTasks::class)($asOf);
    }
}

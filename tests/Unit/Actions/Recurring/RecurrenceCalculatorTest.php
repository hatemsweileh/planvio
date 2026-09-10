<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Recurring;

use App\Actions\Recurring\InvalidRecurrence;
use App\Actions\Recurring\RecurrenceCalculator;
use App\Actions\Recurring\RecurrenceSchedule;
use App\Enums\RecurrenceFrequency;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pure date arithmetic — no database, so these run as fast as the assertions they make.
 *
 * The two properties that matter run unattended from cron: the calculator always terminates,
 * and occurrences never drift. Both get their own cases below.
 */
final class RecurrenceCalculatorTest extends TestCase
{
    private RecurrenceCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new RecurrenceCalculator;
    }

    #[Test]
    public function daily_repeats_on_the_interval(): void
    {
        $schedule = RecurrenceSchedule::make(RecurrenceFrequency::Daily, '2026-03-01', interval: 3);

        $this->assertSame('2026-03-01', $this->first($schedule));
        $this->assertSame('2026-03-04', $this->after($schedule, '2026-03-01'));
        $this->assertSame('2026-03-07', $this->after($schedule, '2026-03-04'));
    }

    /**
     * A rule whose cursor is years behind must be answered by arithmetic, not by stepping a
     * day at a time until it catches up.
     */
    #[Test]
    public function a_daily_rule_years_behind_still_answers_in_one_step(): void
    {
        $schedule = RecurrenceSchedule::make(RecurrenceFrequency::Daily, '2016-01-01', interval: 7);

        // 2016-01-01 to 2026-03-06 is 3,717 days, an exact multiple of seven.
        $this->assertSame('2026-03-06', $this->after($schedule, '2026-03-01'));
    }

    #[Test]
    public function weekly_fires_on_the_chosen_weekdays(): void
    {
        // 2026-03-02 is a Monday. Mondays and Thursdays, every week.
        $schedule = RecurrenceSchedule::make(
            RecurrenceFrequency::Weekly,
            '2026-03-02',
            byWeekday: [1, 4],
        );

        $this->assertSame('2026-03-02', $this->first($schedule));
        $this->assertSame('2026-03-05', $this->after($schedule, '2026-03-02'));
        $this->assertSame('2026-03-09', $this->after($schedule, '2026-03-05'));
    }

    #[Test]
    public function weekly_skips_whole_weeks_on_the_interval(): void
    {
        $schedule = RecurrenceSchedule::make(
            RecurrenceFrequency::Weekly,
            '2026-03-02',
            interval: 2,
            byWeekday: [1],
        );

        $this->assertSame('2026-03-02', $this->first($schedule));
        $this->assertSame('2026-03-16', $this->after($schedule, '2026-03-02'));
    }

    #[Test]
    public function weekly_defaults_to_the_weekday_it_starts_on(): void
    {
        $schedule = RecurrenceSchedule::make(RecurrenceFrequency::Weekly, '2026-03-04');

        $this->assertSame('2026-03-04', $this->first($schedule));
        $this->assertSame('2026-03-11', $this->after($schedule, '2026-03-04'));
    }

    /**
     * The 31st of a 30-day month lands on the 30th rather than being skipped — and, because
     * every occurrence is computed from `starts_on` rather than from the previous one, the
     * month after that is the 31st again instead of drifting down a day at a time.
     */
    #[Test]
    public function monthly_clamps_to_the_length_of_the_month_without_drifting(): void
    {
        $schedule = RecurrenceSchedule::make(RecurrenceFrequency::Monthly, '2026-01-31');

        $this->assertSame('2026-01-31', $this->first($schedule));
        $this->assertSame('2026-02-28', $this->after($schedule, '2026-01-31'));
        $this->assertSame('2026-03-31', $this->after($schedule, '2026-02-28'));
        $this->assertSame('2026-04-30', $this->after($schedule, '2026-03-31'));
        $this->assertSame('2026-05-31', $this->after($schedule, '2026-04-30'));
    }

    #[Test]
    public function monthly_fires_on_each_chosen_day_of_the_month(): void
    {
        $schedule = RecurrenceSchedule::make(
            RecurrenceFrequency::Monthly,
            '2026-03-01',
            byMonthday: [1, 15],
        );

        $this->assertSame('2026-03-01', $this->first($schedule));
        $this->assertSame('2026-03-15', $this->after($schedule, '2026-03-01'));
        $this->assertSame('2026-04-01', $this->after($schedule, '2026-03-15'));
    }

    #[Test]
    public function yearly_clamps_the_twenty_ninth_of_february(): void
    {
        $schedule = RecurrenceSchedule::make(RecurrenceFrequency::Yearly, '2024-02-29');

        $this->assertSame('2024-02-29', $this->first($schedule));
        $this->assertSame('2025-02-28', $this->after($schedule, '2024-02-29'));
        $this->assertSame('2028-02-29', $this->after($schedule, '2027-03-01'));
    }

    #[Test]
    public function custom_filters_the_daily_walk_by_weekday(): void
    {
        // Every second day from a Monday, but only the ones that land on a weekday.
        $schedule = RecurrenceSchedule::make(
            RecurrenceFrequency::Custom,
            '2026-03-02',
            interval: 2,
            byWeekday: [1, 2, 3, 4, 5],
        );

        $this->assertSame('2026-03-02', $this->first($schedule));
        $this->assertSame('2026-03-04', $this->after($schedule, '2026-03-02'));
        $this->assertSame('2026-03-06', $this->after($schedule, '2026-03-04'));
        // 2026-03-08 is a Sunday and is filtered out.
        $this->assertSame('2026-03-10', $this->after($schedule, '2026-03-06'));
    }

    #[Test]
    public function a_schedule_stops_at_its_end_date(): void
    {
        $schedule = RecurrenceSchedule::make(
            RecurrenceFrequency::Daily,
            '2026-03-01',
            endsOn: '2026-03-03',
        );

        $this->assertSame('2026-03-03', $this->after($schedule, '2026-03-02'));
        $this->assertNull($this->calculator->next($schedule, CarbonImmutable::parse('2026-03-03')));
    }

    /**
     * A filter no date can satisfy has to come back as "never", not as a search that runs
     * until the request gives up.
     */
    #[Test]
    public function a_filter_nothing_satisfies_returns_no_occurrence(): void
    {
        $schedule = RecurrenceSchedule::make(
            RecurrenceFrequency::Custom,
            '2026-03-02',
            interval: 7,
            byWeekday: [2],
        );

        $this->assertNull($this->calculator->first($schedule));
    }

    #[Test]
    public function it_rejects_a_weekday_outside_the_iso_range(): void
    {
        $this->expectException(InvalidRecurrence::class);

        RecurrenceSchedule::make(RecurrenceFrequency::Weekly, '2026-03-02', byWeekday: [0]);
    }

    #[Test]
    public function it_rejects_a_day_of_the_month_outside_the_calendar(): void
    {
        $this->expectException(InvalidRecurrence::class);

        RecurrenceSchedule::make(RecurrenceFrequency::Monthly, '2026-03-02', byMonthday: [32]);
    }

    private function first(RecurrenceSchedule $schedule): ?string
    {
        return $this->calculator->first($schedule)?->toDateString();
    }

    private function after(RecurrenceSchedule $schedule, string $after): ?string
    {
        return $this->calculator->next($schedule, CarbonImmutable::parse($after))?->toDateString();
    }
}

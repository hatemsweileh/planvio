<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Workspace;
use App\Services\DateResolver;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The two things that matter about this class: it is right about *whose* today it is, and it
 * refuses to guess when a phrase has more than one honest reading.
 *
 * Every case pins an explicit reference instant. A date test that depends on the day it runs
 * is a test that fails on a Friday, six months from now, for reasons nobody remembers.
 */
final class DateResolverTest extends TestCase
{
    private DateResolver $resolver;

    /** Tuesday 8 September 2026, midday UTC. */
    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new DateResolver;
        $this->now = CarbonImmutable::create(2026, 9, 8, 12, 0, 0, 'UTC');
    }

    /* ------------------------------------------------------------------ *
     * Timezone correctness
     * ------------------------------------------------------------------ */

    public function test_today_is_the_callers_today_not_the_servers(): void
    {
        // 23:30 UTC is already tomorrow in Auckland and still this afternoon in Los Angeles.
        $lateUtc = CarbonImmutable::create(2026, 9, 8, 23, 30, 0, 'UTC');

        $this->assertSame(
            '2026-09-09',
            $this->resolver->toDateString('today', 'Pacific/Auckland', $lateUtc),
        );

        $this->assertSame(
            '2026-09-08',
            $this->resolver->toDateString('today', 'America/Los_Angeles', $lateUtc),
        );

        $this->assertSame(
            '2026-09-08',
            $this->resolver->toDateString('today', 'UTC', $lateUtc),
        );
    }

    public function test_a_past_midnight_utc_instant_is_still_yesterday_in_the_americas(): void
    {
        $justAfterMidnightUtc = CarbonImmutable::create(2026, 9, 9, 2, 0, 0, 'UTC');

        $this->assertSame(
            '2026-09-08',
            $this->resolver->toDateString('today', 'America/New_York', $justAfterMidnightUtc),
        );

        $this->assertSame(
            '2026-09-09',
            $this->resolver->toDateString('today', 'Europe/Berlin', $justAfterMidnightUtc),
        );
    }

    public function test_tomorrow_crosses_the_day_boundary_in_the_target_zone(): void
    {
        $lateUtc = CarbonImmutable::create(2026, 9, 8, 23, 30, 0, 'UTC');

        $this->assertSame(
            '2026-09-10',
            $this->resolver->toDateString('tomorrow', 'Pacific/Auckland', $lateUtc),
        );

        $this->assertSame(
            '2026-09-09',
            $this->resolver->toDateString('tomorrow', 'America/Los_Angeles', $lateUtc),
        );
    }

    public function test_the_result_is_midnight_in_the_requested_zone(): void
    {
        $resolved = $this->resolver->resolve('today', 'Asia/Tokyo', $this->now);

        $this->assertNotNull($resolved);
        $this->assertSame('Asia/Tokyo', $resolved->timezoneName);
        $this->assertSame('00:00:00', $resolved->format('H:i:s'));
        $this->assertSame('2026-09-08', $resolved->toDateString());
    }

    public function test_a_weekday_is_resolved_against_the_local_day_not_the_utc_one(): void
    {
        // 22:00 UTC on Thursday 10 September is already Friday 11 September in Auckland, so
        // "friday" must skip to the following week there while staying this week in London.
        $thursdayEvening = CarbonImmutable::create(2026, 9, 10, 22, 0, 0, 'UTC');

        $this->assertSame(
            '2026-09-11',
            $this->resolver->toDateString('friday', 'Europe/London', $thursdayEvening),
        );

        $this->assertSame(
            '2026-09-18',
            $this->resolver->toDateString('friday', 'Pacific/Auckland', $thursdayEvening),
        );
    }

    public function test_an_unknown_timezone_falls_back_to_utc_rather_than_throwing(): void
    {
        $resolved = $this->resolver->resolve('today', 'Mars/Olympus_Mons', $this->now);

        $this->assertNotNull($resolved);
        $this->assertSame('UTC', $resolved->timezoneName);
        $this->assertSame('2026-09-08', $resolved->toDateString());
    }

    /* ------------------------------------------------------------------ *
     * Ambiguity — the cases that must refuse
     * ------------------------------------------------------------------ */

    #[DataProvider('ambiguousInputs')]
    public function test_ambiguous_input_resolves_to_null(string $input): void
    {
        $this->assertNull(
            $this->resolver->resolve($input, 'UTC', $this->now),
            sprintf('[%s] has more than one honest reading and must not be guessed at.', $input),
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function ambiguousInputs(): array
    {
        return [
            // British English reads this as the Friday after the coming one; American English
            // reads it as the coming Friday. There is no majority to side with.
            'next friday' => ['next friday'],
            'next monday' => ['next monday'],
            // Has no answer at all once the day has already passed this week.
            'this friday' => ['this friday'],
            'coming tuesday' => ['coming tuesday'],
            // 3 April or 4 March, depending entirely on where the writer lives.
            'day/month/year vs month/day/year' => ['03/04/2026'],
            'dotted day/month vs month/day' => ['03.04.2026'],
            'dashed day/month vs month/day' => ['03-04-2026'],
            'day/month with no year' => ['3/4'],
            'ten/eleven' => ['10/11'],
            // A distance with no direction.
            'bare day count' => ['3 days'],
            'bare week count' => ['2 weeks'],
            // Spans and intentions, not days.
            'weekend' => ['weekend'],
            'next weekend' => ['next weekend'],
            'soon' => ['soon'],
            'asap' => ['asap'],
            'later' => ['later'],
            'bare month' => ['month'],
            // Not a date in any reading.
            'nonsense' => ['when the ci is green'],
            'empty' => [''],
            'whitespace' => ['   '],
            'impossible day' => ['2026-02-30'],
            'impossible month' => ['2026-13-01'],
            'thirty-first of april' => ['31 april 2026'],
        ];
    }

    public function test_a_numeric_date_with_only_one_valid_reading_is_not_ambiguous(): void
    {
        // There is no month 25, so this can only be 25 December.
        $this->assertSame('2026-12-25', $this->resolver->toDateString('25/12/2026', 'UTC', $this->now));
        $this->assertSame('2026-12-25', $this->resolver->toDateString('25.12.2026', 'UTC', $this->now));

        // Both readings land on the same day, so there is nothing to disagree about.
        $this->assertSame('2026-05-05', $this->resolver->toDateString('05/05/2026', 'UTC', $this->now));
    }

    /* ------------------------------------------------------------------ *
     * Weekdays
     * ------------------------------------------------------------------ */

    public function test_a_bare_weekday_is_the_next_one_strictly_after_today(): void
    {
        // Reference is Tuesday 8 September 2026.
        $this->assertSame('2026-09-11', $this->resolver->toDateString('friday', 'UTC', $this->now));
        $this->assertSame('2026-09-11', $this->resolver->toDateString('fri', 'UTC', $this->now));
        $this->assertSame('2026-09-09', $this->resolver->toDateString('wednesday', 'UTC', $this->now));
        $this->assertSame('2026-09-14', $this->resolver->toDateString('monday', 'UTC', $this->now));
    }

    public function test_a_bare_weekday_never_resolves_to_today(): void
    {
        $friday = CarbonImmutable::create(2026, 9, 11, 9, 0, 0, 'UTC');

        $this->assertSame('2026-09-18', $this->resolver->toDateString('friday', 'UTC', $friday));
    }

    public function test_last_weekday_is_the_most_recent_one_strictly_before_today(): void
    {
        $this->assertSame('2026-09-04', $this->resolver->toDateString('last friday', 'UTC', $this->now));
        $this->assertSame('2026-09-07', $this->resolver->toDateString('last monday', 'UTC', $this->now));

        $friday = CarbonImmutable::create(2026, 9, 11, 9, 0, 0, 'UTC');
        $this->assertSame('2026-09-04', $this->resolver->toDateString('last friday', 'UTC', $friday));
    }

    public function test_weekday_next_week_says_what_next_friday_cannot(): void
    {
        // Week starting Monday 14 September; its Friday is the 18th.
        $this->assertSame('2026-09-18', $this->resolver->toDateString('friday next week', 'UTC', $this->now));
        $this->assertSame('2026-09-14', $this->resolver->toDateString('monday next week', 'UTC', $this->now));
        $this->assertSame('2026-09-07', $this->resolver->toDateString('monday this week', 'UTC', $this->now));
        $this->assertSame('2026-08-31', $this->resolver->toDateString('monday last week', 'UTC', $this->now));
    }

    /* ------------------------------------------------------------------ *
     * Periods
     * ------------------------------------------------------------------ */

    public function test_next_week_follows_the_workspace_week_start(): void
    {
        // Monday start: this week began 7 September, so next week begins on the 14th.
        $this->assertSame(
            '2026-09-14',
            $this->resolver->toDateString('next week', 'UTC', $this->now, weekStartsOn: 1),
        );

        // Sunday start: this week began 6 September, so next week begins on the 13th.
        $this->assertSame(
            '2026-09-13',
            $this->resolver->toDateString('next week', 'UTC', $this->now, weekStartsOn: 0),
        );
    }

    public function test_week_boundaries_respect_the_week_start(): void
    {
        $this->assertSame('2026-09-07', $this->resolver->toDateString('this week', 'UTC', $this->now, 1));
        $this->assertSame('2026-09-13', $this->resolver->toDateString('end of week', 'UTC', $this->now, 1));
        $this->assertSame('2026-09-12', $this->resolver->toDateString('end of week', 'UTC', $this->now, 0));
        $this->assertSame('2026-08-31', $this->resolver->toDateString('last week', 'UTC', $this->now, 1));
    }

    public function test_month_quarter_and_year_boundaries(): void
    {
        $this->assertSame('2026-09-30', $this->resolver->toDateString('end of month', 'UTC', $this->now));
        $this->assertSame('2026-09-01', $this->resolver->toDateString('start of month', 'UTC', $this->now));
        $this->assertSame('2026-10-01', $this->resolver->toDateString('next month', 'UTC', $this->now));
        $this->assertSame('2026-10-31', $this->resolver->toDateString('end of next month', 'UTC', $this->now));
        $this->assertSame('2026-08-01', $this->resolver->toDateString('last month', 'UTC', $this->now));

        $this->assertSame('2026-07-01', $this->resolver->toDateString('this quarter', 'UTC', $this->now));
        $this->assertSame('2026-09-30', $this->resolver->toDateString('end of quarter', 'UTC', $this->now));
        $this->assertSame('2026-10-01', $this->resolver->toDateString('next quarter', 'UTC', $this->now));

        $this->assertSame('2026-01-01', $this->resolver->toDateString('this year', 'UTC', $this->now));
        $this->assertSame('2026-12-31', $this->resolver->toDateString('end of year', 'UTC', $this->now));
        $this->assertSame('2027-01-01', $this->resolver->toDateString('next year', 'UTC', $this->now));
    }

    public function test_end_of_month_is_correct_in_a_leap_february(): void
    {
        $february = CarbonImmutable::create(2028, 2, 10, 8, 0, 0, 'UTC');

        $this->assertSame('2028-02-29', $this->resolver->toDateString('end of month', 'UTC', $february));
    }

    public function test_end_of_a_named_month_takes_the_next_occurrence(): void
    {
        $this->assertSame('2026-09-30', $this->resolver->toDateString('end of september', 'UTC', $this->now));
        $this->assertSame('2026-10-31', $this->resolver->toDateString('end of october', 'UTC', $this->now));
        // March 2026 has already gone, so the next one is in 2027.
        $this->assertSame('2027-03-31', $this->resolver->toDateString('end of march', 'UTC', $this->now));
    }

    /* ------------------------------------------------------------------ *
     * Offsets
     * ------------------------------------------------------------------ */

    public function test_fixed_offsets(): void
    {
        $this->assertSame('2026-09-08', $this->resolver->toDateString('today', 'UTC', $this->now));
        $this->assertSame('2026-09-09', $this->resolver->toDateString('tomorrow', 'UTC', $this->now));
        $this->assertSame('2026-09-07', $this->resolver->toDateString('yesterday', 'UTC', $this->now));
        $this->assertSame('2026-09-10', $this->resolver->toDateString('day after tomorrow', 'UTC', $this->now));
        $this->assertSame('2026-09-08', $this->resolver->toDateString('end of day', 'UTC', $this->now));
    }

    public function test_directed_relative_offsets(): void
    {
        $this->assertSame('2026-09-11', $this->resolver->toDateString('in 3 days', 'UTC', $this->now));
        $this->assertSame('2026-09-22', $this->resolver->toDateString('2 weeks from now', 'UTC', $this->now));
        $this->assertSame('2026-10-08', $this->resolver->toDateString('+1 month', 'UTC', $this->now));
        $this->assertSame('2026-09-03', $this->resolver->toDateString('5 days ago', 'UTC', $this->now));
        $this->assertSame('2026-09-01', $this->resolver->toDateString('-1 week', 'UTC', $this->now));
        $this->assertSame('2027-09-08', $this->resolver->toDateString('in 1 year', 'UTC', $this->now));
    }

    public function test_month_arithmetic_does_not_overflow_a_short_month(): void
    {
        // A month after 31 January is 28 February, not 3 March.
        $endOfJanuary = CarbonImmutable::create(2026, 1, 31, 9, 0, 0, 'UTC');

        $this->assertSame('2026-02-28', $this->resolver->toDateString('in 1 month', 'UTC', $endOfJanuary));
    }

    public function test_business_days_skip_the_weekend(): void
    {
        // Thursday 10 September + 3 business days = Tuesday 15 September.
        $thursday = CarbonImmutable::create(2026, 9, 10, 9, 0, 0, 'UTC');

        $this->assertSame('2026-09-15', $this->resolver->toDateString('in 3 business days', 'UTC', $thursday));
        $this->assertSame('2026-09-11', $this->resolver->toDateString('in 1 working day', 'UTC', $thursday));
    }

    /* ------------------------------------------------------------------ *
     * Explicit dates
     * ------------------------------------------------------------------ */

    public function test_iso_and_year_first_dates_are_unambiguous(): void
    {
        $this->assertSame('2026-09-30', $this->resolver->toDateString('2026-09-30', 'UTC', $this->now));
        $this->assertSame('2026-09-30', $this->resolver->toDateString('2026/09/30', 'UTC', $this->now));
        $this->assertSame('2026-09-03', $this->resolver->toDateString('2026-9-3', 'UTC', $this->now));
    }

    public function test_textual_dates_resolve_in_either_order(): void
    {
        $this->assertSame('2026-09-30', $this->resolver->toDateString('30 September 2026', 'UTC', $this->now));
        $this->assertSame('2026-09-30', $this->resolver->toDateString('September 30, 2026', 'UTC', $this->now));
        $this->assertSame('2026-09-30', $this->resolver->toDateString('30th of September 2026', 'UTC', $this->now));
        $this->assertSame('2026-09-30', $this->resolver->toDateString('Sep 30th 2026', 'UTC', $this->now));
    }

    public function test_a_textual_date_without_a_year_takes_the_next_occurrence(): void
    {
        $this->assertSame('2026-09-30', $this->resolver->toDateString('sep 30', 'UTC', $this->now));
        // 1 March 2026 has passed, so the next one is in 2027.
        $this->assertSame('2027-03-01', $this->resolver->toDateString('1 march', 'UTC', $this->now));
        // 29 February only exists in leap years; the next is in 2028.
        $this->assertSame('2028-02-29', $this->resolver->toDateString('29 february', 'UTC', $this->now));
    }

    public function test_leading_prepositions_and_stray_punctuation_are_ignored(): void
    {
        $this->assertSame('2026-09-11', $this->resolver->toDateString('by friday', 'UTC', $this->now));
        $this->assertSame('2026-09-11', $this->resolver->toDateString('  ON  Friday.  ', 'UTC', $this->now));
        $this->assertSame('2026-09-30', $this->resolver->toDateString('due 2026-09-30', 'UTC', $this->now));
    }

    /* ------------------------------------------------------------------ *
     * Workspace and user context
     * ------------------------------------------------------------------ */

    public function test_for_workspace_uses_the_workspace_timezone_and_week_start(): void
    {
        $lateUtc = CarbonImmutable::create(2026, 9, 8, 23, 30, 0, 'UTC');

        $auckland = new Workspace(['timezone' => 'Pacific/Auckland', 'week_starts_on' => 1]);

        $this->assertSame(
            '2026-09-09',
            $this->resolver->forWorkspace($auckland, 'today', $lateUtc)?->toDateString(),
        );

        $sundayStart = new Workspace(['timezone' => 'UTC', 'week_starts_on' => 0]);
        $mondayStart = new Workspace(['timezone' => 'UTC', 'week_starts_on' => 1]);

        $this->assertSame(
            '2026-09-13',
            $this->resolver->forWorkspace($sundayStart, 'next week', $this->now)?->toDateString(),
        );

        $this->assertSame(
            '2026-09-14',
            $this->resolver->forWorkspace($mondayStart, 'next week', $this->now)?->toDateString(),
        );
    }

    public function test_resolution_is_case_insensitive(): void
    {
        $this->assertSame('2026-09-11', $this->resolver->toDateString('FRIDAY', 'UTC', $this->now));
        $this->assertSame('2026-09-30', $this->resolver->toDateString('END OF MONTH', 'UTC', $this->now));
        $this->assertSame('2026-09-09', $this->resolver->toDateString('Tomorrow', 'UTC', $this->now));
    }

    public function test_absurdly_long_input_is_refused_rather_than_scanned(): void
    {
        $this->assertNull($this->resolver->resolve(str_repeat('friday ', 40), 'UTC', $this->now));
    }
}

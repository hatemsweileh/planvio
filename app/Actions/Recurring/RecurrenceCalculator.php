<?php

declare(strict_types=1);

namespace App\Actions\Recurring;

use App\Enums\RecurrenceFrequency;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Works out when a rule fires next.
 *
 * Two properties are non-negotiable, because this runs unattended from cron:
 *
 *   - **It always terminates.** Every branch is a bounded loop. A rule with a nonsensical
 *     combination of filters — "the 31st of every February" — returns null instead of
 *     searching forever.
 *   - **It never drifts.** Occurrences are computed from `starts_on` by whole periods, not
 *     by repeatedly adding to the previous result. Adding a month to the 31st gives the
 *     30th, and adding another gives the 30th again; anchoring every occurrence to the
 *     original keeps the 31st.
 *
 * The frequencies read as follows:
 *
 *   - `daily`   — every `interval` days from `starts_on`.
 *   - `weekly`  — in every `interval`-th week from the week of `starts_on`, on the weekdays
 *                 in `by_weekday` (defaulting to the weekday `starts_on` falls on).
 *   - `monthly` — in every `interval`-th month, on the days in `by_monthday` (defaulting to
 *                 the day of the month `starts_on` falls on), clamped to the length of the
 *                 month so the 31st becomes the 30th in April rather than being skipped.
 *   - `yearly`  — every `interval`-th year on the month and day of `starts_on`, with
 *                 29 February clamped to the 28th in a common year.
 *   - `custom`  — the daily walk, further filtered by `by_weekday` and `by_monthday` when
 *                 either is set. This is the escape hatch for "every other Monday and
 *                 Thursday" and similar, and the only shape that can genuinely never match.
 */
final class RecurrenceCalculator
{
    /**
     * The most periods a single search will consider. Two thousand periods is a century of
     * fortnights; past that the schedule is not one anybody meant to write.
     */
    private const MAX_STEPS = 2000;

    /**
     * The first occurrence on or after `starts_on`, or null when the rule never fires.
     */
    public function first(RecurrenceSchedule $schedule): ?CarbonImmutable
    {
        return $this->next($schedule, $schedule->startsOn->subDay());
    }

    /**
     * The first occurrence strictly after $after, or null when there is none.
     */
    public function next(RecurrenceSchedule $schedule, CarbonImmutable $after): ?CarbonImmutable
    {
        $after = RecurrenceSchedule::normalise($after);
        $interval = max(1, $schedule->interval);

        $candidate = match ($schedule->frequency) {
            RecurrenceFrequency::Daily => $this->nextDaily($schedule, $after, $interval),
            RecurrenceFrequency::Weekly => $this->nextWeekly($schedule, $after, $interval),
            RecurrenceFrequency::Monthly => $this->nextMonthly($schedule, $after, $interval),
            RecurrenceFrequency::Yearly => $this->nextYearly($schedule, $after, $interval),
            RecurrenceFrequency::Custom => $this->nextCustom($schedule, $after, $interval),
        };

        if ($candidate === null) {
            return null;
        }

        if ($schedule->endsOn !== null && $candidate->greaterThan($schedule->endsOn)) {
            return null;
        }

        return $candidate;
    }

    private function nextDaily(RecurrenceSchedule $schedule, CarbonImmutable $after, int $interval): ?CarbonImmutable
    {
        $start = $schedule->startsOn;

        if ($after->lessThan($start)) {
            return $start;
        }

        // Jump straight to the period after $after instead of stepping day by day: a rule
        // that started ten years ago is one division away, not 3650 iterations.
        $periods = intdiv($this->daysBetween($start, $after), $interval) + 1;

        return $start->addDays($periods * $interval);
    }

    private function nextWeekly(RecurrenceSchedule $schedule, CarbonImmutable $after, int $interval): ?CarbonImmutable
    {
        $start = $schedule->startsOn;
        $weekdays = $schedule->byWeekday === [] ? [$start->dayOfWeekIso] : $schedule->byWeekday;
        $anchor = $start->startOfWeek(CarbonInterface::MONDAY);

        $weeksElapsed = intdiv(
            max(0, $this->daysBetween($anchor, $after->startOfWeek(CarbonInterface::MONDAY))),
            7,
        );

        $step = max(0, intdiv($weeksElapsed, $interval) - 1);

        while ($step < self::MAX_STEPS) {
            $weekStart = $anchor->addWeeks($step * $interval);

            foreach ($weekdays as $weekday) {
                $candidate = $weekStart->addDays($weekday - 1);

                if ($candidate->greaterThan($after) && ! $candidate->lessThan($start)) {
                    return $candidate;
                }
            }

            $step++;
        }

        return null;
    }

    private function nextMonthly(RecurrenceSchedule $schedule, CarbonImmutable $after, int $interval): ?CarbonImmutable
    {
        $start = $schedule->startsOn;
        $monthdays = $schedule->byMonthday === [] ? [$start->day] : $schedule->byMonthday;
        $anchor = $start->startOfMonth();

        $monthsElapsed = ($after->year - $anchor->year) * 12 + ($after->month - $anchor->month);
        $step = max(0, intdiv(max(0, $monthsElapsed), $interval) - 1);

        while ($step < self::MAX_STEPS) {
            $month = $anchor->addMonths($step * $interval);
            $lastDay = $month->daysInMonth;
            $days = [];

            foreach ($monthdays as $day) {
                // The 31st of a 30-day month lands on the 30th rather than vanishing, and
                // two requested days that clamp onto the same date only fire once.
                $clamped = min($day, $lastDay);

                if (! in_array($clamped, $days, true)) {
                    $days[] = $clamped;
                }
            }

            sort($days);

            foreach ($days as $day) {
                $candidate = $month->setDay($day);

                if ($candidate->greaterThan($after) && ! $candidate->lessThan($start)) {
                    return $candidate;
                }
            }

            $step++;
        }

        return null;
    }

    private function nextYearly(RecurrenceSchedule $schedule, CarbonImmutable $after, int $interval): ?CarbonImmutable
    {
        $start = $schedule->startsOn;
        $step = max(0, intdiv(max(0, $after->year - $start->year), $interval) - 1);

        while ($step < self::MAX_STEPS) {
            $year = $start->year + ($step * $interval);
            $firstOfMonth = CarbonImmutable::create($year, $start->month, 1, 0, 0, 0, 'UTC');

            if ($firstOfMonth instanceof CarbonImmutable) {
                $candidate = $firstOfMonth->setDay(min($start->day, $firstOfMonth->daysInMonth));

                if ($candidate->greaterThan($after) && ! $candidate->lessThan($start)) {
                    return $candidate;
                }
            }

            $step++;
        }

        return null;
    }

    private function nextCustom(RecurrenceSchedule $schedule, CarbonImmutable $after, int $interval): ?CarbonImmutable
    {
        $start = $schedule->startsOn;

        $step = $after->lessThan($start)
            ? 0
            : intdiv($this->daysBetween($start, $after), $interval) + 1;

        $limit = $step + self::MAX_STEPS;

        while ($step < $limit) {
            $candidate = $start->addDays($step * $interval);

            if ($candidate->greaterThan($after) && $this->matchesFilters($schedule, $candidate)) {
                return $candidate;
            }

            if ($schedule->endsOn !== null && $candidate->greaterThan($schedule->endsOn)) {
                return null;
            }

            $step++;
        }

        return null;
    }

    private function matchesFilters(RecurrenceSchedule $schedule, CarbonImmutable $candidate): bool
    {
        if ($schedule->byWeekday !== [] && ! in_array($candidate->dayOfWeekIso, $schedule->byWeekday, true)) {
            return false;
        }

        return $schedule->byMonthday === [] || in_array($candidate->day, $schedule->byMonthday, true);
    }

    /**
     * Whole days from $from to $to, positive when $to is later.
     *
     * Both sides are midnight UTC by construction, so this is exact — no daylight saving
     * hour to round away, no fractional day to truncate in the wrong direction.
     */
    private function daysBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) round($from->diffInDays($to, false));
    }
}

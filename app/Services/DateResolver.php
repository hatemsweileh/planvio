<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Turns the way people write dates into a date, in a stated timezone — or refuses.
 *
 * This backs the AI's date handling, which is why refusing matters more than covering.
 * An agent that sets a due date from "next Friday" has to be right: British and American
 * English disagree about whether that means the Friday of this week or the one after, and
 * `03/04/2026` is 3 April to most of the world and 4 March in the United States. Guessing
 * produces a deadline someone will miss. This class returns null for those, so the caller
 * can ask instead of inventing.
 *
 * Conventions it *does* commit to, all deterministic and all tested:
 *
 *   `friday`              the next Friday strictly after today — never today
 *   `last friday`         the most recent Friday strictly before today
 *   `friday next week`    the Friday inside next week (the unambiguous way to say it)
 *   `next week`           the first day of next week, per `workspaces.week_starts_on`
 *   `next month`/`year`   the first day of that period
 *   `end of month`        the last day of the current month
 *   `sep 30`              the next 30 September on or after today
 *   `25/12/2026`          25 December — only one reading is a real date
 *
 * Everything is resolved arithmetically from a reference instant. Nothing is handed to
 * `strtotime()` or `Carbon::parse()`, because both guess: `strtotime('friday')` returns
 * *today* on a Friday, and `Carbon::parse('03/04/2026')` silently picks a locale's order.
 *
 * The reference instant is `$now` (default: real now) converted into `$timezone`, so "today"
 * is the caller's today. A user in Auckland asking at 09:00 local on the 9th must not get
 * the 8th because the server runs on UTC.
 */
final class DateResolver
{
    /** Monday, matching the `workspaces.week_starts_on` default. */
    public const DEFAULT_WEEK_START = 1;

    private const MAX_INPUT_LENGTH = 64;

    /** Offsets in days for the phrases that need no arithmetic. */
    private const FIXED_OFFSETS = [
        'today' => 0,
        'tod' => 0,
        'now' => 0,
        'eod' => 0,
        'end of day' => 0,
        'cob' => 0,
        'close of business' => 0,
        'tomorrow' => 1,
        'tmr' => 1,
        'tmrw' => 1,
        'tom' => 1,
        'yesterday' => -1,
        'yest' => -1,
        'day after tomorrow' => 2,
        'the day after tomorrow' => 2,
        'day before yesterday' => -2,
        'the day before yesterday' => -2,
    ];

    /**
     * Phrases that name a span or an intention rather than a day. Listed rather than left to
     * fall through, so the refusal is deliberate and testable.
     */
    private const AMBIGUOUS = [
        'weekend', 'this weekend', 'next weekend', 'last weekend',
        'soon', 'asap', 'later', 'sometime', 'eventually', 'tbd', 'tba',
        'midweek', 'mid week', 'mid month', 'month', 'week', 'year', 'quarter',
    ];

    /** Carbon day-of-week numbers: 0 = Sunday … 6 = Saturday. */
    private const WEEKDAYS = [
        'sunday' => 0, 'sun' => 0,
        'monday' => 1, 'mon' => 1,
        'tuesday' => 2, 'tue' => 2, 'tues' => 2,
        'wednesday' => 3, 'wed' => 3, 'weds' => 3,
        'thursday' => 4, 'thu' => 4, 'thur' => 4, 'thurs' => 4,
        'friday' => 5, 'fri' => 5,
        'saturday' => 6, 'sat' => 6,
    ];

    private const MONTHS = [
        'january' => 1, 'jan' => 1,
        'february' => 2, 'feb' => 2,
        'march' => 3, 'mar' => 3,
        'april' => 4, 'apr' => 4,
        'may' => 5,
        'june' => 6, 'jun' => 6,
        'july' => 7, 'jul' => 7,
        'august' => 8, 'aug' => 8,
        'september' => 9, 'sep' => 9, 'sept' => 9,
        'october' => 10, 'oct' => 10,
        'november' => 11, 'nov' => 11,
        'december' => 12, 'dec' => 12,
    ];

    private const UNIT_PATTERN = 'days?|d|weeks?|w|months?|mos?|years?|yrs?|y'
        .'|business days?|working days?|weekdays?';

    /**
     * @param int $weekStartsOn 0 = Sunday … 6 = Saturday
     * @return CarbonImmutable|null midnight on the resolved day, in $timezone
     */
    public function resolve(
        string $input,
        string $timezone = 'UTC',
        ?DateTimeInterface $now = null,
        int $weekStartsOn = self::DEFAULT_WEEK_START,
    ): ?CarbonImmutable {
        $term = $this->normalise($input);

        if ($term === null) {
            return null;
        }

        $zone = self::timezone($timezone);
        $weekStartsOn = ($weekStartsOn % 7 + 7) % 7;

        $today = ($now === null
            ? CarbonImmutable::now($zone)
            : CarbonImmutable::instance($now)->setTimezone($zone))->startOfDay();

        if (in_array($term, self::AMBIGUOUS, true)) {
            return null;
        }

        return $this->fixedOffset($term, $today)
            ?? $this->relativeOffset($term, $today)
            ?? $this->periodBoundary($term, $today, $weekStartsOn)
            ?? $this->namedMonthBoundary($term, $today)
            ?? $this->weekday($term, $today, $weekStartsOn)
            ?? $this->isoDate($term, $zone)
            ?? $this->numericDate($term, $today, $zone)
            ?? $this->textualDate($term, $today, $zone);
    }

    /**
     * The same resolution as a `Y-m-d` string, which is what every date column stores.
     */
    public function toDateString(
        string $input,
        string $timezone = 'UTC',
        ?DateTimeInterface $now = null,
        int $weekStartsOn = self::DEFAULT_WEEK_START,
    ): ?string {
        return $this->resolve($input, $timezone, $now, $weekStartsOn)?->toDateString();
    }

    /**
     * Resolve in the workspace's own timezone and week start — what an agent acting inside a
     * workspace should always use.
     */
    public function forWorkspace(
        Workspace $workspace,
        string $input,
        ?DateTimeInterface $now = null,
    ): ?CarbonImmutable {
        return $this->resolve(
            $input,
            (string) ($workspace->timezone ?? 'UTC'),
            $now,
            (int) ($workspace->week_starts_on ?? self::DEFAULT_WEEK_START),
        );
    }

    /**
     * Resolve in a person's own timezone. "Tomorrow" is their tomorrow, not the server's.
     */
    public function forUser(
        User $user,
        string $input,
        ?DateTimeInterface $now = null,
        int $weekStartsOn = self::DEFAULT_WEEK_START,
    ): ?CarbonImmutable {
        return $this->resolve($input, (string) ($user->timezone ?? 'UTC'), $now, $weekStartsOn);
    }

    /* ------------------------------------------------------------------ *
     * Matchers, tried in order
     * ------------------------------------------------------------------ */

    private function fixedOffset(string $term, CarbonImmutable $today): ?CarbonImmutable
    {
        $offset = self::FIXED_OFFSETS[$term] ?? null;

        return $offset === null ? null : $today->addDays($offset);
    }

    /**
     * "in 3 days", "3 weeks from now", "+2mo", "5 days ago", "-1 week".
     *
     * A bare "3 days" is refused: it names a distance without a direction, and reading it as
     * the future is exactly the kind of guess this class exists not to make.
     */
    private function relativeOffset(string $term, CarbonImmutable $today): ?CarbonImmutable
    {
        $unit = self::UNIT_PATTERN;

        if (preg_match('/^in (\d{1,4}) ('.$unit.')$/', $term, $m) === 1
            || preg_match('/^(\d{1,4}) ('.$unit.') (?:from (?:now|today)|ahead)$/', $term, $m) === 1
            || preg_match('/^\+ ?(\d{1,4}) ?('.$unit.')$/', $term, $m) === 1) {
            return $this->shift($today, (int) $m[1], $m[2]);
        }

        if (preg_match('/^(\d{1,4}) ('.$unit.') ago$/', $term, $m) === 1
            || preg_match('/^- ?(\d{1,4}) ?('.$unit.')$/', $term, $m) === 1) {
            return $this->shift($today, -(int) $m[1], $m[2]);
        }

        return null;
    }

    private function shift(CarbonImmutable $today, int $amount, string $unit): ?CarbonImmutable
    {
        return match (true) {
            in_array($unit, ['day', 'days', 'd'], true) => $today->addDays($amount),
            in_array($unit, ['week', 'weeks', 'w'], true) => $today->addWeeks($amount),
            // No-overflow arithmetic: a month after 31 January is 28 February, not 3 March.
            in_array($unit, ['month', 'months', 'mo', 'mos'], true) => $today->addMonthsNoOverflow($amount),
            in_array($unit, ['year', 'years', 'yr', 'yrs', 'y'], true) => $today->addYearsNoOverflow($amount),
            in_array($unit, ['business day', 'business days', 'working day', 'working days', 'weekday', 'weekdays'], true) => $this->addBusinessDays($today, $amount),
            default => null,
        };
    }

    /**
     * Saturday and Sunday are skipped. The weekend is not configurable in v1 — nothing in the
     * schema records which days a workspace works — so this is the Western-week assumption,
     * stated rather than hidden.
     */
    private function addBusinessDays(CarbonImmutable $today, int $amount): CarbonImmutable
    {
        $step = $amount >= 0 ? 1 : -1;
        $remaining = abs($amount);
        $date = $today;

        while ($remaining > 0) {
            $date = $date->addDays($step);

            if ($date->dayOfWeek !== 0 && $date->dayOfWeek !== 6) {
                $remaining--;
            }
        }

        return $date;
    }

    /**
     * "this/next/last week|month|quarter|year", and the start/end of any of them.
     */
    private function periodBoundary(string $term, CarbonImmutable $today, int $weekStartsOn): ?CarbonImmutable
    {
        $period = '(week|month|quarter|year)';
        $when = '(?:(this|next|last|current|coming) )?';

        if (preg_match('/^(?:start|beginning) of '.$when.$period.'$/', $term, $m) === 1) {
            return $this->periodStart($today, $m[2], $m[1], $weekStartsOn);
        }

        if (preg_match('/^end of '.$when.$period.'$/', $term, $m) === 1) {
            return $this->periodEnd($today, $m[2], $m[1], $weekStartsOn);
        }

        // A bare "next month" names a span; taking its first day is the convention, and it is
        // the one every scheduling tool uses.
        if (preg_match('/^(this|next|last|current|coming) '.$period.'$/', $term, $m) === 1) {
            return $this->periodStart($today, $m[2], $m[1], $weekStartsOn);
        }

        return null;
    }

    private function periodStart(
        CarbonImmutable $today,
        string $period,
        string $when,
        int $weekStartsOn,
    ): ?CarbonImmutable {
        $offset = self::periodOffset($when);

        return match ($period) {
            'week' => $today->startOfWeek($weekStartsOn)->addWeeks($offset),
            'month' => $today->startOfMonth()->addMonthsNoOverflow($offset),
            'quarter' => $today->startOfQuarter()->addMonthsNoOverflow($offset * 3),
            'year' => $today->startOfYear()->addYearsNoOverflow($offset),
            default => null,
        };
    }

    private function periodEnd(
        CarbonImmutable $today,
        string $period,
        string $when,
        int $weekStartsOn,
    ): ?CarbonImmutable {
        $start = $this->periodStart($today, $period, $when, $weekStartsOn);

        if ($start === null) {
            return null;
        }

        return match ($period) {
            // The week's last day follows the workspace's week start, not Carbon's default.
            'week' => $start->addDays(6),
            'month' => $start->endOfMonth()->startOfDay(),
            'quarter' => $start->addMonthsNoOverflow(2)->endOfMonth()->startOfDay(),
            'year' => $start->endOfYear()->startOfDay(),
            default => null,
        };
    }

    private static function periodOffset(string $when): int
    {
        return match ($when) {
            'next', 'coming' => 1,
            'last' => -1,
            default => 0,
        };
    }

    /**
     * "end of september", "start of march" — the next occurrence of that month.
     */
    private function namedMonthBoundary(string $term, CarbonImmutable $today): ?CarbonImmutable
    {
        if (preg_match('/^(end|start|beginning) of ([a-z]+)$/', $term, $m) !== 1) {
            return null;
        }

        $month = self::MONTHS[$m[2]] ?? null;

        if ($month === null) {
            return null;
        }

        $candidate = $today->setDate($today->year, $month, 1)->startOfDay();
        $boundary = $m[1] === 'end'
            ? $candidate->endOfMonth()->startOfDay()
            : $candidate;

        return $boundary->lessThan($today)
            ? ($m[1] === 'end'
                ? $candidate->addYearsNoOverflow(1)->endOfMonth()->startOfDay()
                : $candidate->addYearsNoOverflow(1))
            : $boundary;
    }

    /**
     * Weekday names.
     *
     * A bare weekday is the next one strictly after today. "next friday" and "this friday"
     * are refused: the first means different weeks to different English speakers, and the
     * second has no answer once the day has already passed this week. "friday next week"
     * says the thing "next friday" is reaching for, and is honoured.
     */
    private function weekday(string $term, CarbonImmutable $today, int $weekStartsOn): ?CarbonImmutable
    {
        $names = implode('|', array_keys(self::WEEKDAYS));

        if (preg_match('/^('.$names.')$/', $term, $m) === 1) {
            return $this->nextWeekday($today, self::WEEKDAYS[$m[1]]);
        }

        if (preg_match('/^last ('.$names.')$/', $term, $m) === 1) {
            return $this->previousWeekday($today, self::WEEKDAYS[$m[1]]);
        }

        if (preg_match('/^('.$names.') (next|this|last) week$/', $term, $m) === 1) {
            return $this->weekdayInWeek(
                $today,
                self::WEEKDAYS[$m[1]],
                $weekStartsOn,
                self::periodOffset($m[2]),
            );
        }

        if (preg_match('/^(next|this|coming|last) ('.$names.')$/', $term) === 1) {
            // Deliberately unresolved. See the method note.
            return null;
        }

        return null;
    }

    private function nextWeekday(CarbonImmutable $today, int $weekday): CarbonImmutable
    {
        $delta = ($weekday - $today->dayOfWeek + 7) % 7;

        return $today->addDays($delta === 0 ? 7 : $delta);
    }

    private function previousWeekday(CarbonImmutable $today, int $weekday): CarbonImmutable
    {
        $delta = ($today->dayOfWeek - $weekday + 7) % 7;

        return $today->subDays($delta === 0 ? 7 : $delta);
    }

    private function weekdayInWeek(
        CarbonImmutable $today,
        int $weekday,
        int $weekStartsOn,
        int $weekOffset,
    ): CarbonImmutable {
        $start = $today->startOfWeek($weekStartsOn)->addWeeks($weekOffset);

        return $start->addDays(($weekday - $start->dayOfWeek + 7) % 7);
    }

    /**
     * ISO `Y-m-d`, and `Y/m/d` — a four-digit year first leaves nothing to interpret.
     */
    private function isoDate(string $term, DateTimeZone $zone): ?CarbonImmutable
    {
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $term, $m) !== 1) {
            return null;
        }

        return self::makeDate((int) $m[1], (int) $m[2], (int) $m[3], $zone);
    }

    /**
     * All-numeric day/month forms.
     *
     * Both readings are built. If both are real dates and they differ, the input is genuinely
     * ambiguous — `03/04/2026` is two different days depending on where the writer lives —
     * and null is returned. If only one reading is a date, that one is unambiguous and is
     * used: `25/12/2026` has no month 25.
     */
    private function numericDate(string $term, CarbonImmutable $today, DateTimeZone $zone): ?CarbonImmutable
    {
        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $term, $m) === 1) {
            $year = (int) $m[3];

            return self::disambiguate(
                self::makeDate($year, (int) $m[2], (int) $m[1], $zone),
                self::makeDate($year, (int) $m[1], (int) $m[2], $zone),
            );
        }

        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})$/', $term, $m) === 1) {
            return self::disambiguate(
                self::upcoming((int) $m[2], (int) $m[1], $today, $zone),
                self::upcoming((int) $m[1], (int) $m[2], $today, $zone),
            );
        }

        return null;
    }

    /**
     * "30 September 2026", "Sep 30", "September 30th, 2026".
     *
     * A month name removes the ordering question entirely, so these always resolve. Without a
     * year, the next occurrence on or after today is used.
     */
    private function textualDate(string $term, CarbonImmutable $today, DateTimeZone $zone): ?CarbonImmutable
    {
        $names = implode('|', array_keys(self::MONTHS));
        $day = '(\d{1,2})(?:st|nd|rd|th)?';

        if (preg_match('/^'.$day.' (?:of )?('.$names.')(?:,? (\d{4}))?$/', $term, $m) === 1) {
            return $this->fromParts((int) $m[1], self::MONTHS[$m[2]], $m[3] ?? null, $today, $zone);
        }

        if (preg_match('/^('.$names.') '.$day.'(?:,? (\d{4}))?$/', $term, $m) === 1) {
            return $this->fromParts((int) $m[2], self::MONTHS[$m[1]], $m[3] ?? null, $today, $zone);
        }

        return null;
    }

    private function fromParts(
        int $day,
        int $month,
        ?string $year,
        CarbonImmutable $today,
        DateTimeZone $zone,
    ): ?CarbonImmutable {
        if ($year !== null && $year !== '') {
            return self::makeDate((int) $year, $month, $day, $zone);
        }

        return self::upcoming($month, $day, $today, $zone);
    }

    /* ------------------------------------------------------------------ *
     * Building and validating
     * ------------------------------------------------------------------ */

    /**
     * The next month/day on or after today. Returns null when the pair is not a real date in
     * either candidate year — 29 February resolves to the next leap year rather than nothing.
     */
    private static function upcoming(int $month, int $day, CarbonImmutable $today, DateTimeZone $zone): ?CarbonImmutable
    {
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        for ($year = $today->year; $year <= $today->year + 8; $year++) {
            $candidate = self::makeDate($year, $month, $day, $zone);

            if ($candidate !== null && $candidate->greaterThanOrEqualTo($today)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Two readings of the same input: null when both are valid and disagree, otherwise
     * whichever one is real.
     */
    private static function disambiguate(?CarbonImmutable $first, ?CarbonImmutable $second): ?CarbonImmutable
    {
        if ($first !== null && $second !== null) {
            return $first->equalTo($second) ? $first : null;
        }

        return $first ?? $second;
    }

    private static function makeDate(int $year, int $month, int $day, DateTimeZone $zone): ?CarbonImmutable
    {
        if ($year < 1 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        // checkdate rejects 31 April and 29 February in a common year, which is the whole
        // point: Carbon would happily roll both forward into the next month.
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        $date = CarbonImmutable::create($year, $month, $day, 0, 0, 0, $zone);

        return $date instanceof CarbonImmutable ? $date->startOfDay() : null;
    }

    private function normalise(string $input): ?string
    {
        $term = mb_strtolower(trim($input));
        $term = (string) preg_replace('/\s+/u', ' ', $term);
        $term = trim($term, " \t\n\r\0\x0B,;:!?");
        $term = (string) preg_replace('/[.]+$/', '', $term);
        $term = trim($term);

        if ($term === '' || mb_strlen($term) > self::MAX_INPUT_LENGTH) {
            return null;
        }

        // "on friday", "by 30 september", "due next week" — leading prepositions carry no
        // information, and stripping them keeps the matcher list from doubling.
        $term = (string) preg_replace('/^(?:on|by|due|before|until|till|at) /', '', $term);
        $term = (string) preg_replace('/^the /', '', $term);

        return $term === '' ? null : $term;
    }

    private static function timezone(string $timezone): DateTimeZone
    {
        $zone = @timezone_open($timezone);

        // An unknown identifier is a caller bug, not a user's; falling back to UTC keeps the
        // resolver total, and the value it returns is still explicit about its zone.
        return $zone === false ? new DateTimeZone('UTC') : $zone;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\Priority;
use App\Models\Milestone;
use App\Models\Tag;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DateResolver;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Turns one row of a CSV into a {@see ResolvedRow}.
 *
 * ## Dates
 *
 * Everything goes through {@see DateResolver} in the *workspace's* timezone, so "tomorrow"
 * in a file uploaded at 23:00 in Auckland is the Auckland tomorrow and not the server's
 * today. That resolver deliberately refuses to guess, and this class turns its refusals
 * into two different outcomes:
 *
 *   - `03/04/2026` is 3 April to most of the world and 4 March in the United States. Both
 *     readings are real dates, so it is **ambiguous**: a warning, and the task is imported
 *     without that date. Picking one would put a deadline somebody will miss into the
 *     project, with no sign that it was invented.
 *   - `next thurs` or `soonish` is not a date at all. That is an **error** and the row is
 *     skipped, because a column of unreadable dates almost always means the mapping is
 *     wrong, and silently importing a hundred tasks with no deadlines hides that.
 *
 * ## Estimates
 *
 * A bare number is hours, which is how every project tool writes them and how everyone
 * reads "Estimate: 3". Units are honoured where they are given — `90m`, `1h 30m`, `1:30`,
 * `2.5h` — and the parsed value is echoed back in the preview as "1h 30m" so a
 * misinterpretation is visible before anything is written.
 *
 * ## Everything else
 *
 * Names are matched exactly, ignoring case, against {@see ImportCatalogue}. A miss is a
 * warning and the field is left empty. That is the promise the wizard makes: an unknown
 * assignee imports unassigned rather than failing the file.
 */
final readonly class RowResolver
{
    /** `tasks.title` is a varchar(255); the message is friendlier than a truncated task. */
    private const MAX_TITLE = 255;

    /** Longest estimate accepted, in minutes: a thousand hours. */
    private const MAX_ESTIMATE_MINUTES = 60000;

    public function __construct(
        private DateResolver $dates,
        private Workspace $workspace,
        private ImportCatalogue $catalogue,
        private bool $createMissingTags = false,
        private ?DateTimeInterface $now = null,
    ) {}

    /**
     * @param list<string> $row
     */
    public function resolve(int $number, array $row, ColumnMap $map): ResolvedRow
    {
        $issues = [];

        $title = $this->title($number, $row, $map, $issues);
        $status = $this->status($number, $row, $map, $issues);
        $priority = $this->priority($number, $row, $map, $issues);
        $assignee = $this->assignee($number, $row, $map, $issues);
        $startDate = $this->date($number, $row, $map, TaskField::StartDate, $issues);
        $dueDate = $this->date($number, $row, $map, TaskField::DueDate, $issues);
        $milestone = $this->milestone($number, $row, $map, $issues);
        $estimate = $this->estimate($number, $row, $map, $issues);
        [$tags, $newTags] = $this->tags($number, $row, $map, $issues);

        // The same invariant CreateTask enforces, checked here so it is reported as a row
        // problem in the wizard rather than as an exception halfway through the run.
        if ($startDate !== null && $dueDate !== null && $dueDate->lessThan($startDate)) {
            $issues[] = RowIssue::error(
                $number,
                __('The due date is before the start date.'),
                TaskField::DueDate,
                $dueDate->toDateString(),
            );
        }

        return new ResolvedRow(
            row: $number,
            title: $title,
            description: $map->value($row, TaskField::Description),
            status: $status,
            priority: $priority,
            assignee: $assignee,
            startDate: $startDate,
            dueDate: $dueDate,
            tags: $tags,
            newTagNames: $newTags,
            milestone: $milestone,
            estimateMinutes: $estimate,
            issues: $issues,
        );
    }

    /* ------------------------------------------------------------------ *
     * Fields
     * ------------------------------------------------------------------ */

    /**
     * @param list<string> $row
     * @param list<RowIssue> $issues
     */
    private function title(int $number, array $row, ColumnMap $map, array &$issues): string
    {
        $title = $map->value($row, TaskField::Title);

        if ($title === null) {
            $issues[] = RowIssue::error($number, __('This row has no title, so there is no task to create.'), TaskField::Title);

            return '';
        }

        if (mb_strlen($title) > self::MAX_TITLE) {
            $issues[] = RowIssue::warning(
                $number,
                __('The title is longer than :max characters and was shortened.', ['max' => self::MAX_TITLE]),
                TaskField::Title,
                $title,
            );

            return mb_substr($title, 0, self::MAX_TITLE);
        }

        return $title;
    }

    /**
     * @param list<string> $row
     * @param list<RowIssue> $issues
     */
    private function status(int $number, array $row, ColumnMap $map, array &$issues): ?TaskStatus
    {
        $name = $map->value($row, TaskField::Status);

        if ($name === null) {
            return $this->catalogue->defaultStatus();
        }

        $status = $this->catalogue->status($name);

        if ($status !== null) {
            return $status;
        }

        $default = $this->catalogue->defaultStatus();

        $issues[] = RowIssue::warning(
            $number,
            $default === null
                ? __('No column on this board is called that.')
                : __('No column on this board is called that. Imported into :column.', ['column' => $default->name]),
            TaskField::Status,
            $name,
        );

        return $default;
    }

    /**
     * @param list<string> $row
     * @param list<RowIssue> $issues
     */
    private function priority(int $number, array $row, ColumnMap $map, array &$issues): Priority
    {
        $value = $map->value($row, TaskField::Priority);

        if ($value === null) {
            return Priority::Medium;
        }

        $priority = self::priorityFrom($value);

        if ($priority !== null) {
            return $priority;
        }

        $issues[] = RowIssue::warning(
            $number,
            __('Not a priority Planvio knows. Imported as medium.'),
            TaskField::Priority,
            $value,
        );

        return Priority::Medium;
    }

    /**
     * Values, labels and the words people actually type. `p1`..`p4` and `1`..`4` are
     * included because half the world's exports use them.
     */
    private static function priorityFrom(string $value): ?Priority
    {
        $key = mb_strtolower(trim($value));

        $direct = Priority::tryFrom($key);

        if ($direct !== null) {
            return $direct;
        }

        return match ($key) {
            'critical', 'blocker', 'highest', 'p0', 'p1', '4' => Priority::Urgent,
            'important', '3' => Priority::High,
            'normal', 'standard', 'p2', '2' => Priority::Medium,
            'minor', 'lowest', 'p3', 'p4', '1' => Priority::Low,
            'trivial', 'nice to have', 'none', 'no', '0', '-' => Priority::None,
            default => null,
        };
    }

    /**
     * @param list<string> $row
     * @param list<RowIssue> $issues
     */
    private function assignee(int $number, array $row, ColumnMap $map, array &$issues): ?User
    {
        $reference = $map->value($row, TaskField::Assignee);

        if ($reference === null) {
            return null;
        }

        $user = $this->catalogue->member($reference);

        if ($user !== null) {
            return $user;
        }

        $issues[] = RowIssue::warning(
            $number,
            $this->catalogue->isAmbiguousMember($reference)
                ? __('More than one member has that name, so Planvio will not pick one. Imported unassigned.')
                : __('Nobody in this workspace matches. Imported unassigned.'),
            TaskField::Assignee,
            $reference,
        );

        return null;
    }

    /**
     * @param list<string> $row
     * @param list<RowIssue> $issues
     */
    private function date(int $number, array $row, ColumnMap $map, TaskField $field, array &$issues): ?CarbonImmutable
    {
        $value = $map->value($row, $field);

        if ($value === null) {
            return null;
        }

        $date = $this->dates->resolve(
            $value,
            (string) ($this->workspace->timezone ?? 'UTC'),
            $this->now,
            (int) ($this->workspace->week_starts_on ?? DateResolver::DEFAULT_WEEK_START),
        );

        if ($date instanceof CarbonImmutable) {
            return $date;
        }

        if (self::looksAmbiguous($value)) {
            $issues[] = RowIssue::warning(
                $number,
                __('This could be a day or a month first, and Planvio will not guess. Imported without the date.'),
                $field,
                $value,
            );

            return null;
        }

        $issues[] = RowIssue::error(
            $number,
            __('Not a date Planvio can read. Try 2026-03-31.'),
            $field,
            $value,
        );

        return null;
    }

    /**
     * Whether a value the resolver refused was refused for being ambiguous rather than for
     * being unreadable.
     *
     * The two all-numeric shapes are the only genuinely ambiguous ones: `3/4/2026` and
     * `3/4`. Both readings must be real dates — `25/12/2026` has no month 25, so the
     * resolver would already have accepted it — which is why this only has to recognise the
     * shape, not re-do the arithmetic.
     */
    private static function looksAmbiguous(string $value): bool
    {
        $term = trim($value);

        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})(?:[-\/.](\d{4}))?$/', $term, $found) !== 1) {
            return false;
        }

        $first = (int) $found[1];
        $second = (int) $found[2];

        // Both halves have to be plausible months for the reading to be in doubt.
        return $first >= 1 && $first <= 12 && $second >= 1 && $second <= 12 && $first !== $second;
    }

    /**
     * @param list<string> $row
     * @param list<RowIssue> $issues
     */
    private function milestone(int $number, array $row, ColumnMap $map, array &$issues): ?Milestone
    {
        $name = $map->value($row, TaskField::Milestone);

        if ($name === null) {
            return null;
        }

        $milestone = $this->catalogue->milestone($name);

        if ($milestone !== null) {
            return $milestone;
        }

        $issues[] = RowIssue::warning(
            $number,
            __('This project has no milestone by that name. Imported without one.'),
            TaskField::Milestone,
            $name,
        );

        return null;
    }

    /**
     * @param list<string> $row
     * @param list<RowIssue> $issues
     */
    private function estimate(int $number, array $row, ColumnMap $map, array &$issues): ?int
    {
        $value = $map->value($row, TaskField::Estimate);

        if ($value === null) {
            return null;
        }

        $minutes = self::estimateMinutes($value);

        if ($minutes === null) {
            $issues[] = RowIssue::warning(
                $number,
                __('Not a length of time Planvio can read. Imported without an estimate.'),
                TaskField::Estimate,
                $value,
            );

            return null;
        }

        if ($minutes < 0) {
            $issues[] = RowIssue::error($number, __('An estimate cannot be negative.'), TaskField::Estimate, $value);

            return null;
        }

        if ($minutes > self::MAX_ESTIMATE_MINUTES) {
            $issues[] = RowIssue::warning(
                $number,
                __('That is more than a thousand hours. Imported without an estimate — it is almost always a unit mix-up.'),
                TaskField::Estimate,
                $value,
            );

            return null;
        }

        return $minutes;
    }

    /**
     * "2.5" and "2.5h" are two and a half hours; "90m" is ninety minutes; "1:30" is an hour
     * and a half; "1h 30m" is the same. A bare number is hours because that is the unit
     * every estimate column in every tool is written in.
     */
    public static function estimateMinutes(string $value): ?int
    {
        $term = mb_strtolower(trim($value));

        if ($term === '') {
            return null;
        }

        $negative = str_starts_with($term, '-');
        $term = ltrim($term, '+-');

        // 1:30
        if (preg_match('/^(\d{1,4}):([0-5]?\d)$/', $term, $found) === 1) {
            $minutes = ((int) $found[1] * 60) + (int) $found[2];

            return $negative ? -$minutes : $minutes;
        }

        $total = 0.0;
        $matched = false;

        // 1h 30m, 1h, 30m, 2d — days are eight hours, the working day this product assumes
        // everywhere it turns an estimate into a span.
        if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*(d|days?|h|hrs?|hours?|m|mins?|minutes?)\b/', $term, $parts, PREG_SET_ORDER) > 0) {
            foreach ($parts as $part) {
                $amount = (float) str_replace(',', '.', $part[1]);

                $total += match (true) {
                    str_starts_with($part[2], 'd') => $amount * 8 * 60,
                    str_starts_with($part[2], 'h') => $amount * 60,
                    default => $amount,
                };

                $matched = true;
            }
        }

        if (! $matched) {
            // A bare number: hours.
            if (preg_match('/^(\d+(?:[.,]\d+)?)$/', $term, $found) !== 1) {
                return null;
            }

            $total = (float) str_replace(',', '.', $found[1]) * 60;
        }

        $minutes = (int) round($total);

        return $negative ? -$minutes : $minutes;
    }

    /**
     * @param list<string> $row
     * @param list<RowIssue> $issues
     * @return array{0: list<Tag>, 1: list<string>}
     */
    private function tags(int $number, array $row, ColumnMap $map, array &$issues): array
    {
        $value = $map->value($row, TaskField::Tags);

        if ($value === null) {
            return [[], []];
        }

        $names = array_values(array_filter(
            array_map(trim(...), preg_split('/[,;|]/', $value) ?: []),
            static fn (string $name): bool => $name !== '',
        ));

        $existing = [];
        $missing = [];
        $unknown = [];

        foreach ($names as $name) {
            $tag = $this->catalogue->tag($name);

            if ($tag instanceof Tag) {
                $existing[] = $tag;

                continue;
            }

            if ($this->createMissingTags) {
                $missing[] = mb_substr($name, 0, 60);

                continue;
            }

            $unknown[] = $name;
        }

        if ($unknown !== []) {
            $issues[] = RowIssue::warning(
                $number,
                __('This workspace has no tag by that name, and creating tags is switched off. Imported without it.'),
                TaskField::Tags,
                implode(', ', $unknown),
            );
        }

        return [$existing, array_values(array_unique($missing))];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Enums\Priority;
use App\Livewire\App\Concerns\FiltersTasks;

/**
 * What the person exporting is looking at, as plain data.
 *
 * The keys are deliberately the ones the task list and the board already put in the query
 * string (`q`, `status`, `assignee`, `priority`, `tag`, `milestone`, `due`, `overdue`,
 * `unassigned`, `done` — see {@see FiltersTasks}). That makes a
 * filtered list URL and its export URL the same set of parameters, so "export what I am
 * looking at" is a link rather than a second filter bar somebody has to reproduce by hand.
 *
 * Everything arrives from a query string, which anyone can type, so {@see fromArray()} is
 * the only constructor that matters: it discards anything it does not recognise rather than
 * letting an unknown priority or a non-numeric id reach the query builder.
 */
final readonly class ExportFilters
{
    /** The due windows the task list offers. Mirrors FiltersTasks::DUE_RANGES. */
    public const DUE_RANGES = ['overdue', 'today', 'week', 'month', 'none'];

    /**
     * @param list<int> $projectIds empty means every project the reader may see
     * @param list<int> $statusIds
     * @param list<int> $assigneeIds
     * @param list<string> $priorities
     * @param list<int> $tagIds
     * @param list<int> $milestoneIds
     */
    public function __construct(
        public array $projectIds = [],
        public string $search = '',
        public array $statusIds = [],
        public array $assigneeIds = [],
        public array $priorities = [],
        public array $tagIds = [],
        public array $milestoneIds = [],
        public string $dueRange = '',
        public bool $overdueOnly = false,
        public bool $unassignedOnly = false,
        public bool $includeCompleted = true,
        public bool $includeArchived = false,
        public bool $billableOnly = false,
        public ?string $from = null,
        public ?string $to = null,
    ) {}

    /**
     * @param array<array-key, mixed> $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            projectIds: self::ints($input['project'] ?? []),
            search: self::text($input['q'] ?? ''),
            statusIds: self::ints($input['status'] ?? []),
            assigneeIds: self::ints($input['assignee'] ?? []),
            priorities: self::priorities($input['priority'] ?? []),
            tagIds: self::ints($input['tag'] ?? []),
            milestoneIds: self::ints($input['milestone'] ?? []),
            dueRange: self::choice($input['due'] ?? '', self::DUE_RANGES),
            overdueOnly: self::flag($input['overdue'] ?? false),
            unassignedOnly: self::flag($input['unassigned'] ?? false),
            includeCompleted: self::flag($input['done'] ?? true),
            includeArchived: self::flag($input['archived'] ?? false),
            billableOnly: self::flag($input['billable'] ?? false),
            from: self::date($input['from'] ?? null),
            to: self::date($input['to'] ?? null),
        );
    }

    /**
     * The same filters as a query string — what the export button links to.
     *
     * Defaults are dropped so the URL stays readable and so it matches, character for
     * character, the one the task list would have produced for the same selection.
     *
     * @return array<string, mixed>
     */
    public function toQuery(): array
    {
        return array_filter([
            'project' => $this->projectIds,
            'q' => $this->search,
            'status' => $this->statusIds,
            'assignee' => $this->assigneeIds,
            'priority' => $this->priorities,
            'tag' => $this->tagIds,
            'milestone' => $this->milestoneIds,
            'due' => $this->dueRange,
            'overdue' => $this->overdueOnly ? 1 : null,
            'unassigned' => $this->unassignedOnly ? 1 : null,
            'done' => $this->includeCompleted ? 1 : null,
            'archived' => $this->includeArchived ? 1 : null,
            'billable' => $this->billableOnly ? 1 : null,
            'from' => $this->from,
            'to' => $this->to,
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * A short human sentence for the confirmation line: "3 filters applied".
     */
    public function activeCount(): int
    {
        return count(array_filter([
            $this->projectIds !== [],
            $this->search !== '',
            $this->statusIds !== [],
            $this->assigneeIds !== [],
            $this->priorities !== [],
            $this->tagIds !== [],
            $this->milestoneIds !== [],
            $this->dueRange !== '',
            $this->overdueOnly,
            $this->unassignedOnly,
            ! $this->includeCompleted,
            $this->includeArchived,
            $this->billableOnly,
            $this->from !== null,
            $this->to !== null,
        ]));
    }

    /**
     * @return list<int>
     */
    private static function ints(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        $ids = [];

        foreach ($values as $item) {
            if (is_numeric($item) && (int) $item > 0 && ! in_array((int) $item, $ids, true)) {
                $ids[] = (int) $item;
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private static function priorities(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        $priorities = [];

        foreach ($values as $item) {
            $case = is_string($item) ? Priority::tryFrom($item) : null;

            if ($case !== null && ! in_array($case->value, $priorities, true)) {
                $priorities[] = $case->value;
            }
        }

        return $priorities;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, 120) : '';
    }

    /**
     * @param list<string> $allowed
     */
    private static function choice(mixed $value, array $allowed): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : '';
    }

    private static function flag(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        [$year, $month, $day] = array_map(intval(...), explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }
}

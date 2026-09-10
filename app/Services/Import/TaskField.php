<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Services\SearchType;

/**
 * The task attributes a CSV can fill.
 *
 * Local to the import service rather than an `App\Enums` case: it names the rows of a
 * mapping screen, not a value anything persists (the same reasoning as
 * {@see SearchType}).
 *
 * Case order is the order the mapping screen lists them, which is the order somebody would
 * describe a task in: what it is, then how it is classified, then when it is due.
 *
 * The aliases are what makes the guess work. They are matched against the header with
 * everything but letters and digits removed, so "Due Date", "due_date" and "DUE-DATE" are
 * the same string by the time they get here.
 */
enum TaskField: string
{
    case Title = 'title';
    case Description = 'description';
    case Status = 'status';
    case Priority = 'priority';
    case Assignee = 'assignee';
    case DueDate = 'due_date';
    case StartDate = 'start_date';
    case Tags = 'tags';
    case Milestone = 'milestone';
    case Estimate = 'estimate';

    public function label(): string
    {
        return match ($this) {
            self::Title => __('Title'),
            self::Description => __('Description'),
            self::Status => __('Status'),
            self::Priority => __('Priority'),
            self::Assignee => __('Assignee'),
            self::DueDate => __('Due date'),
            self::StartDate => __('Start date'),
            self::Tags => __('Tags'),
            self::Milestone => __('Milestone'),
            self::Estimate => __('Estimate'),
        };
    }

    /**
     * The sentence under the dropdown: what Planvio will do with this column.
     */
    public function hint(): string
    {
        return match ($this) {
            self::Title => __('Required. A row without one cannot be imported.'),
            self::Description => __('Kept as plain text, exactly as written.'),
            self::Status => __('Matched to a column on this project\'s board by name. No match lands the task in the default column.'),
            self::Priority => __('none, low, medium, high or urgent. Anything else falls back to medium.'),
            self::Assignee => __('Matched on email first, then on full name, among this workspace\'s members. No match imports unassigned.'),
            self::DueDate => __('2026-03-31, 31 March 2026, or a phrase like "next month". Read in the workspace timezone.'),
            self::StartDate => __('The same formats as the due date.'),
            self::Tags => __('Separated by commas, semicolons or pipes. Existing tags are attached; new ones only if you allow it below.'),
            self::Milestone => __('Matched to a milestone on this project by name. No match imports without one.'),
            self::Estimate => __('Hours unless a unit is given: 2.5, 90m, 1h 30m and 1:30 all work.'),
        };
    }

    /**
     * A row that cannot fill this field is not a task. Only the title qualifies.
     */
    public function isRequired(): bool
    {
        return $this === self::Title;
    }

    /**
     * Header names this field recognises, already normalised the way {@see ColumnMap}
     * normalises the file's own headers.
     *
     * @return list<string>
     */
    public function aliases(): array
    {
        return match ($this) {
            self::Title => ['title', 'name', 'task', 'taskname', 'tasktitle', 'summary', 'subject', 'item', 'work'],
            self::Description => ['description', 'desc', 'details', 'notes', 'body', 'comment', 'content'],
            self::Status => ['status', 'state', 'column', 'stage', 'progress', 'taskstatus', 'workflow'],
            self::Priority => ['priority', 'importance', 'urgency', 'severity'],
            self::Assignee => ['assignee', 'assignedto', 'assigned', 'owner', 'responsible', 'resource', 'user', 'member', 'email'],
            self::DueDate => ['duedate', 'due', 'deadline', 'enddate', 'end', 'finish', 'finishdate', 'targetdate', 'target'],
            self::StartDate => ['startdate', 'start', 'begin', 'begindate', 'startson', 'from'],
            self::Tags => ['tags', 'tag', 'labels', 'label', 'categories', 'category', 'keywords'],
            self::Milestone => ['milestone', 'phase', 'sprint', 'iteration', 'epic', 'release'],
            self::Estimate => ['estimate', 'estimatedhours', 'estimated', 'effort', 'hours', 'duration', 'timeestimate', 'estimatehours'],
        };
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }
}

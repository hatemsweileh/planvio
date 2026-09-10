<?php

declare(strict_types=1);

namespace App\Actions\Projects;

/**
 * What {@see DuplicateProject} carries across.
 *
 * The defaults describe a faithful copy, because that is what "duplicate" means to the person
 * clicking it. The two most common departures — starting the copy without the finished work,
 * and moving the whole schedule — are the first two flags.
 */
final readonly class DuplicateProjectOptions
{
    public function __construct(
        public bool $tasks = true,
        public bool $completedTasks = true,
        public bool $milestones = true,
        public bool $checklists = true,
        public bool $members = true,
        public bool $tags = true,
        public bool $savedViews = true,
        public bool $assignees = true,
        /**
         * Drop every start and due date instead of copying it. Wins over `shiftDays`.
         */
        public bool $resetDates = false,
        /**
         * Move every copied date by this many days, which is how a quarterly project becomes
         * next quarter's without editing each row.
         */
        public ?int $shiftDays = null,
    ) {}

    /**
     * A structure-only copy: the board, the milestones and the task titles, with nothing
     * carried over from how the original actually went.
     */
    public static function structureOnly(): self
    {
        return new self(
            completedTasks: false,
            checklists: true,
            members: false,
            assignees: false,
            resetDates: true,
        );
    }
}

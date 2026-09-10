<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\Priority;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use DateTimeInterface;

/**
 * Everything {@see CreateTask} needs, already resolved to models.
 *
 * Actions take typed values, never a request array: an array would carry whatever the
 * client sent — `workspace_id` included — straight into a mass assignment. By the time a
 * caller can build one of these it has had to look every reference up, which is also where
 * a wrong id turns into a 404 instead of a silent cross-tenant write.
 */
final readonly class CreateTaskData
{
    public function __construct(
        public Project $project,
        public User $actor,
        public string $title,
        public ?string $description = null,
        public ?TaskStatus $status = null,
        public Priority $priority = Priority::Medium,
        public ?User $assignee = null,
        public ?User $reporter = null,
        public ?Task $parent = null,
        public ?Milestone $milestone = null,
        public ?DateTimeInterface $startDate = null,
        public ?DateTimeInterface $dueDate = null,
        public ?int $estimateMinutes = null,
        public ?float $position = null,
        public ?RecurringTask $recurringTask = null,
        public bool $aiGenerated = false,
    ) {}

    /**
     * The same task, hung under a parent.
     */
    public function withParent(?Task $parent): self
    {
        return new self(
            project: $this->project,
            actor: $this->actor,
            title: $this->title,
            description: $this->description,
            status: $this->status,
            priority: $this->priority,
            assignee: $this->assignee,
            reporter: $this->reporter,
            parent: $parent,
            milestone: $this->milestone,
            startDate: $this->startDate,
            dueDate: $this->dueDate,
            estimateMinutes: $this->estimateMinutes,
            position: $this->position,
            recurringTask: $this->recurringTask,
            aiGenerated: $this->aiGenerated,
        );
    }

    /**
     * The same task, dropped into a specific column at a specific position.
     */
    public function withPlacement(?TaskStatus $status, ?float $position): self
    {
        return new self(
            project: $this->project,
            actor: $this->actor,
            title: $this->title,
            description: $this->description,
            status: $status ?? $this->status,
            priority: $this->priority,
            assignee: $this->assignee,
            reporter: $this->reporter,
            parent: $this->parent,
            milestone: $this->milestone,
            startDate: $this->startDate,
            dueDate: $this->dueDate,
            estimateMinutes: $this->estimateMinutes,
            position: $position ?? $this->position,
            recurringTask: $this->recurringTask,
            aiGenerated: $this->aiGenerated,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\Priority;
use App\Models\Milestone;
use App\Models\TaskStatus;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * A partial edit to a task, expressed as the columns the caller actually touched.
 *
 * The distinction that matters is "leave this alone" versus "set this to null" — clearing
 * a due date and not mentioning it are different intents, and a plain nullable DTO cannot
 * tell them apart. Here a column is touched only when its method was called, so
 * `dueDate(null)` clears the date while never calling `dueDate()` leaves it standing.
 *
 * Immutable: every method returns a new instance, so a half-built change set cannot be
 * mutated out from under the action holding it.
 */
final readonly class TaskChanges
{
    /**
     * @param array<string, mixed> $attributes column => value, touched columns only
     */
    private function __construct(
        public array $attributes = [],
        public ?TaskStatus $status = null,
        public ?User $assignee = null,
        public ?Milestone $milestone = null,
    ) {}

    public static function make(): self
    {
        return new self;
    }

    public function title(string $title): self
    {
        return $this->with('title', $title);
    }

    public function description(?string $description): self
    {
        return $this->with('description', $description);
    }

    public function priority(Priority $priority): self
    {
        return $this->with('priority', $priority);
    }

    /**
     * Moving a task to another column has its own semantics (completion, watchers), so the
     * status is carried as a model and handed to {@see ChangeTaskStatus} rather than being
     * written as a plain column.
     */
    public function status(TaskStatus $status): self
    {
        return new self(
            [...$this->attributes, 'status_id' => $status->getKey()],
            $status,
            $this->assignee,
            $this->milestone,
        );
    }

    public function assignee(?User $assignee): self
    {
        return new self(
            [...$this->attributes, 'assignee_id' => $assignee?->getKey()],
            $this->status,
            $assignee,
            $this->milestone,
        );
    }

    public function milestone(?Milestone $milestone): self
    {
        return new self(
            [...$this->attributes, 'milestone_id' => $milestone?->getKey()],
            $this->status,
            $this->assignee,
            $milestone,
        );
    }

    public function startDate(?DateTimeInterface $date): self
    {
        return $this->with('start_date', $date === null ? null : Carbon::instance($date)->toDateString());
    }

    public function dueDate(?DateTimeInterface $date): self
    {
        return $this->with('due_date', $date === null ? null : Carbon::instance($date)->toDateString());
    }

    public function estimateMinutes(?int $minutes): self
    {
        return $this->with('estimate_minutes', $minutes);
    }

    public function progress(int $progress): self
    {
        return $this->with('progress', $progress);
    }

    public function touches(string $column): bool
    {
        return array_key_exists($column, $this->attributes);
    }

    public function isEmpty(): bool
    {
        return $this->attributes === [];
    }

    /**
     * The touched columns minus the two that are applied through their own actions.
     *
     * @return array<string, mixed>
     */
    public function plainColumns(): array
    {
        return array_diff_key($this->attributes, ['status_id' => null, 'assignee_id' => null]);
    }

    private function with(string $column, mixed $value): self
    {
        return new self(
            [...$this->attributes, $column => $value],
            $this->status,
            $this->assignee,
            $this->milestone,
        );
    }
}

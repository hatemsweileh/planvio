<?php

declare(strict_types=1);

namespace App\Actions\Milestones;

use App\Enums\MilestoneStatus;
use DateTimeInterface;

/**
 * The writable surface of a milestone, as a typed carrier rather than a request array.
 *
 * Null means *leave alone*; an empty string clears the description. `completedAt` is absent
 * on purpose — it is set by the status transition rather than by a caller, so the timestamp
 * and the status can never disagree about whether the milestone is finished.
 */
final readonly class MilestoneAttributes
{
    public function __construct(
        public ?string $name = null,
        public ?string $description = null,
        public ?MilestoneStatus $status = null,
        public DateTimeInterface|string|null $startDate = null,
        public DateTimeInterface|string|null $dueDate = null,
        public ?int $ownerId = null,
        public ?int $position = null,
        public ?int $progress = null,
    ) {}

    /**
     * The provided fields only, keyed by column name.
     *
     * @return array<string, mixed>
     */
    public function toColumns(): array
    {
        $columns = [];

        if ($this->name !== null && trim($this->name) !== '') {
            $columns['name'] = mb_substr(trim($this->name), 0, 255);
        }

        if ($this->description !== null) {
            $columns['description'] = trim($this->description) === '' ? null : trim($this->description);
        }

        if ($this->status !== null) {
            $columns['status'] = $this->status;
        }

        if ($this->startDate !== null) {
            $columns['start_date'] = $this->startDate;
        }

        if ($this->dueDate !== null) {
            $columns['due_date'] = $this->dueDate;
        }

        if ($this->ownerId !== null) {
            $columns['owner_id'] = $this->ownerId;
        }

        if ($this->position !== null) {
            $columns['position'] = $this->position;
        }

        if ($this->progress !== null) {
            $columns['progress'] = max(0, min(100, $this->progress));
        }

        return $columns;
    }
}

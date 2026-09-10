<?php

declare(strict_types=1);

namespace App\Actions\Recurring;

use App\Enums\Priority;

/**
 * The shape stored in `recurring_tasks.template`: what each generated task should look like.
 *
 * References are held as ids rather than models on purpose. A template outlives the objects
 * it points at — an assignee leaves, a milestone is deleted, a board column is renamed —
 * and a rule that stored a serialised model would either fail to load or resurrect stale
 * data years later. Ids are resolved, and re-checked, at the moment each occurrence is
 * generated.
 *
 * `dueDayOffset` is relative because a recurring task has no fixed dates: "due three days
 * after it appears" is the only form of deadline a repeating rule can carry.
 */
final readonly class RecurringTaskTemplate
{
    public function __construct(
        public string $title,
        public ?string $description = null,
        public Priority $priority = Priority::Medium,
        public ?int $assigneeId = null,
        public ?int $statusId = null,
        public ?int $milestoneId = null,
        public ?int $estimateMinutes = null,
        public ?int $dueDayOffset = null,
    ) {}

    /**
     * Rebuild a template from the stored JSON.
     *
     * Every field is defensive: the column is JSON written by an earlier version of the
     * product, and a rule saved before a field existed must still generate tasks today.
     *
     * @param array<string, mixed> $template
     */
    public static function fromArray(array $template): self
    {
        $priority = $template['priority'] ?? null;

        return new self(
            title: is_string($template['title'] ?? null) ? $template['title'] : '',
            description: is_string($template['description'] ?? null) ? $template['description'] : null,
            priority: (is_string($priority) ? Priority::tryFrom($priority) : null) ?? Priority::Medium,
            assigneeId: self::toId($template['assignee_id'] ?? null),
            statusId: self::toId($template['status_id'] ?? null),
            milestoneId: self::toId($template['milestone_id'] ?? null),
            estimateMinutes: self::toId($template['estimate_minutes'] ?? null),
            dueDayOffset: self::toId($template['due_day_offset'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority->value,
            'assignee_id' => $this->assigneeId,
            'status_id' => $this->statusId,
            'milestone_id' => $this->milestoneId,
            'estimate_minutes' => $this->estimateMinutes,
            'due_day_offset' => $this->dueDayOffset,
        ];
    }

    private static function toId(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Enums\StatusCategory;
use App\Support\DefaultNames;

/**
 * One row of a status template, parsed out of untyped JSON.
 *
 * Status templates arrive from three places — `config('planvio.defaults')`, a workspace's
 * saved `settings.task_status_template`, and a project template's `definition.statuses` —
 * and only the first is written by us. Everything that seeds a board goes through this class
 * so a template authored two releases ago, or by hand in the admin panel, cannot produce a
 * status row with a category the enum does not know or a name that is an empty string.
 */
final readonly class StatusDefinition
{
    public function __construct(
        public string $name,
        public string $color,
        public StatusCategory $category,
        public int $position,
        public bool $isDefault = false,
        public bool $isCompleted = false,
    ) {}

    /**
     * Null when the row carries no usable name — the one field with no sensible fallback.
     *
     * @param array<array-key, mixed> $row
     */
    public static function fromArray(array $row, int $position): ?self
    {
        $name = trim((string) ($row['name'] ?? ''));

        if ($name === '') {
            return null;
        }

        // Shipped English becomes this workspace's own editable row here and only here.
        // See App\Support\DefaultNames for why it is not a bare __().
        $name = DefaultNames::translate($name);

        $category = StatusCategory::tryFrom((string) ($row['category'] ?? '')) ?? StatusCategory::Todo;
        $color = trim((string) ($row['color'] ?? ''));

        return new self(
            name: mb_substr($name, 0, 255),
            color: $color === '' ? $category->color() : $color,
            category: $category,
            position: array_key_exists('position', $row) ? (int) $row['position'] : $position,
            isDefault: (bool) ($row['is_default'] ?? false),
            // A template that does not say is trusted to have meant what its category says:
            // "done" and "cancelled" both stop the clock on a task.
            isCompleted: array_key_exists('is_completed', $row)
                ? (bool) $row['is_completed']
                : $category->isClosed(),
        );
    }

    /**
     * @param iterable<array-key, mixed> $rows
     * @return list<self>
     */
    public static function listFrom(iterable $rows): array
    {
        $definitions = [];
        $position = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $definition = self::fromArray($row, $position);

            if ($definition === null) {
                continue;
            }

            $definitions[] = $definition;
            $position++;
        }

        return self::withExactlyOneDefault($definitions);
    }

    /**
     * A board needs exactly one landing column: with none, task creation has nowhere to put
     * a task; with several, which one wins would depend on row order.
     *
     * @param list<self> $definitions
     * @return list<self>
     */
    public static function withExactlyOneDefault(array $definitions): array
    {
        if ($definitions === []) {
            return [];
        }

        $chosen = null;

        foreach ($definitions as $index => $definition) {
            if ($definition->isDefault) {
                $chosen = $index;

                break;
            }
        }

        if ($chosen === null) {
            $chosen = self::firstOpenIndex($definitions);
        }

        $normalised = [];

        foreach ($definitions as $index => $definition) {
            $normalised[] = $definition->isDefault === ($index === $chosen)
                ? $definition
                : $definition->withDefault($index === $chosen);
        }

        return $normalised;
    }

    public function withDefault(bool $isDefault): self
    {
        return new self(
            name: $this->name,
            color: $this->color,
            category: $this->category,
            position: $this->position,
            isDefault: $isDefault,
            isCompleted: $this->isCompleted,
        );
    }

    public function withPosition(int $position): self
    {
        return new self(
            name: $this->name,
            color: $this->color,
            category: $this->category,
            position: $position,
            isDefault: $this->isDefault,
            isCompleted: $this->isCompleted,
        );
    }

    /**
     * Columns for a `task_statuses` row.
     *
     * @return array<string, mixed>
     */
    public function toTaskStatusAttributes(int $workspaceId, int $projectId): array
    {
        return [
            'workspace_id' => $workspaceId,
            'project_id' => $projectId,
            'name' => $this->name,
            'color' => $this->color,
            'category' => $this->category,
            'position' => $this->position,
            'is_default' => $this->isDefault,
            'is_completed' => $this->isCompleted,
        ];
    }

    /**
     * Columns for a `project_statuses` row. Project statuses have no `is_completed`: a
     * project's completion is recorded by `completed_at`, not by its lifecycle stage.
     *
     * @return array<string, mixed>
     */
    public function toProjectStatusAttributes(int $workspaceId): array
    {
        return [
            'workspace_id' => $workspaceId,
            'name' => $this->name,
            'color' => $this->color,
            'category' => $this->category,
            'position' => $this->position,
            'is_default' => $this->isDefault,
        ];
    }

    /**
     * The storable shape, as saved into `workspaces.settings`.
     *
     * @return array{name: string, color: string, category: string, position: int, is_default: bool, is_completed: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'color' => $this->color,
            'category' => $this->category->value,
            'position' => $this->position,
            'is_default' => $this->isDefault,
            'is_completed' => $this->isCompleted,
        ];
    }

    /**
     * @param list<self> $definitions
     * @return list<array<string, mixed>>
     */
    public static function toArrayList(array $definitions): array
    {
        return array_values(array_map(
            static fn (self $definition): array => $definition->toArray(),
            $definitions,
        ));
    }

    /**
     * @param list<self> $definitions
     */
    private static function firstOpenIndex(array $definitions): int
    {
        foreach ($definitions as $index => $definition) {
            if (! $definition->category->isClosed() && $definition->category !== StatusCategory::Backlog) {
                return $index;
            }
        }

        return 0;
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Enums\Priority;
use App\Enums\ProjectHealth;
use App\Enums\ProjectType;
use DateTimeInterface;

/**
 * The writable surface of a project, as a typed carrier rather than a request array.
 *
 * Null means *leave alone*, which lets one carrier serve create, update, duplicate and
 * template instantiation. An empty string clears a nullable text column; the date fields
 * take a {@see DateTimeInterface} or a `Y-m-d` string, and the actions normalise both.
 *
 * `taskNumberSeq` is deliberately absent, mirroring the model's `$fillable`: the per-project
 * counter is advanced by task creation and must never arrive from outside.
 */
final readonly class ProjectAttributes
{
    /**
     * @param array<string, mixed>|null $settings
     * @param array<string, mixed>|null $aiSettings
     */
    public function __construct(
        public ?string $name = null,
        public ?string $key = null,
        public ?string $slug = null,
        public ?string $description = null,
        public ?string $icon = null,
        public ?string $logoPath = null,
        public ?string $color = null,
        public ?ProjectType $type = null,
        public ?int $statusId = null,
        public ?ProjectHealth $health = null,
        public ?string $healthNote = null,
        public ?Priority $priority = null,
        public ?int $ownerId = null,
        public ?int $managerId = null,
        public ?string $clientName = null,
        public ?string $department = null,
        public DateTimeInterface|string|null $startDate = null,
        public DateTimeInterface|string|null $targetDate = null,
        public int|float|string|null $budget = null,
        public ?string $currency = null,
        public ?array $settings = null,
        public ?array $aiSettings = null,
    ) {}

    /**
     * Columns that accept null, and so can be cleared by passing an empty string.
     *
     * @var list<string>
     */
    private const CLEARABLE = [
        'description', 'icon', 'logo_path', 'health_note', 'client_name', 'department',
    ];

    /**
     * The provided fields only, keyed by column name.
     *
     * `key` and `slug` are excluded: both are derived and deduplicated by the generators, so
     * letting them through here would write an unchecked value straight into a unique index.
     * The JSON documents are excluded for the same reason as on a workspace — the actions
     * merge them rather than replacing them wholesale.
     *
     * @return array<string, mixed>
     */
    public function toColumns(): array
    {
        $candidates = [
            'name' => $this->name,
            'description' => $this->description,
            'icon' => $this->icon,
            'logo_path' => $this->logoPath,
            'color' => $this->color,
            'type' => $this->type,
            'status_id' => $this->statusId,
            'health' => $this->health,
            'health_note' => $this->healthNote,
            'priority' => $this->priority,
            'owner_id' => $this->ownerId,
            'manager_id' => $this->managerId,
            'client_name' => $this->clientName,
            'department' => $this->department,
            'start_date' => $this->startDate,
            'target_date' => $this->targetDate,
            'budget' => $this->budget,
            'currency' => $this->currency,
        ];

        $columns = [];

        foreach ($candidates as $column => $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($value)) {
                $trimmed = trim($value);

                if ($trimmed === '' && ! in_array($column, self::CLEARABLE, true)) {
                    continue;
                }

                $columns[$column] = $trimmed === '' ? null : $trimmed;

                continue;
            }

            $columns[$column] = $value;
        }

        if (isset($columns['currency']) && is_string($columns['currency'])) {
            $columns['currency'] = mb_strtoupper(mb_substr($columns['currency'], 0, 3));
        }

        return $columns;
    }

    public function hasSettings(): bool
    {
        return $this->settings !== null;
    }

    public function hasAiSettings(): bool
    {
        return $this->aiSettings !== null;
    }

    /**
     * A copy with the name replaced — used by DuplicateProject, which has to name the copy
     * before it knows what the caller wanted.
     */
    public function withName(string $name): self
    {
        return new self(
            name: $name,
            key: $this->key,
            slug: $this->slug,
            description: $this->description,
            icon: $this->icon,
            logoPath: $this->logoPath,
            color: $this->color,
            type: $this->type,
            statusId: $this->statusId,
            health: $this->health,
            healthNote: $this->healthNote,
            priority: $this->priority,
            ownerId: $this->ownerId,
            managerId: $this->managerId,
            clientName: $this->clientName,
            department: $this->department,
            startDate: $this->startDate,
            targetDate: $this->targetDate,
            budget: $this->budget,
            currency: $this->currency,
            settings: $this->settings,
            aiSettings: $this->aiSettings,
        );
    }
}

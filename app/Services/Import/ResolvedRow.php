<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\Priority;
use App\Models\Milestone;
use App\Models\Tag;
use App\Models\TaskStatus;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * One row of the file, turned into the task it describes — or into the reasons it cannot be.
 *
 * This is what the preview shows and what the importer writes, and it is the same object in
 * both cases. A preview built from a different code path than the import is a preview that
 * eventually lies.
 */
final readonly class ResolvedRow
{
    /**
     * @param list<Tag> $tags tags that already exist and will be attached
     * @param list<string> $newTagNames tags the file names that do not exist yet
     * @param list<RowIssue> $issues
     */
    public function __construct(
        public int $row,
        public string $title,
        public ?string $description,
        public ?TaskStatus $status,
        public Priority $priority,
        public ?User $assignee,
        public ?CarbonImmutable $startDate,
        public ?CarbonImmutable $dueDate,
        public array $tags,
        public array $newTagNames,
        public ?Milestone $milestone,
        public ?int $estimateMinutes,
        public array $issues,
    ) {}

    public function hasErrors(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->blocks()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<RowIssue>
     */
    public function errors(): array
    {
        return array_values(array_filter($this->issues, static fn (RowIssue $issue): bool => $issue->blocks()));
    }

    /**
     * @return list<RowIssue>
     */
    public function warnings(): array
    {
        return array_values(array_filter($this->issues, static fn (RowIssue $issue): bool => ! $issue->blocks()));
    }

    /**
     * Every tag name this row will end up carrying, existing and new alike — what the
     * preview prints in its tags column.
     *
     * @return list<string>
     */
    public function tagNames(): array
    {
        return [
            ...array_map(static fn (Tag $tag): string => $tag->name, $this->tags),
            ...$this->newTagNames,
        ];
    }

    /**
     * The estimate as people write it back: "1h 30m".
     */
    public function estimateLabel(): ?string
    {
        if ($this->estimateMinutes === null) {
            return null;
        }

        $hours = intdiv($this->estimateMinutes, 60);
        $minutes = $this->estimateMinutes % 60;

        return match (true) {
            $hours === 0 => $minutes.'m',
            $minutes === 0 => $hours.'h',
            default => $hours.'h '.$minutes.'m',
        };
    }
}

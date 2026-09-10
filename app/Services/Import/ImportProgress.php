<?php

declare(strict_types=1);

namespace App\Services\Import;

use Carbon\CarbonImmutable;

/**
 * How far a queued import has got.
 *
 * Deliberately a cache record rather than a table. The architecture's schema (§5) is
 * normative and holds no import table, and none is warranted: this is the transient state
 * of one run, useful for as long as somebody is watching the bar move and worthless
 * afterwards. What survives is what the import actually did — the tasks, their activity
 * entries and their notifications — because every row goes through `CreateTask` exactly as
 * a manual create does.
 *
 * The issue list is capped for the same reason it is capped in {@see ImportReport}: this
 * object is serialised into a cache entry and rendered into a Livewire payload, and neither
 * should carry twenty thousand sentences.
 */
final readonly class ImportProgress
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const FINISHED = 'finished';

    public const FAILED = 'failed';

    /** Failures kept for the report. Past this the pattern is clear without the rest. */
    public const MAX_ISSUES = 100;

    /**
     * @param list<RowIssue> $issues rows that failed while being written
     */
    public function __construct(
        public string $status = self::QUEUED,
        public int $total = 0,
        public int $processed = 0,
        public int $created = 0,
        public int $skipped = 0,
        public int $failed = 0,
        public array $issues = [],
        public bool $issuesTruncated = false,
        public ?string $message = null,
        public ?string $startedAt = null,
        public ?string $finishedAt = null,
    ) {}

    public static function queued(int $total): self
    {
        return new self(status: self::QUEUED, total: $total);
    }

    public function starting(): self
    {
        return $this->with(status: self::RUNNING, startedAt: CarbonImmutable::now()->toIso8601String());
    }

    /**
     * @param list<RowIssue> $issues
     */
    public function advanced(int $processed, int $created, int $skipped, int $failed, array $issues): self
    {
        $kept = array_slice($issues, 0, self::MAX_ISSUES);

        return $this->with(
            status: self::RUNNING,
            processed: $processed,
            created: $created,
            skipped: $skipped,
            failed: $failed,
            issues: $kept,
            issuesTruncated: count($issues) > count($kept),
        );
    }

    public function finished(): self
    {
        return $this->with(status: self::FINISHED, finishedAt: CarbonImmutable::now()->toIso8601String());
    }

    public function failedWith(string $message): self
    {
        return $this->with(
            status: self::FAILED,
            message: $message,
            finishedAt: CarbonImmutable::now()->toIso8601String(),
        );
    }

    public function isRunning(): bool
    {
        return $this->status === self::QUEUED || $this->status === self::RUNNING;
    }

    public function isDone(): bool
    {
        return $this->status === self::FINISHED || $this->status === self::FAILED;
    }

    public function percentage(): int
    {
        if ($this->total <= 0) {
            return $this->isDone() ? 100 : 0;
        }

        return max(0, min(100, (int) round(($this->processed / $this->total) * 100)));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'total' => $this->total,
            'processed' => $this->processed,
            'created' => $this->created,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
            'issues' => array_map(static fn (RowIssue $issue): array => $issue->toArray(), $this->issues),
            'issues_truncated' => $this->issuesTruncated,
            'message' => $this->message,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $issues = is_array($data['issues'] ?? null) ? $data['issues'] : [];

        return new self(
            status: (string) ($data['status'] ?? self::QUEUED),
            total: (int) ($data['total'] ?? 0),
            processed: (int) ($data['processed'] ?? 0),
            created: (int) ($data['created'] ?? 0),
            skipped: (int) ($data['skipped'] ?? 0),
            failed: (int) ($data['failed'] ?? 0),
            issues: array_values(array_map(
                static fn (mixed $issue): RowIssue => RowIssue::fromArray(is_array($issue) ? $issue : []),
                $issues,
            )),
            issuesTruncated: (bool) ($data['issues_truncated'] ?? false),
            message: is_string($data['message'] ?? null) ? $data['message'] : null,
            startedAt: is_string($data['started_at'] ?? null) ? $data['started_at'] : null,
            finishedAt: is_string($data['finished_at'] ?? null) ? $data['finished_at'] : null,
        );
    }

    /**
     * @param list<RowIssue>|null $issues
     */
    private function with(
        ?string $status = null,
        ?int $total = null,
        ?int $processed = null,
        ?int $created = null,
        ?int $skipped = null,
        ?int $failed = null,
        ?array $issues = null,
        ?bool $issuesTruncated = null,
        ?string $message = null,
        ?string $startedAt = null,
        ?string $finishedAt = null,
    ): self {
        return new self(
            status: $status ?? $this->status,
            total: $total ?? $this->total,
            processed: $processed ?? $this->processed,
            created: $created ?? $this->created,
            skipped: $skipped ?? $this->skipped,
            failed: $failed ?? $this->failed,
            issues: $issues ?? $this->issues,
            issuesTruncated: $issuesTruncated ?? $this->issuesTruncated,
            message: $message ?? $this->message,
            startedAt: $startedAt ?? $this->startedAt,
            finishedAt: $finishedAt ?? $this->finishedAt,
        );
    }
}

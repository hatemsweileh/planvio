<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * Everything a queued import needs, as ids and scalars.
 *
 * No models. A job is serialised into a database row and may be picked up minutes later by
 * a worker with a different container, and `SerializesModels` would re-fetch a Project
 * without a workspace bound — which is exactly the case where `WorkspaceScope` is inert
 * (ARCHITECTURE.md §3). Passing ids forces the job to bind the tenant and look everything up
 * itself, which is the only version that is safe to read.
 *
 * The path is relative to the private disk and is written by the wizard, never by a client.
 * It is still validated on the way back in: a job payload is a database row, and a row is
 * not a thing to trust with a filesystem path.
 */
final readonly class ImportSpec
{
    /**
     * @param array<string, int> $mapping field value => column index
     */
    public function __construct(
        public int $workspaceId,
        public int $projectId,
        public int $actorId,
        public string $path,
        public string $delimiter,
        public array $mapping,
        public bool $createMissingTags,
        public string $token,
        public int $total,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'workspace_id' => $this->workspaceId,
            'project_id' => $this->projectId,
            'actor_id' => $this->actorId,
            'path' => $this->path,
            'delimiter' => $this->delimiter,
            'mapping' => $this->mapping,
            'create_missing_tags' => $this->createMissingTags,
            'token' => $this->token,
            'total' => $this->total,
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, int> $mapping */
        $mapping = [];

        foreach (is_array($data['mapping'] ?? null) ? $data['mapping'] : [] as $field => $index) {
            if (is_string($field) && TaskField::tryFrom($field) !== null && is_numeric($index)) {
                $mapping[$field] = (int) $index;
            }
        }

        return new self(
            workspaceId: (int) ($data['workspace_id'] ?? 0),
            projectId: (int) ($data['project_id'] ?? 0),
            actorId: (int) ($data['actor_id'] ?? 0),
            path: self::safePath((string) ($data['path'] ?? '')),
            delimiter: self::safeDelimiter((string) ($data['delimiter'] ?? ',')),
            mapping: $mapping,
            createMissingTags: (bool) ($data['create_missing_tags'] ?? false),
            token: (string) ($data['token'] ?? ''),
            total: (int) ($data['total'] ?? 0),
        );
    }

    public function columnMap(int $columnCount): ColumnMap
    {
        return ColumnMap::fromArray($this->mapping, $columnCount);
    }

    /**
     * Staged uploads live in one directory and are named by the wizard. Anything that is not
     * that shape is refused rather than sanitised: there is no legitimate import path with a
     * `..` in it, so the honest answer is an empty string and a job that stops.
     */
    private static function safePath(string $path): string
    {
        return preg_match('#^imports/\d+/[A-Za-z0-9_-]+\.csv$#', $path) === 1 ? $path : '';
    }

    private static function safeDelimiter(string $delimiter): string
    {
        return in_array($delimiter, CsvSource::DELIMITERS, true) ? $delimiter : ',';
    }
}

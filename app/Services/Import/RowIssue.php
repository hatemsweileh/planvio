<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * One thing wrong with one row.
 *
 * It carries the row number a person can find in their spreadsheet, the field it concerns,
 * and — this is the part that makes a report usable — the value that was actually in the
 * cell. "Row 42: due date" sends somebody hunting; "Row 42, due date: 'next thurs' is not a
 * date Planvio can read" tells them what to change.
 */
final readonly class RowIssue
{
    /** Longest quoted value. A pasted paragraph must not push the message off the screen. */
    private const MAX_VALUE = 80;

    public function __construct(
        public int $row,
        public IssueSeverity $severity,
        public string $message,
        public ?TaskField $field = null,
        public ?string $value = null,
    ) {}

    public static function error(int $row, string $message, ?TaskField $field = null, ?string $value = null): self
    {
        return new self($row, IssueSeverity::Error, $message, $field, self::clip($value));
    }

    public static function warning(int $row, string $message, ?TaskField $field = null, ?string $value = null): self
    {
        return new self($row, IssueSeverity::Warning, $message, $field, self::clip($value));
    }

    public function blocks(): bool
    {
        return $this->severity->blocks();
    }

    /**
     * @return array{row: int, severity: string, message: string, field: string|null, value: string|null}
     */
    public function toArray(): array
    {
        return [
            'row' => $this->row,
            'severity' => $this->severity->value,
            'message' => $this->message,
            'field' => $this->field?->value,
            'value' => $this->value,
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            row: (int) ($data['row'] ?? 0),
            severity: IssueSeverity::tryFrom((string) ($data['severity'] ?? '')) ?? IssueSeverity::Error,
            message: (string) ($data['message'] ?? ''),
            field: is_string($data['field'] ?? null) ? TaskField::tryFrom($data['field']) : null,
            value: is_string($data['value'] ?? null) ? $data['value'] : null,
        );
    }

    private static function clip(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return mb_strlen($value) > self::MAX_VALUE
            ? mb_substr($value, 0, self::MAX_VALUE).'…'
            : $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * Which column of the file fills which field of a task.
 *
 * Held as indexes rather than header names, because headers are not unique — a file with
 * two columns called "Date" is common, and a map keyed by name would silently point both at
 * the same one.
 *
 * The guess is a starting point and nothing more. It is offered on the mapping screen with
 * every dropdown editable, because a wrong guess that cannot be corrected is worse than no
 * guess at all.
 */
final readonly class ColumnMap
{
    /**
     * @param array<string, int> $columns field value => column index
     */
    private function __construct(public array $columns) {}

    /**
     * @param array<string, int> $columns
     */
    public static function make(array $columns = []): self
    {
        return new self($columns);
    }

    /**
     * The map a file suggests for itself.
     *
     * Headers are compared with everything but letters and digits stripped, so "Due Date",
     * "due_date" and "DUE DATE" are one string. An exact alias wins outright; failing that a
     * header that contains an alias is accepted, which catches "Task Due Date". The first
     * column to claim a field keeps it, and no column is used twice: mapping one column onto
     * two fields would import the same text into both.
     *
     * @param list<string> $headers
     */
    public static function guess(array $headers): self
    {
        $normalised = array_map(self::normalise(...), $headers);
        $columns = [];
        $taken = [];

        // Exact matches first, across every field, before any fuzzy one is considered — so a
        // file with both "Status" and "Task status" fills Status from the exact header.
        foreach ([true, false] as $exactOnly) {
            foreach (TaskField::all() as $field) {
                if (isset($columns[$field->value])) {
                    continue;
                }

                foreach ($normalised as $index => $header) {
                    if ($header === '' || in_array($index, $taken, true)) {
                        continue;
                    }

                    if (self::matches($header, $field, $exactOnly)) {
                        $columns[$field->value] = $index;
                        $taken[] = $index;

                        break;
                    }
                }
            }
        }

        return new self($columns);
    }

    /**
     * Rebuild from the mapping screen's state, discarding anything that no longer points at
     * a real column. The screen's values arrive as strings and may name a field that has
     * since been removed from a re-uploaded file.
     *
     * @param array<array-key, mixed> $raw
     */
    public static function fromArray(array $raw, int $columnCount): self
    {
        $columns = [];

        foreach (TaskField::all() as $field) {
            $value = $raw[$field->value] ?? null;

            if (! is_numeric($value)) {
                continue;
            }

            $index = (int) $value;

            if ($index >= 0 && $index < $columnCount && ! in_array($index, $columns, true)) {
                $columns[$field->value] = $index;
            }
        }

        return new self($columns);
    }

    public function indexFor(TaskField $field): ?int
    {
        return $this->columns[$field->value] ?? null;
    }

    public function has(TaskField $field): bool
    {
        return isset($this->columns[$field->value]);
    }

    /**
     * The cell this field reads on a given row, or null when the field is unmapped or the
     * row is short. A ragged file is normal — spreadsheets drop trailing empty cells.
     *
     * @param list<string> $row
     */
    public function value(array $row, TaskField $field): ?string
    {
        $index = $this->indexFor($field);

        if ($index === null) {
            return null;
        }

        $value = $row[$index] ?? '';

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return $this->columns;
    }

    /**
     * The screen's shape: every field present, unmapped ones as an empty string, so a
     * `<select>` bound to it always has a value.
     *
     * @return array<string, int|string>
     */
    public function toFormState(): array
    {
        $state = [];

        foreach (TaskField::all() as $field) {
            $state[$field->value] = $this->columns[$field->value] ?? '';
        }

        return $state;
    }

    /**
     * Fields that must be mapped before the import can proceed.
     *
     * @return list<TaskField>
     */
    public function missingRequired(): array
    {
        return array_values(array_filter(
            TaskField::all(),
            fn (TaskField $field): bool => $field->isRequired() && ! $this->has($field),
        ));
    }

    private static function matches(string $header, TaskField $field, bool $exactOnly): bool
    {
        foreach ($field->aliases() as $alias) {
            if ($header === $alias) {
                return true;
            }

            if (! $exactOnly && mb_strlen($alias) >= 4 && str_contains($header, $alias)) {
                return true;
            }
        }

        return false;
    }

    private static function normalise(string $header): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($header));
    }
}

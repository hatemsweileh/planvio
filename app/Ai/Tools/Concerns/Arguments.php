<?php

declare(strict_types=1);

namespace App\Ai\Tools\Concerns;

use BackedEnum;

/**
 * The arguments of one tool call, after they have passed the tool's own JSON Schema.
 *
 * Tools never read `$args['title']` directly. Everything the model sends is untrusted text
 * that has travelled through a provider, so the difference between "absent", "null" and
 * "empty string" has to be answered the same way in twenty places or it will be answered
 * three different ways in twenty places. This object gives one answer each:
 *
 *   - {@see has()} is *present in the payload*, whatever the value — the "clear this field"
 *     signal, which a nullable getter alone cannot express;
 *   - the typed getters return the schema-checked value or the caller's default, never a
 *     surprise type, because {@see ValidatesArguments} has already coerced and bounded them;
 *   - {@see text()} trims and collapses to null, so a title of `"   "` is absent rather
 *     than a record named with three spaces.
 *
 * Nothing here validates: by the time an instance exists the schema has been satisfied.
 * It is a reader, and it is deliberately incapable of reaching a key the schema did not
 * declare — `additionalProperties: false` is enforced before construction, so an argument
 * the tool never described cannot be smuggled through to an Action.
 */
final readonly class Arguments
{
    /**
     * @param array<string, mixed> $values schema-checked and coerced
     */
    public function __construct(private array $values) {}

    /**
     * Whether the key was present in the call at all — `null` included.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * Present and not null: the test for "the caller wants this set to something".
     */
    public function filled(string $key): bool
    {
        return ($this->values[$key] ?? null) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * A trimmed string, or null when absent, null, or nothing but whitespace.
     */
    public function text(string $key): ?string
    {
        $value = $this->values[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->values[$key] ?? null;

        return is_int($value) ? $value : $default;
    }

    public function nullableInt(string $key): ?int
    {
        $value = $this->values[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->values[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    /**
     * A list of trimmed, non-empty strings. Anything else in the array is dropped rather
     * than turned into `""`, so a malformed entry cannot become an empty checklist item.
     *
     * @return list<string>
     */
    public function strings(string $key): array
    {
        $value = $this->values[$key] ?? null;

        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $trimmed = trim($item);

            if ($trimmed !== '') {
                $strings[] = $trimmed;
            }
        }

        return $strings;
    }

    /**
     * A list of positive integers, deduplicated in the order they arrived.
     *
     * Ids from a model are the input most worth normalising: a repeated id in a bulk call
     * would otherwise be counted twice in "how many did you change".
     *
     * @return list<int>
     */
    public function ids(string $key): array
    {
        $value = $this->values[$key] ?? null;

        if (! is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $item) {
            $id = is_int($item) ? $item : null;

            if ($id !== null && $id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * A backed enum case, or null when the key is absent or the value is not one of its
     * cases. The schema's `enum` keyword has already rejected unknown values, so a null
     * here means "not given" rather than "not understood".
     *
     * @template TEnum of BackedEnum
     *
     * @param class-string<TEnum> $enum
     * @return TEnum|null
     */
    public function enum(string $key, string $enum): ?BackedEnum
    {
        $value = $this->values[$key] ?? null;

        return is_string($value) ? $enum::tryFrom($value) : null;
    }
}

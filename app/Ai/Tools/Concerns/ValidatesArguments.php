<?php

declare(strict_types=1);

namespace App\Ai\Tools\Concerns;

/**
 * Step 2 of the tool pipeline: the arguments must match the schema the tool published, and
 * anything else is rejected rather than ignored (AI_SECURITY.md, "Tool authorization").
 *
 * Planvio ships no JSON Schema package, and pulling one in for this would violate the
 * deployment invariant that production runs without Composer. What is implemented here is
 * the strict subset the tool schemas actually use — `type`, `enum`, `required`,
 * `additionalProperties: false`, the length/range/size bounds and one level of `items` —
 * and nothing else. A schema keyword this validator does not know is not silently skipped
 * in a way that could widen what is accepted: the keywords it ignores (`description`,
 * `title`) cannot loosen a constraint, and every keyword that can is implemented.
 *
 * ## Why an unknown property is an error rather than a shrug
 *
 * A model that invents `"force": true` or `"workspace_id": 9` is either confused or being
 * steered by injected text. Dropping the key quietly would let the second case keep probing
 * for free; failing the call tells the model plainly that the argument does not exist, and
 * leaves a row in `ai_tool_runs` that a human can read afterwards.
 *
 * ## Coercion, and its limits
 *
 * Providers are not consistent about JSON types — `"7"` for an integer and `"true"` for a
 * boolean both turn up. Refusing those produces failures that teach the model nothing, so
 * scalars are coerced when the coercion is lossless and unambiguous: an integer-shaped
 * string becomes an int, `"true"`/`"false"` become bools, a number becomes a string. What is
 * never coerced is structure — an object is not an array, a string is not a list — because
 * that is where a shape confusion could change which records a call touches.
 */
trait ValidatesArguments
{
    /**
     * Check and normalise one call against a schema.
     *
     * @param array<string, mixed> $args
     * @param array<string, mixed> $schema
     * @return array{0: list<string>, 1: array<string, mixed>} errors, then the coerced values
     */
    protected function checkSchema(array $args, array $schema): array
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $allowsExtra = ($schema['additionalProperties'] ?? false) === true;

        $errors = [];
        $values = [];

        if (! $allowsExtra) {
            $unknown = array_values(array_diff(array_keys($args), array_keys($properties)));

            if ($unknown !== []) {
                $errors[] = __('Unknown argument(s): :names.', ['names' => implode(', ', $unknown)]);
            }
        }

        foreach ($required as $name) {
            if (! is_string($name)) {
                continue;
            }

            // Presence only. Whether null is acceptable is the property's own `type` to
            // answer: `assignee_id: null` is a required argument saying "unassign", and
            // conflating "must be supplied" with "must be non-null" would make that
            // unexpressible.
            if (! array_key_exists($name, $args)) {
                $errors[] = __(':name is required.', ['name' => $name]);
            }
        }

        foreach ($properties as $name => $rule) {
            if (! is_string($name) || ! is_array($rule) || ! array_key_exists($name, $args)) {
                continue;
            }

            [$fieldErrors, $value] = $this->checkValue($name, $args[$name], $rule);

            if ($fieldErrors !== []) {
                foreach ($fieldErrors as $message) {
                    $errors[] = $message;
                }

                continue;
            }

            $values[$name] = $value;
        }

        return [$errors, $values];
    }

    /**
     * @param array<string, mixed> $rule
     * @return array{0: list<string>, 1: mixed}
     */
    private function checkValue(string $name, mixed $value, array $rule): array
    {
        $types = $this->typesOf($rule);

        if ($value === null) {
            return in_array('null', $types, true)
                ? [[], null]
                : [[__(':name may not be null.', ['name' => $name])], null];
        }

        $coerced = $this->coerce($value, $types);

        if ($coerced === null) {
            return [[__(':name must be of type :type.', [
                'name' => $name,
                'type' => implode(' or ', array_values(array_diff($types, ['null']))),
            ])], null];
        }

        $value = $coerced[0];

        $errors = [
            ...$this->checkEnum($name, $value, $rule),
            ...$this->checkBounds($name, $value, $rule),
        ];

        if ($errors !== [] || ! is_array($value)) {
            return [$errors, $value];
        }

        return $this->checkItems($name, $value, $rule);
    }

    /**
     * @param array<string, mixed> $rule
     * @return list<string>
     */
    private function typesOf(array $rule): array
    {
        $declared = $rule['type'] ?? 'string';

        if (is_string($declared)) {
            return [$declared];
        }

        if (! is_array($declared)) {
            return ['string'];
        }

        $types = [];

        foreach ($declared as $type) {
            if (is_string($type)) {
                $types[] = $type;
            }
        }

        return $types === [] ? ['string'] : $types;
    }

    /**
     * The value as one of $types, wrapped in a one-element array so a legitimate `null`
     * result is distinguishable from "no type matched".
     *
     * @param list<string> $types
     * @return array{0: mixed}|null
     */
    private function coerce(mixed $value, array $types): ?array
    {
        foreach ($types as $type) {
            $coerced = match ($type) {
                'string' => $this->asString($value),
                'integer' => $this->asInteger($value),
                'number' => $this->asNumber($value),
                'boolean' => $this->asBoolean($value),
                // Structure is never coerced: a bare string is not a one-element list, and an
                // object is not an array. A shape confusion here would change which records
                // the call reaches.
                'array' => is_array($value) && array_is_list($value) ? [$value] : null,
                'object' => is_array($value) ? [$value] : null,
                default => null,
            };

            if ($coerced !== null) {
                return $coerced;
            }
        }

        return null;
    }

    /**
     * @return array{0: string}|null
     */
    private function asString(mixed $value): ?array
    {
        if (is_string($value)) {
            return [$value];
        }

        // A number where a string was declared is a provider quirk, not an attack: "2026"
        // is a perfectly good task title.
        return is_int($value) || is_float($value) ? [(string) $value] : null;
    }

    /**
     * @return array{0: int}|null
     */
    private function asInteger(mixed $value): ?array
    {
        if (is_int($value)) {
            return [$value];
        }

        if (is_float($value) && floor($value) === $value && is_finite($value)) {
            return [(int) $value];
        }

        return is_string($value) && preg_match('/^-?\d{1,18}$/', trim($value)) === 1
            ? [(int) trim($value)]
            : null;
    }

    /**
     * @return array{0: float|int}|null
     */
    private function asNumber(mixed $value): ?array
    {
        if (is_int($value) || is_float($value)) {
            return [$value];
        }

        return is_string($value) && is_numeric(trim($value)) ? [(float) trim($value)] : null;
    }

    /**
     * @return array{0: bool}|null
     */
    private function asBoolean(mixed $value): ?array
    {
        if (is_bool($value)) {
            return [$value];
        }

        return match (is_string($value) ? mb_strtolower(trim($value)) : $value) {
            'true', 1 => [true],
            'false', 0 => [false],
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $rule
     * @return list<string>
     */
    private function checkEnum(string $name, mixed $value, array $rule): array
    {
        $allowed = $rule['enum'] ?? null;

        if (! is_array($allowed) || in_array($value, $allowed, true)) {
            return [];
        }

        $labels = array_map(static fn (mixed $case): string => is_scalar($case) ? (string) $case : '?', $allowed);

        return [__(':name must be one of: :values.', ['name' => $name, 'values' => implode(', ', $labels)])];
    }

    /**
     * @param array<string, mixed> $rule
     * @return list<string>
     */
    private function checkBounds(string $name, mixed $value, array $rule): array
    {
        $errors = [];

        if (is_string($value)) {
            $length = mb_strlen($value);

            if (is_int($rule['minLength'] ?? null) && $length < $rule['minLength']) {
                $errors[] = __(':name must be at least :n characters.', ['name' => $name, 'n' => $rule['minLength']]);
            }

            if (is_int($rule['maxLength'] ?? null) && $length > $rule['maxLength']) {
                $errors[] = __(':name must be at most :n characters.', ['name' => $name, 'n' => $rule['maxLength']]);
            }
        }

        if (is_int($value) || is_float($value)) {
            if (is_numeric($rule['minimum'] ?? null) && $value < $rule['minimum']) {
                $errors[] = __(':name must be at least :n.', ['name' => $name, 'n' => $rule['minimum']]);
            }

            if (is_numeric($rule['maximum'] ?? null) && $value > $rule['maximum']) {
                $errors[] = __(':name must be at most :n.', ['name' => $name, 'n' => $rule['maximum']]);
            }
        }

        if (is_array($value)) {
            $count = count($value);

            if (is_int($rule['minItems'] ?? null) && $count < $rule['minItems']) {
                $errors[] = __(':name must contain at least :n item(s).', ['name' => $name, 'n' => $rule['minItems']]);
            }

            if (is_int($rule['maxItems'] ?? null) && $count > $rule['maxItems']) {
                $errors[] = __(':name must contain at most :n item(s).', ['name' => $name, 'n' => $rule['maxItems']]);
            }
        }

        return $errors;
    }

    /**
     * One level of `items`, which is all any tool schema here declares: lists of strings and
     * lists of ids. Nested objects inside an array are refused by the type check rather than
     * walked, because no tool accepts one and accepting one by accident is how a validator
     * stops being a boundary.
     *
     * @param array<array-key, mixed> $value
     * @param array<string, mixed> $rule
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private function checkItems(string $name, array $value, array $rule): array
    {
        $items = $rule['items'] ?? null;

        if (! is_array($items)) {
            return [[], array_values($value)];
        }

        $types = $this->typesOf($items);
        $errors = [];
        $coercedItems = [];

        foreach (array_values($value) as $index => $item) {
            $coerced = $item === null ? null : $this->coerce($item, $types);

            if ($coerced === null) {
                $errors[] = __(':name[:index] must be of type :type.', [
                    'name' => $name,
                    'index' => $index,
                    'type' => implode(' or ', $types),
                ]);

                continue;
            }

            $itemErrors = [
                ...$this->checkEnum($name.'['.$index.']', $coerced[0], $items),
                ...$this->checkBounds($name.'['.$index.']', $coerced[0], $items),
            ];

            if ($itemErrors !== []) {
                foreach ($itemErrors as $message) {
                    $errors[] = $message;
                }

                continue;
            }

            $coercedItems[] = $coerced[0];
        }

        return [$errors, $coercedItems];
    }
}

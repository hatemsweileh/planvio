<?php

declare(strict_types=1);

namespace App\Services\Translation;

/**
 * What one pass of {@see TranslationCatalogue::scan()} found.
 *
 * `dynamic` is the number that keeps this honest. A key assembled at runtime —
 * `__('enums.priority.'.$case->value)` — cannot be recovered by reading the source, so it is
 * counted rather than guessed at. A catalogue that reported 100% while thirty call sites
 * were invisible to it would be worse than one that says how much it cannot see.
 */
final class CatalogueScan
{
    /**
     * @param list<string> $json literal keys, the `lang/<locale>.json` catalogue
     * @param array<string, list<string>> $groups group name => item keys within it
     * @param int $dynamic call sites whose key is built at runtime
     * @param int $occurrences call sites whose key was read successfully
     * @param int $files source files read
     */
    public function __construct(
        public readonly array $json,
        public readonly array $groups,
        public readonly int $dynamic,
        public readonly int $occurrences,
        public readonly int $files,
    ) {}

    /**
     * Every catalogue, keyed the way Laravel addresses them: `*` is the JSON namespace.
     *
     * @return array<string, list<string>>
     */
    public function catalogues(): array
    {
        return ['*' => $this->json] + $this->groups;
    }

    /**
     * Distinct keys across every catalogue.
     */
    public function total(): int
    {
        return count($this->json) + array_sum(array_map('count', $this->groups));
    }
}

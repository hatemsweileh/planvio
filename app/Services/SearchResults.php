<?php

declare(strict_types=1);

namespace App\Services;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The result of one global search: hits grouped by type, in a fixed group order.
 *
 * Grouped rather than interleaved because there is no honest way to rank a wiki page
 * against a person. A relevance score across seven unrelated tables would be a number
 * invented to justify an ordering, so the service ranks within a group — where the
 * comparison means something — and leaves the groups side by side.
 *
 * @implements IteratorAggregate<int, SearchResult>
 */
final readonly class SearchResults implements Countable, IteratorAggregate
{
    /**
     * @param array<string, list<SearchResult>> $groups keyed by SearchType value
     */
    public function __construct(
        public string $term,
        public int $limitPerType,
        private array $groups = [],
    ) {}

    /**
     * @param list<SearchResult> $results
     */
    public static function fromList(string $term, int $limitPerType, array $results): self
    {
        $groups = [];

        foreach ($results as $result) {
            $groups[$result->type->value][] = $result;
        }

        return new self($term, $limitPerType, $groups);
    }

    /**
     * Non-empty groups only, in SearchType declaration order.
     *
     * @return array<string, list<SearchResult>>
     */
    public function groups(): array
    {
        $ordered = [];

        foreach (SearchType::cases() as $type) {
            $group = $this->groups[$type->value] ?? [];

            if ($group !== []) {
                $ordered[$type->value] = $group;
            }
        }

        return $ordered;
    }

    /**
     * @return list<SearchResult>
     */
    public function for(SearchType $type): array
    {
        return $this->groups[$type->value] ?? [];
    }

    public function countFor(SearchType $type): int
    {
        return count($this->groups[$type->value] ?? []);
    }

    /**
     * Whether a group came back full, meaning there are probably more hits behind it.
     */
    public function isTruncated(SearchType $type): bool
    {
        return $this->countFor($type) >= $this->limitPerType;
    }

    /**
     * Every hit, flattened in group order.
     *
     * @return list<SearchResult>
     */
    public function all(): array
    {
        $flat = [];

        foreach ($this->groups() as $group) {
            foreach ($group as $result) {
                $flat[] = $result;
            }
        }

        return $flat;
    }

    public function first(): ?SearchResult
    {
        foreach ($this->groups() as $group) {
            return $group[0] ?? null;
        }

        return null;
    }

    public function count(): int
    {
        $total = 0;

        foreach ($this->groups as $group) {
            $total += count($group);
        }

        return $total;
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->all());
    }

    /**
     * @return array{term: string, total: int, groups: array<string, list<array<string, mixed>>>}
     */
    public function toArray(): array
    {
        $groups = [];

        foreach ($this->groups() as $type => $group) {
            $groups[$type] = array_map(
                static fn (SearchResult $result): array => $result->toArray(),
                $group,
            );
        }

        return [
            'term' => $this->term,
            'total' => $this->count(),
            'groups' => $groups,
        ];
    }
}

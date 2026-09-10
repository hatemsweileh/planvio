<?php

declare(strict_types=1);

namespace App\Ai\Support;

use App\Ai\Contracts\ContextProvider;

/**
 * One {@see ContextProvider}'s contribution to a prompt: a labelled group
 * of records the acting user is authorised to read.
 *
 * The label is Planvio's own text ("Open tasks assigned to you") and sits outside the
 * wrapper. The items are workspace content and each one is wrapped separately, so a single
 * hostile description cannot swallow its neighbours.
 */
final readonly class ContextFragment
{
    /**
     * @param list<ContextItem> $items
     */
    public function __construct(
        public string $label,
        public array $items = [],
    ) {}

    public static function empty(string $label = ''): self
    {
        return new self($label, []);
    }

    /**
     * @param array<int, ContextItem> $items
     */
    public static function of(string $label, array $items): self
    {
        return new self($label, array_values(array_filter(
            $items,
            static fn (ContextItem $item): bool => ! $item->isEmpty(),
        )));
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }
}

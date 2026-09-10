<?php

declare(strict_types=1);

namespace App\Actions\Tags;

use App\Actions\Tasks\TaskChanges;

/**
 * A partial edit to a tag.
 *
 * Same shape as {@see TaskChanges} and for the same reason: clearing a
 * description and not mentioning it are different requests, and only a touched-column set
 * can tell them apart.
 */
final readonly class TagChanges
{
    /**
     * @param array<string, mixed> $attributes column => value, touched columns only
     */
    private function __construct(public array $attributes = []) {}

    public static function make(): self
    {
        return new self;
    }

    public function name(string $name): self
    {
        return $this->with('name', $name);
    }

    public function color(string $color): self
    {
        return $this->with('color', $color);
    }

    public function description(?string $description): self
    {
        return $this->with('description', $description);
    }

    public function touches(string $column): bool
    {
        return array_key_exists($column, $this->attributes);
    }

    public function isEmpty(): bool
    {
        return $this->attributes === [];
    }

    private function with(string $column, mixed $value): self
    {
        return new self([...$this->attributes, $column => $value]);
    }
}

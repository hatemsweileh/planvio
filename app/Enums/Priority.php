<?php

declare(strict_types=1);

namespace App\Enums;

enum Priority: string
{
    case None = 'none';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return __('enums.priority.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::None => 'gray',
            self::Low => 'blue',
            self::Medium => 'amber',
            self::High => 'orange',
            self::Urgent => 'red',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::None => 'heroicon-o-minus',
            self::Low => 'heroicon-o-arrow-down',
            self::Medium => 'heroicon-o-bars-2',
            self::High => 'heroicon-o-arrow-up',
            self::Urgent => 'heroicon-o-fire',
        };
    }

    /**
     * Sort weight, ascending from the least to the most urgent.
     */
    public function weight(): int
    {
        return match ($this) {
            self::None => 0,
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Urgent => 4,
        };
    }

    public function isAtLeast(self $other): bool
    {
        return $this->weight() >= $other->weight();
    }

    public static function fromWeight(int $weight): self
    {
        return match (max(0, min(4, $weight))) {
            0 => self::None,
            1 => self::Low,
            2 => self::Medium,
            3 => self::High,
            default => self::Urgent,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

enum MilestoneStatus: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Delayed = 'delayed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('enums.milestone_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Planned => 'gray',
            self::InProgress => 'brand',
            self::Completed => 'green',
            self::Delayed => 'orange',
            self::Cancelled => 'red',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Planned => 'heroicon-o-calendar',
            self::InProgress => 'heroicon-o-play-circle',
            self::Completed => 'heroicon-o-flag',
            self::Delayed => 'heroicon-o-clock',
            self::Cancelled => 'heroicon-o-x-circle',
        };
    }

    public function isOpen(): bool
    {
        return match ($this) {
            self::Planned, self::InProgress, self::Delayed => true,
            default => false,
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

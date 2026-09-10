<?php

declare(strict_types=1);

namespace App\Enums;

enum AiToolRisk: string
{
    case Read = 'read';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Destructive = 'destructive';

    public function label(): string
    {
        return __('enums.ai_tool_risk.'.$this->value);
    }

    public function description(): string
    {
        return __('enums.ai_tool_risk_description.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Read => 'gray',
            self::Low => 'blue',
            self::Medium => 'amber',
            self::High => 'orange',
            self::Destructive => 'red',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Read => 'heroicon-o-eye',
            self::Low => 'heroicon-o-shield-check',
            self::Medium => 'heroicon-o-shield-exclamation',
            self::High => 'heroicon-o-exclamation-triangle',
            self::Destructive => 'heroicon-o-trash',
        };
    }

    /**
     * Ordinal severity, 0 for read-only through 4 for destructive.
     */
    public function level(): int
    {
        return match ($this) {
            self::Read => 0,
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Destructive => 4,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->level() >= $other->level();
    }

    public function exceeds(self $other): bool
    {
        return $this->level() > $other->level();
    }

    /**
     * Levels outside 0..4 clamp to the nearest bound rather than throwing,
     * so a stale persisted level can never abort a run.
     */
    public static function fromLevel(int $level): self
    {
        return match (max(0, min(4, $level))) {
            0 => self::Read,
            1 => self::Low,
            2 => self::Medium,
            3 => self::High,
            default => self::Destructive,
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

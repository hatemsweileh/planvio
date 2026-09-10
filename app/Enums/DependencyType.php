<?php

declare(strict_types=1);

namespace App\Enums;

enum DependencyType: string
{
    case FinishToStart = 'finish_to_start';
    case Blocks = 'blocks';
    case RelatesTo = 'relates_to';

    public function label(): string
    {
        return __('enums.dependency_type.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::FinishToStart => 'blue',
            self::Blocks => 'red',
            self::RelatesTo => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::FinishToStart => 'heroicon-o-arrow-long-right',
            self::Blocks => 'heroicon-o-no-symbol',
            self::RelatesTo => 'heroicon-o-link',
        };
    }

    /**
     * Whether the dependency gates scheduling rather than merely documenting a link.
     */
    public function isBlocking(): bool
    {
        return match ($this) {
            self::FinishToStart, self::Blocks => true,
            self::RelatesTo => false,
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

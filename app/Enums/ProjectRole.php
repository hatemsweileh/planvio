<?php

declare(strict_types=1);

namespace App\Enums;

enum ProjectRole: string
{
    case Manager = 'manager';
    case Member = 'member';
    case Guest = 'guest';

    public function label(): string
    {
        return __('enums.project_role.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Manager => 'blue',
            self::Member => 'teal',
            self::Guest => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Manager => 'heroicon-o-briefcase',
            self::Member => 'heroicon-o-user',
            self::Guest => 'heroicon-o-eye',
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

<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Manager = 'manager';
    case Member = 'member';
    case Guest = 'guest';

    public function label(): string
    {
        return __('enums.workspace_role.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Owner => 'brand',
            self::Admin => 'purple',
            self::Manager => 'blue',
            self::Member => 'teal',
            self::Guest => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Owner => 'heroicon-o-key',
            self::Admin => 'heroicon-o-shield-check',
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

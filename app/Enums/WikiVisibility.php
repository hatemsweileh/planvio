<?php

declare(strict_types=1);

namespace App\Enums;

enum WikiVisibility: string
{
    case Project = 'project';
    case Workspace = 'workspace';
    case Private = 'private';

    public function label(): string
    {
        return __('enums.wiki_visibility.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Project => 'blue',
            self::Workspace => 'teal',
            self::Private => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Project => 'heroicon-o-folder',
            self::Workspace => 'heroicon-o-building-office',
            self::Private => 'heroicon-o-lock-closed',
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

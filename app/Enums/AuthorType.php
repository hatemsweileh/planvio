<?php

declare(strict_types=1);

namespace App\Enums;

enum AuthorType: string
{
    case User = 'user';
    case Ai = 'ai';

    public function label(): string
    {
        return __('enums.author_type.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::User => 'brand',
            self::Ai => 'purple',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::User => 'heroicon-o-user',
            self::Ai => 'heroicon-o-sparkles',
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

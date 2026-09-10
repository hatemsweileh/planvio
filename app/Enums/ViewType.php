<?php

declare(strict_types=1);

namespace App\Enums;

enum ViewType: string
{
    case List = 'list';
    case Board = 'board';
    case Calendar = 'calendar';
    case Timeline = 'timeline';

    public function label(): string
    {
        return __('enums.view_type.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::List => 'gray',
            self::Board => 'brand',
            self::Calendar => 'purple',
            self::Timeline => 'teal',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::List => 'heroicon-o-list-bullet',
            self::Board => 'heroicon-o-view-columns',
            self::Calendar => 'heroicon-o-calendar-days',
            self::Timeline => 'heroicon-o-chart-bar',
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

<?php

declare(strict_types=1);

namespace App\Enums;

enum ProjectType: string
{
    case General = 'general';
    case Software = 'software';
    case Marketing = 'marketing';
    case Operations = 'operations';
    case Construction = 'construction';
    case Event = 'event';
    case ProductLaunch = 'product_launch';
    case Hr = 'hr';
    case Sales = 'sales';
    case Finance = 'finance';
    case Research = 'research';
    case Creative = 'creative';
    case Client = 'client';

    public function label(): string
    {
        return __('enums.project_type.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::General => 'gray',
            self::Software => 'blue',
            self::Marketing => 'pink',
            self::Operations => 'teal',
            self::Construction => 'orange',
            self::Event => 'purple',
            self::ProductLaunch => 'brand',
            self::Hr => 'amber',
            self::Sales => 'green',
            self::Finance => 'teal',
            self::Research => 'blue',
            self::Creative => 'pink',
            self::Client => 'brand',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::General => 'heroicon-o-folder',
            self::Software => 'heroicon-o-code-bracket',
            self::Marketing => 'heroicon-o-megaphone',
            self::Operations => 'heroicon-o-cog-6-tooth',
            self::Construction => 'heroicon-o-wrench-screwdriver',
            self::Event => 'heroicon-o-calendar-days',
            self::ProductLaunch => 'heroicon-o-rocket-launch',
            self::Hr => 'heroicon-o-user-group',
            self::Sales => 'heroicon-o-presentation-chart-line',
            self::Finance => 'heroicon-o-banknotes',
            self::Research => 'heroicon-o-beaker',
            self::Creative => 'heroicon-o-paint-brush',
            self::Client => 'heroicon-o-briefcase',
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

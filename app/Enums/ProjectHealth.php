<?php

declare(strict_types=1);

namespace App\Enums;

enum ProjectHealth: string
{
    case OnTrack = 'on_track';
    case AtRisk = 'at_risk';
    case OffTrack = 'off_track';

    public function label(): string
    {
        return __('enums.project_health.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::OnTrack => 'green',
            self::AtRisk => 'amber',
            self::OffTrack => 'red',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::OnTrack => 'heroicon-o-check-circle',
            self::AtRisk => 'heroicon-o-exclamation-triangle',
            self::OffTrack => 'heroicon-o-exclamation-circle',
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

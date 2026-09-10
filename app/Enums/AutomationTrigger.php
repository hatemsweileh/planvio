<?php

declare(strict_types=1);

namespace App\Enums;

enum AutomationTrigger: string
{
    case Schedule = 'schedule';
    case Event = 'event';

    public function label(): string
    {
        return __('enums.automation_trigger.'.$this->value);
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

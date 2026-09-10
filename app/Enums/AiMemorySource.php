<?php

declare(strict_types=1);

namespace App\Enums;

enum AiMemorySource: string
{
    case Ai = 'ai';
    case User = 'user';
    case System = 'system';

    public function label(): string
    {
        return __('enums.ai_memory_source.'.$this->value);
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

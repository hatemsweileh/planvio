<?php

declare(strict_types=1);

namespace App\Enums;

enum AiDriver: string
{
    case OpenAi = 'openai';
    case Anthropic = 'anthropic';
    case OpenAiCompatible = 'openai_compatible';
    case CustomHttp = 'custom_http';

    public function label(): string
    {
        return __('enums.ai_driver.'.$this->value);
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

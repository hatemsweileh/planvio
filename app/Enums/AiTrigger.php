<?php

declare(strict_types=1);

namespace App\Enums;

enum AiTrigger: string
{
    case Chat = 'chat';
    case Automation = 'automation';
    case Api = 'api';
    case System = 'system';

    public function label(): string
    {
        return __('enums.ai_trigger.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Chat => 'blue',
            self::Automation => 'purple',
            self::Api => 'teal',
            self::System => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Chat => 'heroicon-o-chat-bubble-left-right',
            self::Automation => 'heroicon-o-bolt',
            self::Api => 'heroicon-o-code-bracket',
            self::System => 'heroicon-o-cog-6-tooth',
        };
    }

    /**
     * Whether a human is present to answer approval prompts in real time.
     */
    public function isInteractive(): bool
    {
        return $this === self::Chat;
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

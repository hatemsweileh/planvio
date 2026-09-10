<?php

declare(strict_types=1);

namespace App\Enums;

enum AiMode: string
{
    case Assistant = 'assistant';
    case Copilot = 'copilot';
    case Autonomous = 'autonomous';

    public function label(): string
    {
        return __('enums.ai_mode.'.$this->value);
    }

    public function description(): string
    {
        return __('enums.ai_mode_description.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Assistant => 'blue',
            self::Copilot => 'brand',
            self::Autonomous => 'purple',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Assistant => 'heroicon-o-chat-bubble-left-right',
            self::Copilot => 'heroicon-o-hand-raised',
            self::Autonomous => 'heroicon-o-bolt',
        };
    }

    public function canExecuteWithoutApproval(): bool
    {
        return $this === self::Autonomous;
    }

    public function requiresApproval(): bool
    {
        return ! $this->canExecuteWithoutApproval();
    }

    /**
     * Assistant mode answers and advises; it never mutates workspace data.
     */
    public function canMutate(): bool
    {
        return $this !== self::Assistant;
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

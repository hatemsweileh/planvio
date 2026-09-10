<?php

declare(strict_types=1);

namespace App\Enums;

enum AiMemoryScope: string
{
    case Workspace = 'workspace';
    case Project = 'project';
    case User = 'user';
    case Run = 'run';

    public function label(): string
    {
        return __('enums.ai_memory_scope.'.$this->value);
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

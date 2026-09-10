<?php

declare(strict_types=1);

namespace App\Enums;

enum AiRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case AwaitingApproval = 'awaiting_approval';
    case Succeeded = 'succeeded';
    case Partial = 'partial';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case LimitReached = 'limit_reached';

    public function label(): string
    {
        return __('enums.ai_run_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Queued => 'gray',
            self::Running => 'blue',
            self::AwaitingApproval => 'amber',
            self::Succeeded => 'green',
            self::Partial => 'orange',
            self::Failed => 'red',
            self::Cancelled => 'gray',
            self::LimitReached => 'purple',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Queued => 'heroicon-o-queue-list',
            self::Running => 'heroicon-o-arrow-path',
            self::AwaitingApproval => 'heroicon-o-hand-raised',
            self::Succeeded => 'heroicon-o-check-circle',
            self::Partial => 'heroicon-o-exclamation-triangle',
            self::Failed => 'heroicon-o-x-circle',
            self::Cancelled => 'heroicon-o-no-symbol',
            self::LimitReached => 'heroicon-o-stop-circle',
        };
    }

    /**
     * A terminal run never advances again; nothing may be appended to it.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Queued, self::Running, self::AwaitingApproval => false,
            self::Succeeded, self::Partial, self::Failed, self::Cancelled, self::LimitReached => true,
        };
    }

    public function isRunnable(): bool
    {
        return ! $this->isTerminal();
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

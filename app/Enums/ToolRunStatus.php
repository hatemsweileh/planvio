<?php

declare(strict_types=1);

namespace App\Enums;

enum ToolRunStatus: string
{
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return __('enums.tool_run_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::PendingApproval => 'amber',
            self::Approved => 'blue',
            self::Rejected => 'red',
            self::Succeeded => 'green',
            self::Failed => 'red',
            self::Skipped => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::PendingApproval => 'heroicon-o-hand-raised',
            self::Approved => 'heroicon-o-check-badge',
            self::Rejected => 'heroicon-o-no-symbol',
            self::Succeeded => 'heroicon-o-check-circle',
            self::Failed => 'heroicon-o-x-circle',
            self::Skipped => 'heroicon-o-forward',
        };
    }

    /**
     * The tool call has reached its final state and will not execute again.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::PendingApproval, self::Approved => false,
            self::Rejected, self::Succeeded, self::Failed, self::Skipped => true,
        };
    }

    public function isAwaitingDecision(): bool
    {
        return $this === self::PendingApproval;
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

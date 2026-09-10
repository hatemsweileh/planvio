<?php

declare(strict_types=1);

namespace App\Enums;

enum StatusCategory: string
{
    case Backlog = 'backlog';
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Review = 'review';
    case Blocked = 'blocked';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('enums.status_category.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Backlog => 'gray',
            self::Todo => 'blue',
            self::InProgress => 'brand',
            self::Review => 'purple',
            self::Blocked => 'red',
            self::Done => 'green',
            self::Cancelled => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Backlog => 'heroicon-o-inbox-stack',
            self::Todo => 'heroicon-o-clipboard-document-list',
            self::InProgress => 'heroicon-o-play-circle',
            self::Review => 'heroicon-o-eye',
            self::Blocked => 'heroicon-o-no-symbol',
            self::Done => 'heroicon-o-check-circle',
            self::Cancelled => 'heroicon-o-x-circle',
        };
    }

    public function isCompleted(): bool
    {
        return $this === self::Done;
    }

    public function isCancelled(): bool
    {
        return $this === self::Cancelled;
    }

    /**
     * Work is under way: in progress or awaiting review.
     */
    public function isActive(): bool
    {
        return match ($this) {
            self::InProgress, self::Review => true,
            default => false,
        };
    }

    /**
     * No further work is expected — completed or abandoned.
     */
    public function isClosed(): bool
    {
        return $this->isCompleted() || $this->isCancelled();
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

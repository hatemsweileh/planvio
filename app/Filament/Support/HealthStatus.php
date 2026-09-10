<?php

declare(strict_types=1);

namespace App\Filament\Support;

/**
 * The three answers a health check is allowed to give.
 *
 * `Warning` is not a softer `Failed`; it means something different and the distinction is the
 * point of having three. `Failed` is "this is broken now". `Warning` is "this will bite you, or
 * I could not establish that it works" — a queue running inline, mail going to a log file, a
 * check that could not reach the host. Collapsing the two would make the page either alarmist
 * or reassuring, and both are worse than accurate.
 *
 * Lives here rather than in `App\Enums` because it is a property of this panel's health screen,
 * not of the domain: nothing outside `app/Filament` has an opinion about it.
 */
enum HealthStatus: string
{
    case Healthy = 'healthy';

    case Warning = 'warning';

    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Healthy => __('Healthy'),
            self::Warning => __('Warning'),
            self::Failed => __('Failed'),
        };
    }

    /**
     * A colour the admin panel has registered.
     */
    public function color(): string
    {
        return match ($this) {
            self::Healthy => 'success',
            self::Warning => 'warning',
            self::Failed => 'danger',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Healthy => 'heroicon-o-check-circle',
            self::Warning => 'heroicon-o-exclamation-triangle',
            self::Failed => 'heroicon-o-x-circle',
        };
    }

    /**
     * Worst wins: one failure makes the installation unhealthy however green the rest is.
     */
    public function isWorseThan(self $other): bool
    {
        return $this->severity() > $other->severity();
    }

    private function severity(): int
    {
        return match ($this) {
            self::Healthy => 0,
            self::Warning => 1,
            self::Failed => 2,
        };
    }
}

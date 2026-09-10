<?php

declare(strict_types=1);

namespace App\Services\Install;

/**
 * The three answers a pre-flight check can give.
 *
 * Kept beside the installer rather than in `App\Enums` on purpose: nothing in the product
 * domain has an opinion about it, and the installer is the only surface that renders it.
 */
enum RequirementStatus: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';

    public function label(): string
    {
        return match ($this) {
            self::Pass => __('Ready'),
            self::Warn => __('Recommended'),
            self::Fail => __('Missing'),
        };
    }

    /**
     * The installer's own status class. It is not a Tailwind utility: the wizard ships its
     * stylesheet inline so it renders before the compiled assets are guaranteed.
     */
    public function cssClass(): string
    {
        return 'is-'.$this->value;
    }

    public function symbol(): string
    {
        return match ($this) {
            self::Pass => '✓',
            self::Warn => '!',
            self::Fail => '✗',
        };
    }

    public function isFailure(): bool
    {
        return $this === self::Fail;
    }
}

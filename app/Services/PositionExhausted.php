<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * There is no representable position strictly between two neighbours any more.
 *
 * Thrown rather than returned as a duplicate or a silently clamped value: two tasks sharing
 * a position makes a board's order non-deterministic, and a drag that quietly lands in the
 * wrong place is worse than one that fails and is retried. The caller's response is to
 * {@see TaskOrderingService::renormalise()} the column and try the move again.
 */
final class PositionExhausted extends RuntimeException
{
    public static function between(float $before, float $after): self
    {
        return new self(sprintf(
            'No position fits between %s and %s at decimal(20,10) precision. '
            .'Renormalise the status column and retry the move.',
            number_format($before, TaskOrderingService::SCALE, '.', ''),
            number_format($after, TaskOrderingService::SCALE, '.', ''),
        ));
    }

    public static function beyondBounds(float $position): self
    {
        return new self(sprintf(
            'Position %s falls outside the range this column can order reliably. '
            .'Renormalise the status column and retry the move.',
            number_format($position, TaskOrderingService::SCALE, '.', ''),
        ));
    }
}

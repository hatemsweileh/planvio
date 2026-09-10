<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Task;
use App\Models\TaskStatus;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Fractional ordering for `tasks.position` — the algorithm behind a drag on the board.
 *
 * Dropping a task between two others writes **one row**: the new position is the midpoint of
 * its neighbours', so the rest of the column is untouched. The alternative — integer ranks
 * rewritten on every move — turns one drag into an UPDATE across the column and makes two
 * people reordering the same board at once corrupt each other's order.
 *
 * ## The precision limit
 *
 * `tasks.position` is `decimal(20,10)`: ten digits before the point, ten after, so the
 * smallest distinguishable step is 1e-10. Positions also pass through PHP as IEEE-754
 * doubles, which carry about 15–17 significant digits in total — so the *usable* fractional
 * precision shrinks as the integer part grows. That is why {@see MAX_POSITION} stops well
 * short of the column's 9,999,999,999: at a billion, a double still resolves steps far finer
 * than any board needs, and the arithmetic stays exact enough to compare.
 *
 * ## When renormalisation triggers
 *
 * Each insertion into the same slot halves the gap. Starting from {@see GAP} = 1000, the gap
 * falls below 1e-10 after roughly 43 consecutive drops into the *same* place — which takes a
 * deliberate effort, and is why this is a fallback rather than a routine. Renormalisation
 * also becomes necessary when appending would push a position past {@see MAX_POSITION}, i.e.
 * after about a million appends to one column.
 *
 * Callers ask {@see needsRenormalisation()} before moving, or catch {@see PositionExhausted},
 * call {@see renormalise()}, re-read the neighbours and retry. Renormalisation rewrites the
 * whole column to clean multiples of GAP in its current order, under a transaction and a row
 * lock so a concurrent move cannot interleave with it.
 */
final class TaskOrderingService
{
    /** Decimal places `tasks.position` stores. */
    public const SCALE = 10;

    /** Spacing left between neighbours, so ~43 midpoint insertions fit in one gap. */
    public const GAP = 1000.0;

    /**
     * Below this gap there is no room for a midpoint at scale 10. Two steps, not one: a
     * midpoint needs a value strictly on each side of it.
     */
    public const MIN_GAP = 2.0e-10;

    /**
     * Deliberately short of the column's 9,999,999,999. Doubles carry ~15 significant digits;
     * past a billion the fractional half stops being reliable long before the column fills.
     */
    public const MAX_POSITION = 1_000_000_000.0;

    /** Rows rewritten per UPDATE during a renormalisation. */
    private const RENORMALISE_CHUNK = 500;

    /**
     * The position for a task dropped between two neighbours.
     *
     * Null means "no neighbour on that side": `(null, null)` is the first task in an empty
     * column, `(x, null)` is an append, `(null, y)` is a prepend.
     *
     * @throws PositionExhausted when the neighbours are too close, or the result would leave
     *                           the range the column can order reliably
     * @throws InvalidArgumentException when the neighbours are not in ascending order
     */
    public function positionBetween(?float $before, ?float $after): float
    {
        if ($before === null && $after === null) {
            return self::GAP;
        }

        if ($after === null) {
            return $this->bounded(self::round($before + self::GAP));
        }

        if ($before === null) {
            return $this->bounded(self::round($after - self::GAP));
        }

        if ($after <= $before) {
            throw new InvalidArgumentException(sprintf(
                'Neighbour positions must ascend, got before=%s after=%s. '
                .'Read them in board order before computing a midpoint.',
                number_format($before, self::SCALE, '.', ''),
                number_format($after, self::SCALE, '.', ''),
            ));
        }

        if ($after - $before < self::MIN_GAP) {
            throw PositionExhausted::between($before, $after);
        }

        $midpoint = self::round($before + ($after - $before) / 2);

        // The rounded midpoint has to be strictly inside the gap. This is the authoritative
        // check: it catches exhaustion from the column's scale and from double precision at
        // large magnitudes alike, without either being modelled separately.
        if ($midpoint <= $before || $midpoint >= $after) {
            throw PositionExhausted::between($before, $after);
        }

        return $this->bounded($midpoint);
    }

    /**
     * Whether {@see positionBetween()} would fail for these neighbours — the pre-flight check
     * a move performs so it can renormalise first rather than fail and retry.
     */
    public function needsRenormalisation(?float $before, ?float $after): bool
    {
        try {
            $this->positionBetween($before, $after);

            return false;
        } catch (PositionExhausted) {
            return true;
        }
    }

    /**
     * Append to the end of a status column.
     */
    public function nextPosition(TaskStatus|int $status): float
    {
        $highest = $this->extreme($status, highest: true);

        return $this->positionBetween($highest, null);
    }

    /**
     * Prepend to the head of a status column.
     */
    public function firstPosition(TaskStatus|int $status): float
    {
        $lowest = $this->extreme($status, highest: false);

        return $this->positionBetween(null, $lowest);
    }

    /**
     * Rewrite an entire status column to clean multiples of {@see GAP}, preserving its
     * current order.
     *
     * Ordered by `position` then `id`, matching index(project_id, status_id, position) and
     * giving a deterministic result even where two rows already share a position — the exact
     * situation that makes a renormalisation necessary.
     *
     * The read takes a row lock inside a transaction so a concurrent move cannot compute a
     * midpoint against positions that are about to be replaced. (SQLite ignores the lock and
     * serialises writes anyway; MySQL and MariaDB honour it.)
     *
     * Tenancy comes for free: a `task_statuses` row belongs to exactly one project, so
     * selecting on `status_id` cannot reach across workspaces even when nothing is bound.
     */
    public function renormalise(TaskStatus|int $status): void
    {
        $statusId = $status instanceof TaskStatus ? (int) $status->getKey() : $status;

        DB::transaction(function () use ($statusId): void {
            $ids = Task::query()
                ->where('tasks.status_id', $statusId)
                ->orderBy('tasks.position')
                ->orderBy('tasks.id')
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            if ($ids === []) {
                return;
            }

            $positions = [];

            foreach ($ids as $index => $id) {
                $positions[(int) $id] = ($index + 1) * self::GAP;
            }

            foreach (array_chunk($positions, self::RENORMALISE_CHUNK, true) as $chunk) {
                $arms = '';

                foreach ($chunk as $id => $position) {
                    // Interpolated, not bound: both halves are values this method just
                    // produced — an id read back from the database and an exact multiple of
                    // GAP — and both are re-cast here. Binding them would mean hand-building
                    // the UPDATE and giving up the scopes toBase() applies.
                    $arms .= ' when '.(int) $id.' then '.number_format((float) $position, self::SCALE, '.', '');
                }

                Task::query()
                    ->whereIn('id', array_keys($chunk))
                    ->toBase()
                    ->update(['position' => DB::raw('case id'.$arms.' else position end')]);
            }
        });
    }

    /**
     * Highest or lowest position currently in a column, or null when it is empty.
     */
    private function extreme(TaskStatus|int $status, bool $highest): ?float
    {
        $statusId = $status instanceof TaskStatus ? (int) $status->getKey() : $status;

        $value = Task::query()
            ->where('tasks.status_id', $statusId)
            ->{$highest ? 'max' : 'min'}('tasks.position');

        return $value === null ? null : (float) $value;
    }

    private function bounded(float $position): float
    {
        if (abs($position) > self::MAX_POSITION) {
            throw PositionExhausted::beyondBounds($position);
        }

        return $position;
    }

    private static function round(float $position): float
    {
        return round($position, self::SCALE);
    }
}

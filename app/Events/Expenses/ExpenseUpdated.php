<?php

declare(strict_types=1);

namespace App\Events\Expenses;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A booked cost was corrected.
 */
final class ExpenseUpdated implements ShouldDispatchAfterCommit
{
    /**
     * @param array<string, array{old: mixed, new: mixed}> $changes
     */
    public function __construct(
        public readonly Expense $expense,
        public readonly User $actor,
        public readonly array $changes = [],
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Events\Expenses;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A booked cost was withdrawn. The row is soft-deleted.
 */
final class ExpenseDeleted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Expense $expense,
        public readonly User $actor,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Events\Expenses;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A cost was booked against a project.
 */
final class ExpenseCreated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Expense $expense,
        public readonly User $actor,
    ) {}
}

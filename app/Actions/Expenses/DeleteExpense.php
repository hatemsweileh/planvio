<?php

declare(strict_types=1);

namespace App\Actions\Expenses;

use App\Events\Expenses\ExpenseDeleted;
use App\Models\Expense;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Withdraw a booked cost.
 *
 * Soft-deleted, so the figure leaves every budget total while the row stays available for
 * an audit that asks where the money went.
 */
final class DeleteExpense
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
    ) {}

    public function __invoke(Expense $expense, User $actor): Expense
    {
        if ($expense->trashed()) {
            return $expense;
        }

        DB::transaction(function () use ($expense, $actor): void {
            $expense->delete();

            $this->activity->forUser($actor)->log($expense, 'expense_deleted', [
                'project_id' => (int) $expense->project_id,
                'amount' => (string) $expense->amount,
                'currency' => (string) $expense->currency,
                'incurred_on' => $expense->incurred_on?->toDateString(),
            ]);
        });

        $this->events->dispatch(new ExpenseDeleted($expense, $actor));

        return $expense;
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Expenses;

use App\Events\Expenses\ExpenseUpdated;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use App\Services\ActivityLogger;
use DateTimeInterface;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Correct a booked cost.
 *
 * A full replacement, like the form that submits it: every mutable attribute is written
 * from the arguments. Money is re-normalised through {@see ExpenseAmount} on the way in, so
 * an edit cannot put a value into the column that the original create would have refused.
 */
final class UpdateExpense
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
    ) {}

    public function __invoke(
        Expense $expense,
        User $actor,
        string|int|float $amount,
        DateTimeInterface|string|null $incurredOn = null,
        ?string $currency = null,
        ?string $category = null,
        ?string $description = null,
    ): Expense {
        $project = $expense->relationLoaded('project') && $expense->project instanceof Project
            ? $expense->project
            : $expense->project()->firstOrFail();

        $money = ExpenseAmount::from($amount);
        $code = ExpenseAmount::currency($currency ?? (string) $expense->currency);
        $date = CreateExpense::resolveDate($project, $incurredOn ?? $expense->incurred_on);

        $expense->amount = $money->value;
        $expense->currency = $code;
        $expense->category = CreateExpense::trim($category, 64);
        $expense->description = CreateExpense::trim($description, 255);
        $expense->incurred_on = $date;

        $changes = ActivityLogger::changes($expense);

        if ($changes === []) {
            return $expense;
        }

        DB::transaction(function () use ($expense, $actor, $changes): void {
            $expense->save();

            $this->activity->forUser($actor)->log($expense, 'expense_updated', $changes);
        });

        $this->events->dispatch(new ExpenseUpdated($expense, $actor, $changes));

        return $expense->refresh();
    }
}

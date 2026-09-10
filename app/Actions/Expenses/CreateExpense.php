<?php

declare(strict_types=1);

namespace App\Actions\Expenses;

use App\Events\Expenses\ExpenseCreated;
use App\Exceptions\InvalidExpense;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use App\Services\ActivityLogger;
use DateTimeInterface;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Book a cost against a project.
 *
 * The currency defaults to the project's, then the workspace's, so an expense entered
 * without one is never silently recorded in the wrong money. It is stored per row rather
 * than inherited at read time because a project's currency can change and historical costs
 * must not change with it.
 */
final class CreateExpense
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
    ) {}

    public function __invoke(
        Project $project,
        User $actor,
        string|int|float $amount,
        DateTimeInterface|string|null $incurredOn = null,
        ?string $currency = null,
        ?string $category = null,
        ?string $description = null,
        ?User $spender = null,
    ): Expense {
        $money = ExpenseAmount::from($amount);
        $code = ExpenseAmount::currency($currency ?? self::defaultCurrency($project));
        $date = self::resolveDate($project, $incurredOn);

        $expense = DB::transaction(function () use (
            $project,
            $actor,
            $money,
            $code,
            $date,
            $category,
            $description,
            $spender,
        ): Expense {
            $expense = Expense::query()->create([
                'workspace_id' => (int) $project->workspace_id,
                'project_id' => $project->getKey(),
                'user_id' => ($spender ?? $actor)->getKey(),
                'amount' => $money->value,
                'currency' => $code,
                'category' => self::trim($category, 64),
                'description' => self::trim($description, 255),
                'incurred_on' => $date,
            ]);

            $this->activity->forUser($actor)->log($expense, 'expense_created', [
                'project_id' => (int) $project->getKey(),
                'amount' => $money->value,
                'currency' => $code,
                'category' => $expense->category,
                'incurred_on' => $date,
            ]);

            return $expense;
        });

        $this->events->dispatch(new ExpenseCreated($expense, $actor));

        return $expense;
    }

    private static function defaultCurrency(Project $project): string
    {
        $currency = $project->currency
            ?? $project->workspace?->currency
            ?? config('planvio.defaults.workspace.currency', 'USD');

        return (string) $currency;
    }

    /**
     * The project's workspace decides what "today" means: a cost booked on the workspace's
     * Monday is not future-dated because the server is still on Sunday.
     */
    public static function resolveDate(Project $project, DateTimeInterface|string|null $incurredOn): string
    {
        $timezone = (string) ($project->workspace?->timezone ?? config('planvio.defaults.workspace.timezone', 'UTC'));
        $timezone = $timezone === '' ? 'UTC' : $timezone;

        $today = Carbon::now($timezone)->startOfDay();

        if ($incurredOn === null) {
            return $today->toDateString();
        }

        $date = $incurredOn instanceof DateTimeInterface
            ? Carbon::instance($incurredOn)->setTimezone($timezone)->startOfDay()
            : Carbon::parse($incurredOn, $timezone)->startOfDay();

        if ($date->greaterThan($today)) {
            throw InvalidExpense::futureDate($date->toDateString());
        }

        return $date->toDateString();
    }

    public static function trim(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $length);
    }
}

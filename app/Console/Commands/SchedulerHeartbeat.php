<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RecordsSchedulerRun;
use Illuminate\Console\Command;

/**
 * The every-minute tick, and deliberately the cheapest thing in the schedule: two setting
 * writes and nothing else.
 *
 * It exists so System Health can *prove* cron is running. Without it, a forgotten
 * `schedule:run` entry looks exactly like a quiet week — no reminders, no pruning, no
 * recurring tasks — and the first person to notice is whoever missed a deadline. A
 * timestamp that stops advancing is unambiguous, and it is the one diagnostic that works on
 * hosting where nobody can read a log.
 */
final class SchedulerHeartbeat extends Command
{
    use RecordsSchedulerRun;

    protected $signature = 'planvio:heartbeat';

    protected $description = 'Record that the scheduler is running.';

    public function handle(): int
    {
        $this->recordSchedulerRun('');

        return self::SUCCESS;
    }
}

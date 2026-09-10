<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Support\Settings;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Leaves a trace in `settings` that a scheduled command ran.
 *
 * System Health needs to be able to say "cron is alive" and "the nightly prune last ran on
 * Tuesday" without a log file, because on the shared hosting Planvio targets the person
 * looking at that screen frequently has no shell. Two keys per command — a timestamp and a
 * short result — are enough to answer both, and are cheap enough to write on every tick.
 *
 * Writing is best-effort by design. A scheduler tick must never fail because the settings
 * table could not be written; the command's actual work has already happened by then, and a
 * missing heartbeat is a diagnostic gap rather than a fault.
 */
trait RecordsSchedulerRun
{
    /**
     * @param string $key the scheduler key, e.g. `prune` — `heartbeat` writes the root
     * @param array<string, mixed>|string|int|null $result what happened, for the health screen
     */
    protected function recordSchedulerRun(string $key, array|string|int|null $result = null): void
    {
        $settings = app(Settings::class);
        $prefix = $key === '' ? 'system.scheduler' : 'system.scheduler.'.$key;

        try {
            $settings->set($prefix.'.last_run_at', Carbon::now()->toIso8601String());

            if ($result !== null) {
                $settings->set($prefix.'.last_result', $result);
            }
        } catch (Throwable $exception) {
            // No settings table yet, or the database is momentarily unavailable. The work
            // this records has already been done; losing the note about it is not a failure.
            $this->components->warn(__('Could not record the scheduler heartbeat: :message', [
                'message' => $exception->getMessage(),
            ]));
        }
    }
}

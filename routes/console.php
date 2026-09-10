<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Laravel 13 registers the schedule here rather than in a console kernel, so this file is
| the whole of Planvio's background timetable.
|
| Deployment assumes one cron entry and nothing else (ARCHITECTURE.md §9):
|
|     * * * * * cd /path/to/planvio && php artisan schedule:run >> /dev/null 2>&1
|
| Everything below is shaped by two facts about that environment. Shared hosting gives no
| supervisor, so a task that overruns must not be joined by the next tick —
| `withoutOverlapping()` is on every task that touches more than one row. And nobody can read
| a log, so `planvio:heartbeat` writes a timestamp through App\Support\Settings every minute:
| System Health reads it, and a value that stops advancing is the only unambiguous proof that
| cron itself has stopped.
|
| Timing:
|
|   every minute  heartbeat only — deliberately the cheapest thing in the file
|   hourly        reminders; each workspace is acted on only at its own local digest hour
|   daily         recurring task generation, then metrics, then pruning
|
| The daily tasks are staggered rather than all set to midnight: three heavy jobs starting in
| the same minute on a shared host is how a nightly maintenance window turns into a nightly
| outage. Generation runs first so the tasks it creates are counted by the metrics pass that
| follows it.
|
*/

Schedule::command('planvio:heartbeat')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->description('Prove to System Health that cron is alive');

Schedule::command('planvio:send-reminders')
    ->hourly()
    ->withoutOverlapping(30)
    ->description('Due-soon reminders and overdue notices, at each workspace\'s digest hour');

Schedule::command('planvio:generate-recurring-tasks')
    ->dailyAt('00:10')
    ->withoutOverlapping(30)
    ->description('Create the tasks that recurring schedules have come due for');

Schedule::command('planvio:recalculate-metrics')
    ->dailyAt('02:20')
    ->withoutOverlapping(60)
    ->description('Refresh project progress, milestone progress and project health');

Schedule::command('planvio:prune')
    ->dailyAt('03:40')
    ->withoutOverlapping(60)
    ->description('Apply the retention windows to activity, notification, webhook and audit records');

/*
| Housekeeping for the queue itself. Both are Laravel's own commands: without them a
| database-backed queue keeps every failed job and every expired batch row forever, which on a
| small install is the table that grows fastest.
*/

Schedule::command('queue:prune-failed', ['--hours=336'])
    ->daily()
    ->description('Drop failed queue jobs older than two weeks');

Schedule::command('queue:prune-batches', ['--hours=336'])
    ->daily()
    ->description('Drop finished job batches older than two weeks');

/*
| The AI timetable.
|
| All three are registered unconditionally rather than wrapped in a check on config('ai.enabled'),
| and each one checks for itself and exits immediately when AI is off. The schedule is built
| once per tick from this file; reading a setting here to decide whether a task exists would
| mean a cached schedule silently disagreeing with a setting somebody changed since. A command
| that costs one config read when AI is off is the cheaper and more honest arrangement.
|
| Timing:
|
|   every five minutes  claim due automations. Not every minute: a claimed automation starts an
|                       agent loop that can legitimately occupy a worker for three minutes, the
|                       per-tick budget is three, and the queue cron on shared hosting rarely
|                       runs more often than this anyway. A cron automation therefore fires
|                       within five minutes of its scheduled moment, which is the resolution
|                       "every Monday at 9" actually needs. Overlapping ticks are safe by
|                       design — AutomationRunner::claim() is the guard — but withoutOverlapping
|                       keeps them from being started in the first place.
|
|   hourly              expire approval requests. The shipped time-to-live is a day, so hourly
|                       is twelve times finer than it needs to be and still costs one indexed
|                       query when there is nothing to do.
|
|   nightly             apply the AI retention windows, twenty minutes after the general prune
|                       so the two never contend for the same tables on a small host.
*/

Schedule::command('ai:run-automations')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->description('Start the AI automations whose schedule has come due');

Schedule::command('ai:expire-approvals')
    ->hourly()
    ->withoutOverlapping(30)
    ->description('Reject AI approval requests nobody answered, and close the runs waiting on them');

Schedule::command('ai:prune')
    ->dailyAt('04:00')
    ->withoutOverlapping(60)
    ->description('Apply each workspace\'s AI retention window to runs, tool runs, conversations and memories');

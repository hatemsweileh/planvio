# Cron

Planvio has no daemon. No Supervisor, no systemd, no resident PHP process. The only two
things that ever execute PHP are a web request and a cron tick.

That means every piece of work which is not the direct result of somebody clicking
something has to be picked up by cron. This page covers the **scheduler** cron job.
The queue worker is a second, separate cron job — see [QUEUE.md](QUEUE.md).

Both are required. They do different things.

---

## What cron does

One command, once a minute:

```
php /home/youraccount/planvio/artisan schedule:run
```

`schedule:run` starts, asks Laravel's scheduler which of Planvio's registered jobs are due
*at this exact minute*, runs those, and exits. A tick with nothing due takes a few hundred
milliseconds. It is not a loop and it does not stay resident.

---

## What stops working without cron

Planvio installs and runs without cron. You can sign in, create projects, move tasks,
comment, upload files and log time. Nothing in the interactive product depends on it.

These stop:

| Feature | Without cron |
|---|---|
| Due-date reminders | Never sent. Tasks still show as due in the UI |
| Overdue notices | Never sent |
| Recurring tasks | No new occurrences are generated. The recurrence rule stays configured and does nothing |
| Scheduled AI automations | Never fire. Event-triggered automations still fire, but only if the queue worker is running |
| AI usage rollup | `ai_usage_daily` stays empty, so the usage and cost screens show nothing |
| Project progress and health | Values drift from reality after an import or an interrupted job, and stay drifted |
| Cleanup and retention | Activity, notifications, webhook delivery logs, expired sessions and cache rows accumulate forever. Your `PLANVIO_*_RETENTION_DAYS` settings are ignored |
| Expired invitations | Stay listed as pending. **The token itself is still rejected** — see below |
| Email of any kind | Only if you also skipped the queue worker cron. Mail is queued, not scheduled |

**No security control depends on cron.** An expired invitation token is rejected at
redemption time by comparing `invitations.expires_at` against the current time, whether or
not the sweep has ever run. Sessions expire on `SESSION_LIFETIME` regardless. The cleanup
jobs tidy data; they do not enforce anything.

Planvio does not pretend otherwise. **Admin → System Health** turns red when the scheduler
has not been observed recently, and names the features that are consequently dormant.

---

## The cron line

Open **Cron Jobs** in cPanel.

1. Under *Add New Cron Job*, set **Common Settings** to *Once Per Minute*. The five fields
   fill in as `*` `*` `*` `*` `*`.
2. Paste the command below into **Command**, with your own home directory and the correct
   PHP binary (next section).
3. Click **Add New Cron Job**.

```
/opt/cpanel/ea-php83/root/usr/bin/php /home/youraccount/planvio/artisan schedule:run >/dev/null 2>&1
```

Three parts, all of which have to be right:

| Part | What it is |
|---|---|
| `/opt/cpanel/ea-php83/root/usr/bin/php` | The PHP binary. Must be 8.3 or 8.4 |
| `/home/youraccount/planvio/artisan` | Absolute path to `artisan` in your Planvio folder. Not `public/`, not `app/` |
| `>/dev/null 2>&1` | Throws the output away |

### Keep the output redirect

cPanel emails you the output of every cron run. A per-minute job without `>/dev/null 2>&1`
sends **1,440 emails a day**, and most hosts will suspend the account for it. Leave the
redirect in place, and clear the **Cron Email** field at the top of the Cron Jobs page as
well.

There is a way to see the output when you need it — see
[Verifying cron is running](#verifying-cron-is-running).

---

## Getting the PHP binary path right

`/usr/local/bin/php` is the account's *default* PHP, which is frequently not the version
you selected in **Select PHP Version**. Cron does not read the same configuration your
website does. Use the version-specific binary and there is nothing to guess.

| Your host | PHP 8.3 | PHP 8.4 |
|---|---|---|
| cPanel with EasyApache 4 (most servers) | `/opt/cpanel/ea-php83/root/usr/bin/php` | `/opt/cpanel/ea-php84/root/usr/bin/php` |
| CloudLinux with PHP Selector (alt-PHP) | `/opt/alt/php83/usr/bin/php` | `/opt/alt/php84/usr/bin/php` |
| Account default (whatever is selected) | `/usr/local/bin/php` | `/usr/local/bin/php` |

If you are on CloudLinux, the `/opt/cpanel/ea-php*` paths may not exist inside your CageFS
at all. See [CLOUDLINUX.md](CLOUDLINUX.md#where-the-php-binary-actually-lives).

**Do not guess.** Sign in to Planvio and open **Admin → System Information**. Planvio
reports the exact binary it is running under and prints both cron lines — scheduler and
queue worker — with that path and your real installation path already filled in. Copy and
paste them.

---

## What the scheduler runs

Every job below is registered by Planvio. You do not create, edit or schedule them
individually; `schedule:run` decides what is due.

| Job | What it does | How often |
|---|---|---|
| **AI automation dispatcher** | Picks up `ai_automations` rows with `trigger_type = schedule` whose `next_run_at` has passed, takes a database lock on each, and queues the agent run. At most `ai.automations.max_per_tick` (3) per tick | Every minute |
| **Due-date reminders** | Notifies assignees and watchers about tasks and milestones due in `planvio.reminders.due_soon_days` (3 days, then 1 day). Delivered once per workspace per day at `planvio.reminders.digest_hour` (08:00) in the *workspace's* timezone | Hourly; fires for a workspace when its local clock reaches the digest hour |
| **Overdue notices** | Notifies about tasks past `due_date` that are not in a completed status. Controlled by `planvio.reminders.send_overdue` | Same pass as due-date reminders |
| **Recurring task generation** | Creates the next occurrence for `recurring_tasks` rows that are active and whose `next_run_on` has arrived, then advances `next_run_on`, `last_run_on` and `occurrences_generated`. Stops at `max_occurrences` or `ends_on` | Hourly |
| **Project progress recalculation** | Recomputes the denormalised `projects.progress` and `milestones.progress` caches from actual task completion. These are also updated on write; this pass reconciles drift left by an import, a bulk update or a job that died halfway | Every 15 minutes, only for projects touched since the last pass |
| **Project health recalculation** | Recomputes `projects.health` (`on_track` / `at_risk` / `off_track`) from overdue tasks, milestone slippage and progress against `target_date`. **Skips any project with `health_set_manually = true`** | Daily, 03:10 |
| **AI usage rollup** | Aggregates `ai_runs` and `ai_tool_runs` into `ai_usage_daily` per date, workspace, user, provider and model. This is what the usage and cost screens read | Hourly for the current day, plus a closing pass at 00:20 for the day that just ended |
| **Invitation expiry sweep** | Deletes `invitations` rows that are past `expires_at` and were never accepted, so the pending-invitations list stays honest | Hourly |
| **Notification cleanup** | Deletes read notifications older than `planvio.retention.notification_days` (120) | Daily, 03:20 |
| **Webhook delivery cleanup** | Deletes `webhook_deliveries` rows older than `planvio.retention.webhook_delivery_days` (30) | Daily, 03:25 |
| **Activity cleanup** | Deletes `activities` rows older than `PLANVIO_ACTIVITY_RETENTION_DAYS`. **The default is `0`, which means keep forever** — this job does nothing until you set a value | Daily, 03:30 |
| **Audit log cleanup** | Deletes `audit_logs` rows older than `PLANVIO_AUDIT_RETENTION_DAYS` (730) | Daily, 03:35 |
| **Session and cache pruning** | Deletes expired rows from the `sessions` and `cache` tables. Planvio uses the database for both, so nothing else reclaims them | Daily, 03:40 |

Fixed clock times are in `APP_TIMEZONE` (`UTC` unless you changed it during installation).
Anything user-facing — reminders, digests, recurrence dates — is computed in the
workspace's own timezone, which is why those jobs run hourly rather than at a fixed hour.

### Automations that do not need cron

An `ai_automations` row with `trigger_type = event` is not scheduled. It fires from the
domain event named in its `event` column and goes straight onto the queue. Those work with
the queue worker alone. Only `trigger_type = schedule` automations depend on this cron job.

---

## Overlapping ticks

A per-minute cron will overlap eventually. A tick that hits a slow query, or a host under
load, can still be running when the next one starts. Planvio assumes this will happen.

**Laravel's scheduler** registers Planvio's longer jobs with overlap prevention, so a
second tick that finds the first still working skips that job rather than running it twice.

**AI automations go further**, because a duplicated agent run would create duplicated
tasks, comments and notifications. Before an automation runs, the dispatcher writes a
`lock_token` and a `locked_until` timestamp onto the `ai_automations` row in a conditional
update. Only the tick that wins that update proceeds. Every overlapping tick sees the lock
and moves on — **a no-op, not a duplicate run**.

The lock expires after `ai.automations.lock_ttl_seconds` (900, fifteen minutes), so a run
killed mid-flight by a resource limit does not wedge the automation permanently. Fifteen
minutes is deliberately far above `AI_MAX_RUN_SECONDS` (180): the lock should only ever be
released by completion or by a genuine crash.

Within a single agent run there is a second layer: every mutating tool computes
`idempotency_key = sha1(run_id|tool|canonical_args)` and `ai_tool_runs` carries a unique
index on `(ai_run_id, idempotency_key)`. Repeating the same call inside one run returns the
previous result instead of executing again.

---

## Verifying cron is running

### Admin → System Health

Planvio records a heartbeat in the `settings` table on every scheduler tick, under the key
`system.scheduler.last_run_at`. **Admin → System Health** reads that row and reports what
it found:

| What it says | What it means |
|---|---|
| *Scheduler: last ran 1 minute ago* | Working |
| *Scheduler: last ran 47 minutes ago* | Cron is firing far less often than once a minute, or the host is throttling it |
| *Scheduler: never observed* | The cron job has never successfully executed Planvio. Almost always a wrong path |

Planvio never reports the scheduler as healthy because a cron job exists in cPanel. It
reports what it has actually observed. A green light means Planvio's own code ran and wrote
the heartbeat.

Give it two minutes after adding the cron job before you judge it.

### Seeing the output, without SSH

When the heartbeat is not moving, you need the error. Temporarily change the redirect at
the end of your cron command from this:

```
>/dev/null 2>&1
```

to this:

```
>> /home/youraccount/planvio/storage/logs/cron.log 2>&1
```

Wait two minutes, then open `storage/logs/cron.log` in cPanel's File Manager (right-click →
**Edit** or **View**). The first line usually tells you everything:

| Line in `cron.log` | Cause |
|---|---|
| `No such file or directory` | Wrong PHP binary path |
| `Could not open input file: /home/.../artisan` | Wrong path to `artisan` |
| `Parse error`, or a message naming a PHP version | Cron is using an older PHP than your website |
| `Class "PDO" not found`, or any missing extension | The CLI PHP has a different extension set than the web PHP |
| `SQLSTATE[HY000] [1045] Access denied` | Cron cannot read `.env`, or the database credentials are wrong |
| Nothing at all, file never created | Cron is not running the command *at all* — see Troubleshooting |

**Put the `>/dev/null 2>&1` back when you are done**, and delete `cron.log`. Left in place
it grows without limit and, on a per-minute job, will fill your disk quota.

### Reading it in the database

If you would rather check directly: open **phpMyAdmin**, select your Planvio database, open
the `settings` table and find the row where `key` is `system.scheduler.last_run_at`. The
`value` column holds the UTC timestamp of the last completed tick.

---

## Troubleshooting

**The cron job exists but nothing ever happens**

The path is wrong. Nine times out of ten it is the PHP binary. Copy the exact command from
**Admin → System Information** rather than typing one. If that still produces nothing,
capture the output with the `cron.log` trick above.

**Wrong PHP binary**

Symptom: `cron.log` says *No such file or directory*, or Planvio reports a version or
extension error that the website does not. Cron does not inherit the PHP version you chose
in **Select PHP Version** — it uses whatever binary you named. Use a version-specific path
from the table above, never bare `php`.

A subtler variant: the binary exists and is the right version, but its CLI `php.ini` has a
different extension set than the web SAPI. On cPanel the CLI and web PHP normally share
extensions; on some hosts they do not. `cron.log` names the missing class or function.

**Wrong artisan path**

Symptom: `Could not open input file`. `artisan` sits in the Planvio root, next to `app/`
and `vendor/` — not in `public/`. If you followed [CPANEL.md](CPANEL.md) the path is
`/home/youraccount/planvio/artisan`. If you used the `public_html` fallback it is
`/home/youraccount/public_html/artisan`. Confirm it in File Manager before you argue with
the cron job.

**Cron is disabled or throttled by the host**

Some shared hosts refuse per-minute cron and silently enforce a minimum interval — five,
ten or fifteen minutes. Some disable cron on entry-level plans entirely. Symptom: the
heartbeat moves, but in jumps far larger than a minute, or `cron.log` is never created
despite a correct command.

Planvio degrades rather than breaks. On a five-minute minimum:

- Scheduled AI automations start up to five minutes late. Harmless.
- Hourly and daily jobs are unaffected — they only need *one* tick inside their window.
- Reminders arrive within five minutes of the digest hour rather than on it.

Set the cron to the shortest interval your host permits and accept the latency. If cron is
unavailable altogether, ask your host to enable it; there is no substitute inside Planvio.

**Overlapping runs**

Symptom: duplicated notifications, or an automation that appears to have run twice. The
locking described above exists to prevent exactly this, so treat it as a bug and check
**Admin → AI → Runs**: two `ai_runs` rows with the same `ai_automation_id` and overlapping
`started_at` / `finished_at` is evidence worth reporting. Overlap on its own — two
`schedule:run` processes alive at the same moment — is normal and harmless.

**Cron runs, Planvio does nothing, no errors**

Check, in this order:

1. `storage/app/planvio-installed.lock` exists. If installation never completed, the
   scheduler has nothing registered to run.
2. Maintenance mode is engaged (**Admin → Settings**). Scheduled work is suspended while it
   is on.
3. `AI_ENABLED=false` in `.env`, if the only thing missing is AI automations. That is the
   default and it is deliberate — see
   [Step 10 of CPANEL.md](CPANEL.md#step-10--configure-ai-optional).
4. The queue worker cron is missing. The scheduler *queues* reminders and notifications; it
   does not send them. Without the worker they sit in the `jobs` table forever. See
   [QUEUE.md](QUEUE.md).

**Cron floods your inbox**

You dropped `>/dev/null 2>&1`. Add it back and clear the **Cron Email** field on the Cron
Jobs page.

---

## Limitations

- **One-minute granularity is the floor.** Nothing in Planvio can be scheduled more
  precisely than the cron interval your host allows. An automation set to run every minute
  runs at most once per cron tick.
- **Scheduled times are approximate.** A job scheduled for 03:10 runs on the first tick at
  or after 03:10, and only if cron fires then. If the host skipped that minute, a job bound
  to a fixed minute waits until the next day. Planvio's daily jobs are spaced five minutes
  apart partly for this reason.
- **The scheduler queues, it does not deliver.** Every notification and email the scheduler
  produces is handed to the queue. Latency you observe is the *sum* of the scheduler
  interval and the queue worker interval — typically up to six minutes with the recommended
  crons.
- **There is no in-app way to run a scheduled job on demand.** Admin shows you when each job
  last ran and what it did. Running one immediately requires a command line, which this
  deployment target does not assume you have.
- **The heartbeat proves the scheduler started, not that every job succeeded.** Job-level
  failures are recorded separately and surface in **Admin → System Health** and in the daily
  log file under `storage/logs/`.
- **`schedule:list` is not exposed in the interface.** If you need it, add a one-off cron
  job running `artisan schedule:list` with output redirected to a file, read the file in
  File Manager, then delete the cron job.

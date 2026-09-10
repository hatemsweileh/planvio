# The queue

Planvio pushes slow work onto a queue so the person who triggered it does not wait for it.
Sending twenty invitation emails, delivering a webhook to an endpoint that takes four
seconds to answer, running an AI agent loop — none of that belongs inside a web request.

The queue is stored in your MySQL database and drained by a short-lived worker that cron
starts every five minutes. There is no daemon.

This page covers the queue worker cron job. The scheduler is a separate cron job — see
[CRON.md](CRON.md). Both are required.

---

## Why the database queue and not Redis

Redis is the obvious choice for a Laravel queue, and Planvio deliberately does not use it.

| | |
|---|---|
| **There is no Redis** | Shared cPanel accounts do not ship Redis, and you cannot install it without root. Requiring it would exclude the hosting Planvio is built for |
| **There is no daemon** | Redis-backed queues assume a worker process that stays alive. Shared hosts kill long-running processes, and you have no Supervisor or systemd to restart one |
| **You already have MySQL** | The database is the one durable, always-available service on every cPanel account. Using it costs one extra table |
| **Failures survive a restart** | A job in the `jobs` table survives the worker being killed mid-flight, a PHP upgrade, and a reboot of the whole server. Nothing is held only in memory |

The cost is honest: polling a MySQL table is slower than Redis, and throughput is bounded.
For a project-management tool serving a team — hundreds of jobs a day, not hundreds a
second — that ceiling is nowhere near.

`.env` reflects this and should be left alone:

```
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
```

Three tables do the work: `jobs` holds pending and reserved jobs, `job_batches` holds batch
progress, `failed_jobs` holds anything that exhausted its attempts.

---

## The worker cron line

Open **Cron Jobs** in cPanel.

1. Set **Common Settings** to *Every Five Minutes*. The fields fill in as `*/5` `*` `*` `*` `*`.
2. Paste the command, with your own home directory and the correct PHP binary.
3. Click **Add New Cron Job**.

```
/opt/cpanel/ea-php83/root/usr/bin/php /home/youraccount/planvio/artisan queue:work database --queue=default,ai --stop-when-empty --max-time=280 --tries=3 >/dev/null 2>&1
```

The PHP binary paths are the same ones listed in
[CRON.md](CRON.md#getting-the-php-binary-path-right), and **Admin → System Information**
prints this exact line with your real paths filled in.

### If you have enabled AI

Add the `ai` queue explicitly, otherwise AI jobs are never picked up:

```
/opt/cpanel/ea-php83/root/usr/bin/php /home/youraccount/planvio/artisan queue:work database --queue=default,ai --stop-when-empty --max-time=280 --tries=3 >/dev/null 2>&1
```

Order matters. `--queue=default,ai` means strict priority: the worker drains `default`
completely before it looks at `ai`. A password reset never waits behind a three-minute
agent run. The shorter line above works only while `AI_ENABLED=false`, which is the
default.

### What each flag does

| Flag | Why it is there |
|---|---|
| `database` | The connection name. Stated explicitly so the worker does not depend on `QUEUE_CONNECTION` being what you expect |
| `--stop-when-empty` | The worker exits as soon as the queue is empty instead of polling forever. **This is the flag that makes the whole design work on shared hosting** |
| `--max-time=280` | Hard ceiling in seconds. Even with jobs still waiting, the worker exits after 280 seconds — 20 seconds before the next cron tick |
| `--tries=3` | Default attempt count for jobs that do not declare their own. On the third failure the job moves to `failed_jobs` |

### Why `--stop-when-empty` is critical

Without it, `queue:work` runs forever. On a shared host that produces exactly the failure
modes Planvio is designed to avoid:

- The process holds an LVE entry-process or NPROC slot indefinitely, and on CloudLinux
  every subsequent cron tick starts *another* one. Within an hour you have twelve workers
  and a `508 Resource Limit Reached` on your website. See
  [CLOUDLINUX.md](CLOUDLINUX.md).
- Hosts that reap long-running processes kill it at an arbitrary point, which on a
  database queue leaves a job *reserved* rather than failed — invisible until
  `retry_after` elapses.
- A resident worker holds the code it booted with. After you upgrade Planvio it keeps
  running the old release, and you have no command line to send it a restart signal.

With `--stop-when-empty`, the worst case is a process that lives 280 seconds and exits
cleanly. The typical case is a process that lives under a second and exits.

---

## What Planvio queues

| Work | Queue | Notes |
|---|---|---|
| **Outbound email** | `default` | Invitations, password resets, email verification, reminder digests, test emails. Every message Planvio sends goes through the queue — nothing is sent inside the request |
| **Notifications** | `default` | Fan-out to assignees, watchers and mentioned users; database rows plus their email copies, honouring each user's `notification_preferences` |
| **Webhook deliveries** | `default` | One job per `webhooks` row per event. Writes a `webhook_deliveries` row with the response status, increments `webhooks.failure_count` on failure |
| **AI runs** | `ai` | One job per `ai_runs` row: chat turns that call tools, and every scheduled or event-triggered automation. Only exists when `AI_ENABLED=true` |
| **Report generation** | `default` | Project, time and budget reports that are too large to build inside a request. The file lands on the private disk and the requester is notified when it is ready |
| **CSV import** | `default` | Task and project imports, processed in chunks so a large file does not exhaust memory. Each chunk is its own job |

Everything else — creating a task, moving a card, posting a comment — happens
synchronously in the request. The queue is for work that is slow, external or fan-out
shaped.

---

## The `ai` queue

AI jobs run on their own named queue, `ai`, configured in `config/ai.php`:

```php
'queue' => [
    'connection' => env('AI_QUEUE_CONNECTION', 'database'),
    'name' => env('AI_QUEUE', 'ai'),
    'tries' => 2,
    'backoff' => [10, 60],
],
```

They are separated from mail for one reason: **duration**.

A mail job takes a second or two. An AI run is an agent loop — provider call, tool call,
provider call again — bounded by `AI_MAX_RUN_SECONDS` (180 by default) and by
`AI_MAX_TOOL_CALLS` (25). A single run can legitimately occupy the worker for three
minutes. On one undifferentiated queue, one AI run would delay every password reset behind
it by three minutes, and a burst of automations would stall mail for the rest of the cron
window.

With `--queue=default,ai`, mail always wins. With a second cron job (below), AI runs get
their own process and never compete at all.

AI jobs also retry differently: two attempts rather than three, with a backoff of 10
seconds then 60. Provider outages are usually brief, and a failing agent run that keeps
retrying burns real money on tokens. The job-level setting overrides the `--tries=3` on the
command line.

Nothing about the `ai` queue matters while AI is off. With `AI_ENABLED=false` — the default
— no AI job is ever dispatched, the `ai` queue stays empty, and the rest of Planvio is
unaffected.

### A dedicated AI worker (optional)

If you use automations heavily, give AI its own five-minute cron so a long run cannot
consume the shared window:

```
/opt/cpanel/ea-php83/root/usr/bin/php /home/youraccount/planvio/artisan queue:work database --queue=ai --stop-when-empty --max-time=280 >/dev/null 2>&1
```

Then return the first worker to `--queue=default`. Two concurrent workers means two
concurrent PHP processes; check your entry-process and NPROC limits first
([CLOUDLINUX.md](CLOUDLINUX.md#lve-limits-and-what-they-do-to-a-php-application)).

---

## Failed jobs

A job that throws is retried until it runs out of attempts. Then it is removed from `jobs`,
written to `failed_jobs` with its full payload and exception, and never retried
automatically.

Nothing prunes `failed_jobs`. Failures stay until you deal with them, on purpose — a
silently discarded invitation email is worse than a growing table.

### Inspecting and retrying from Admin

**Admin → System → Queue** shows:

| | |
|---|---|
| Pending jobs | How many rows are in `jobs` right now |
| Oldest pending job | Age of the oldest. Anything over ten minutes means the worker is not running |
| Last worker finish | When a worker last completed, read from the `settings` row `system.queue.last_worker_at` |
| Failed jobs | Every `failed_jobs` row: job class, queue, failure time, and the exception with its first stack frame |

Per failed job you can:

- **Retry** — pushes the job back onto its original queue with a fresh attempt count.
- **Retry all** — the same for every failed job of that class.
- **Delete** — discards it permanently.

**Retry does not run the job immediately.** It queues it. Nothing executes until the next
worker cron tick, so allow up to five minutes.

Read the exception before retrying. A job that failed because SMTP credentials are wrong
will fail again in exactly the same way; fix the cause in **Admin → Settings → Email**
first, then retry.

If you prefer to look directly: the `failed_jobs` table in phpMyAdmin has `uuid`,
`connection`, `queue`, `payload`, `exception` and `failed_at`.

---

## Tuning

### `--max-time` against the cron interval

This is the one rule that must hold:

> `--max-time` must be at least 20 seconds shorter than the cron interval.

| Cron interval | Interval in seconds | Use |
|---|---|---|
| Every 5 minutes (recommended) | 300 | `--max-time=280` |
| Every 10 minutes | 600 | `--max-time=580` |
| Every 15 minutes | 900 | `--max-time=880` |
| Every 2 minutes | 120 | `--max-time=100` |

Break the rule and workers overlap: the previous one is still working when the next starts,
you accumulate processes, and on CloudLinux you hit an entry-process limit.

### `--max-time` against `max_execution_time`

`max_execution_time` in **Select PHP Version → Options** applies to the web SAPI. PHP's CLI
SAPI normally runs with `max_execution_time = 0` — no limit — which is why `--max-time=280`
works despite CPANEL.md setting the web limit to 120.

Some hosts override that for CLI, and CloudLinux can cap the wall-clock time of a cron
process independently of PHP. If your worker consistently dies partway through, take the
lower of the two limits and subtract 20 seconds. To confirm what CLI PHP thinks its limit
is, add a one-off cron job that writes the value to a file you can read in File Manager:

```
/opt/cpanel/ea-php83/root/usr/bin/php -r "echo ini_get('max_execution_time');" > /home/youraccount/planvio/storage/logs/cli-limit.txt 2>&1
```

Delete the cron job and the file afterwards.

You can raise the CLI memory limit the same way, without touching your website's:

```
/opt/cpanel/ea-php83/root/usr/bin/php -d memory_limit=256M /home/youraccount/planvio/artisan queue:work database --queue=default,ai --stop-when-empty --max-time=280 --tries=3 >/dev/null 2>&1
```

### `retry_after` against long jobs

`config/queue.php` sets the database connection's `retry_after` to 90 seconds, adjustable
with `DB_QUEUE_RETRY_AFTER`. That is how long a reserved job may be held before the queue
assumes the worker died and hands it to another one.

**It must exceed the longest a job can legitimately take.** The default 90 seconds is
comfortable for mail, notifications, webhooks and imports. It is *below*
`AI_MAX_RUN_SECONDS` (180), so if you enable AI, raise it:

```
DB_QUEUE_RETRY_AFTER=600
```

Leave it at 90 with AI enabled and a long agent run can be picked up a second time while
the first is still going. Within a single run the idempotency keys on `ai_tool_runs` stop
duplicate tool execution, but a second *run* is a second run — it costs tokens and can
produce a second set of comments and notifications.

Rule of thumb: `DB_QUEUE_RETRY_AFTER` > `AI_MAX_RUN_SECONDS` > the time a provider call can
take (`AI_TIMEOUT`, 60, times `max_retries` plus backoff).

### If jobs pile up

**Admin → System → Queue** shows a growing pending count and an oldest-job age climbing
past ten minutes. Work through this in order:

1. **Is the worker cron running at all?** Check *Last worker finish*. If it says never,
   the cron command is wrong — capture its output with the `cron.log` trick in
   [CRON.md](CRON.md#seeing-the-output-without-ssh).
2. **Is one job class dominating?** The pending list groups by class. A thousand queued
   notification jobs after a bulk update is normal and will drain over a few cron windows.
3. **Is mail blocking?** An unreachable SMTP host makes every mail job sit for the full
   connection timeout. Fifty jobs at 30 seconds each is 25 minutes of worker time —
   nothing else moves. Send a test message from **Admin → Settings → Email** and fix the
   host or port before blaming the queue.
4. **Shorten the interval.** Every two minutes with `--max-time=100` gives roughly the same
   throughput as every five with `--max-time=280`, but starts sooner. Only useful when
   latency, not throughput, is the complaint.
5. **Add a second worker on a different queue.** Two crons — one `--queue=default`, one
   `--queue=ai` — double throughput without either process running longer. Check your
   entry-process limit first.
6. **Reduce the work.** Fewer active webhooks, fewer automations, a smaller import chunk.
   The queue is the symptom; the volume is the cause.

Do not respond to a backlog by removing `--stop-when-empty`.

---

## Never rely on a permanently running worker

Do not do any of these, whatever a generic Laravel deployment guide tells you:

- `queue:work` without `--stop-when-empty`
- `queue:listen`
- `nohup ... &`, `screen`, `tmux`, or anything else that detaches a process
- A cPanel "Application Manager" or Passenger entry that keeps a worker alive
- Supervisor or systemd, which you do not have access to anyway

Every one of them breaks in the same three ways: the host reaps the process without telling
you, the process holds a resource slot your website needs, and after an upgrade it keeps
serving the previous release's code with no way for you to restart it.

Planvio's queue is designed around processes that start, do a bounded amount of work, and
exit. Keep it that way.

---

## Limitations

- **Latency is bounded by the cron interval, not by load.** With the recommended crons a
  notification created by the scheduler can take up to six minutes to arrive: up to one
  minute for the scheduler tick, up to five for the worker. A notification created by a
  user action takes up to five. This is inherent to cron-driven queues and cannot be tuned
  below your host's minimum cron interval.
- **Throughput is bounded by the worker window.** One worker processes what fits in 280
  seconds. A backlog larger than that drains across several cron windows rather than all at
  once.
- **There is no live progress.** Long jobs — a large CSV import, a big report — report
  completion, not percentage. Nothing streams progress back to the browser without a
  persistent connection Planvio does not open.
- **Retrying from Admin queues; it does not execute.** Expect up to a five-minute delay
  before a retried job actually runs.
- **`failed_jobs` is never pruned automatically.** Clear it from **Admin → System → Queue**
  when you have dealt with the failures. There is no retention setting for it.
- **A killed worker leaves reserved jobs.** If the host kills a worker mid-job, that job
  stays reserved until `retry_after` elapses, then runs again. Set `DB_QUEUE_RETRY_AFTER`
  correctly and this self-heals; set it too low and the job can run twice.
- **Batch progress is stored but not surfaced.** `job_batches` is populated for chunked
  imports; Admin reports the import's overall state, not per-chunk detail.

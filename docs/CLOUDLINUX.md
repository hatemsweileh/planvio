# CloudLinux

Most shared cPanel hosting runs CloudLinux. If your host advertises "no noisy neighbours",
per-account resource limits, or a **PHP Selector** in cPanel, you are on it.

CloudLinux does not change how you install Planvio — follow [CPANEL.md](CPANEL.md). It
changes what happens when the account is under load, where the PHP binary lives, and how
you read the error when something stops working.

---

## What CloudLinux is

CloudLinux is a modified Linux kernel that puts every hosting account in its own cage. Four
components matter to Planvio.

| Component | What it does |
|---|---|
| **LVE** (Lightweight Virtual Environment) | Per-account limits on CPU, memory, processes and disk I/O, enforced by the kernel. Your account cannot exceed them, and no other account on the server can starve you |
| **CageFS** | A virtualised file system. Your account sees its own home directory, a private `/tmp`, and a curated skeleton of `/usr` and `/etc` — and nothing else on the server |
| **PHP Selector** | Lets you pick a PHP version and toggle extensions per account, from CloudLinux's own "alt-PHP" builds rather than the server's EasyApache PHP |
| **MySQL Governor** | Per-account throttling of MySQL CPU and I/O, on top of the LVE limits |

The practical consequence: **your account has a hard ceiling, and hitting it produces
specific, recognisable symptoms.** Planvio is built to stay well under that ceiling. This
page explains where it is and how to tell which one you touched.

---

## LVE limits and what they do to a PHP application

| Limit | Unit | Typical shared value | What it caps |
|---|---|---|---|
| **SPEED** | % of a CPU core | 100–200% | How much CPU your account gets. 100% is one core |
| **PMEM** | Physical memory | 512 MB – 2 GB | Total RAM across every process in the account, at once |
| **VMEM** | Virtual memory | Often `0` (off) | Address space. Disabled on most modern CloudLinux installs |
| **EP** (entry processes) | Count | 20–40 | Concurrent *entries* into your account: each PHP request being served, plus each cron job, plus each SSH session |
| **NPROC** | Count | 30–100 | Total processes alive in the account |
| **IO** | KB/s | 1–4 MB/s | Disk read and write throughput |
| **IOPS** | Operations/s | 1024–4096 | Disk operations per second |

Two of these behave differently from everything else you may be used to.

**EP is concurrency, not requests per second.** A page that takes 200 ms uses one entry
process for 200 ms. Twenty visitors loading pages at once is fine. Twenty visitors each
waiting on a four-second request is not. **A cron job entering your account is also an
entry process** — which is precisely why Planvio's queue worker exits instead of living
forever.

**PMEM is the whole account, not one process.** PHP's own `memory_limit` (256 MB in
[CPANEL.md](CPANEL.md#step-1--set-the-php-version-and-extensions)) caps a single process.
PMEM caps all of them added together. Eight concurrent requests at 64 MB each is 512 MB of
PMEM regardless of what `memory_limit` says.

---

## Which symptom maps to which limit

| What you see | Limit | Why |
|---|---|---|
| **`508 Resource Limit Reached`** in the browser | **EP** | New requests are refused because too many are already in flight. Requests already running finish normally. Almost always EP; occasionally NPROC |
| Page dies mid-render. Blank white screen or a bare 500, **and nothing in `storage/logs/`** | **PMEM** | The kernel killed the process. PHP never got to write a log line. The absence of a log entry is the diagnostic |
| `Allowed memory size of N bytes exhausted` **in `storage/logs/`** | Not LVE | PHP's own `memory_limit` stopped it first. Raise `memory_limit` in **Select PHP Version → Options** |
| Everything works, everything is slow. No errors anywhere | **SPEED** | You are being throttled, not refused. Requests still complete, just slower |
| Uploads crawl. Attachment downloads stall. Extracting the release ZIP takes many minutes | **IO / IOPS** | Disk throughput throttling. Large files are the first thing to suffer |
| Cron jobs silently do not start. `cron.log` is never created despite a correct command | **NPROC** or **EP** | No process slot was available at that minute. Cron does not retry |
| Queries slow only when the site is busy | **MySQL Governor** | Database throttling, separate from the LVE limits above |
| Queue drains far less per tick than it used to | **SPEED** or **IO** | The worker still gets its 280 seconds, it just does less inside them |

Two things follow from this table and are worth internalising:

- **A missing log entry is information.** PHP writing "out of memory" to
  `storage/logs/laravel-YYYY-MM-DD.log` means PHP hit its own limit. A process that
  vanishes with no log line at all was killed from outside — that is PMEM.
- **508 is not a Planvio error.** It is produced by the web server before your request
  reaches PHP. Planvio's logs will show nothing, because Planvio never ran.

---

## PHP Selector: version and extensions

On CloudLinux, cPanel's **Select PHP Version** page is PHP Selector. It has three tabs.

### 1. Version

Choose **8.3** or **8.4**. Planvio requires `8.3.0` as a minimum
(`planvio.install.min_php`) and the installer refuses to proceed below it.

If the only option offered is **native**, PHP Selector is disabled for your account and you
are on the server's EasyApache PHP. Set the version in **MultiPHP Manager** instead, and
use the `ea-php` binary paths further down this page.

### 2. Extensions

Planvio's installer checks these and names any that are absent. Tick all twelve:

```
bcmath   ctype    curl     fileinfo
gd       json     mbstring openssl
pdo      pdo_mysql  tokenizer  xml
```

That list is `planvio.install.required_extensions` in `config/planvio.php`, verbatim. It is
the authority — if the installer's Requirements step is green, you have what Planvio needs.

Recommended but not required (`planvio.install.optional_extensions`):

```
intl   zip   exif   sodium
```

| Extension | What you lose without it |
|---|---|
| `intl` | Locale-correct date, number and currency formatting. Planvio falls back to plain formatting |
| `zip` | Nothing during installation — cPanel's File Manager extracts the release ZIP, not PHP. Archive-producing exports use it where present |
| `exif` | Orientation correction on uploaded photos. Images still upload, some may appear rotated |
| `sodium` | Nothing. Laravel's encryption uses OpenSSL. `sodium` is listed for forward compatibility |

Notes specific to alt-PHP:

- Extension names in PHP Selector do not always match the `extension_loaded()` name. `pdo`
  and `pdo_mysql` may appear as a single **pdo** entry, or alongside **mysqlnd**. Tick
  everything that looks relevant and let the installer's Requirements step confirm.
- Some extensions are mutually exclusive in the list (for example an APC/APCu pairing).
  Planvio requires neither.
- Ticking an extension applies immediately to the web SAPI. **Cron may need a minute to
  pick it up**, and on some builds the CLI extension set is maintained separately — see
  Troubleshooting in [CRON.md](CRON.md#troubleshooting).

### 3. Options

```
memory_limit          = 256M    (512M if you plan to use AI heavily)
max_execution_time    = 120
upload_max_filesize   = 32M
post_max_size         = 32M
```

`memory_limit` here is per process. Keep it comfortably below your PMEM limit divided by
the number of requests you expect to be in flight at once. On a 1 GB PMEM account,
`memory_limit = 256M` and four concurrent PHP requests is already the whole allowance.

`upload_max_filesize` must be at least `PLANVIO_MAX_UPLOAD_KB` (20480 KB = 20 MB by
default). `post_max_size` must be at least as large as `upload_max_filesize`.

---

## Where the PHP binary actually lives

This is the single most common CloudLinux problem, and it only shows up in cron.

Cron runs **inside CageFS**, so it sees the same virtualised file system your PHP scripts
do — not the real server file system. A path that exists on the host may simply not exist
for you.

| PHP Selector in use? | Binary to use in cron |
|---|---|
| Yes (alt-PHP) | `/opt/alt/php83/usr/bin/php` or `/opt/alt/php84/usr/bin/php` |
| No (EasyApache native) | `/opt/cpanel/ea-php83/root/usr/bin/php` or `/opt/cpanel/ea-php84/root/usr/bin/php` |
| Either | `/usr/local/bin/php` — inside CageFS this is remapped to whichever version you selected |

**`/opt/cpanel/ea-php83/root/usr/bin/php` frequently does not exist inside CageFS when PHP
Selector is active.** A cron job using it fails with *No such file or directory* and,
because the output is redirected to `/dev/null`, fails silently. This is the usual reason a
correct-looking cron job does nothing at all.

### What else is and is not visible

| Path | Inside CageFS |
|---|---|
| `/home/youraccount` | Visible. This is where Planvio lives |
| Other accounts' home directories | Not visible |
| `/tmp` | Visible, but **private to your account** — it is a per-user directory presented as `/tmp`. PHP's upload staging happens here, and it counts against your disk quota |
| `/opt/alt/php83`, `/opt/alt/php84` | Visible when PHP Selector is enabled |
| `/opt/cpanel/ea-php*` | Usually not visible |
| `/usr/bin`, `/bin`, `/etc` | A restricted skeleton, not the real thing |
| `/proc`, `/sys` | Heavily filtered. Tools that read them report your LVE's figures, not the server's |

Because `/proc` is filtered, anything reporting "server memory" or "CPU cores" inside your
account is reporting your cage, not the machine. Planvio's **Admin → System Information**
reports the PHP binary path, version, `memory_limit` and `max_execution_time` as PHP
actually sees them — which is what you want — and generates both cron lines with the
correct binary already filled in. Use it rather than guessing from this table.

---

## Why Planvio fits inside LVE limits

Planvio's deployment invariants exist for this environment specifically. Each one maps to a
limit.

| Design decision | Limit it protects |
|---|---|
| **Queue workers are short-lived.** `--stop-when-empty` and `--max-time=280` mean the worker exits rather than living forever ([QUEUE.md](QUEUE.md)) | EP, NPROC. A daemonised worker permanently occupies a slot; overlapping ones accumulate until the site returns 508 |
| **No daemons of any kind.** No Supervisor, no systemd, no resident process | EP, NPROC, PMEM |
| **AI runs are bounded.** `AI_MAX_RUN_SECONDS` (180), `AI_MAX_TOOL_CALLS` (25), `AI_MAX_CONTEXT_TOKENS` (24000), `max_tool_result_chars` (6000) | PMEM, SPEED, EP. An unbounded agent loop is a process that never ends and a prompt that grows without limit |
| **Every list is paginated.** 50 rows per list, 25 per board column, 30 activity entries, 20 search results, 200 hard maximum on the API (`planvio.pagination`) | PMEM, IO, MySQL Governor. No screen can load a project's entire task history into memory |
| **Attachments are streamed, not buffered.** Uploads go to `storage/app/private/` and are served by an authorising controller that streams them | PMEM. A 20 MB download costs a buffer, not 20 MB of RAM |
| **`vendor/` and `public/build/` ship inside the release ZIP** | IO, IOPS, PMEM, SPEED. `composer install` on a shared account is thousands of file operations and hundreds of megabytes of resolution; `npm ci` is worse. Planvio never runs either on your server |
| **Database queue, database cache, database sessions** | NPROC. No Redis, Memcached or any other service to keep alive |
| **AI is off by default** (`AI_ENABLED=false`) | Everything. The most resource-hungry subsystem does not run until you deliberately turn it on, and turning it off again affects nothing else |

The one thing that genuinely costs I/O is the initial extraction of the release ZIP —
roughly 15,000 files. Do it with cPanel's File Manager **Extract**, which runs outside your
PHP limits, and expect a few minutes on a throttled account.

---

## Tuning Planvio against your limits

### The queue worker

The rule from [QUEUE.md](QUEUE.md#tuning) holds on CloudLinux too, and matters more:

> `--max-time` must be at least 20 seconds shorter than the cron interval.

Break it and workers overlap. On a normal Linux host that wastes CPU. On CloudLinux it
accumulates entry processes until your website starts returning 508 to real visitors.

If your account is tight on EP, prefer **one** worker cron over two. A single
`--queue=default,ai` worker uses one process slot; splitting mail and AI onto separate
crons uses two.

### `AI_MAX_RUN_SECONDS`

An AI run occupies one PHP process for its entire duration. Three ceilings have to line up:

```
AI_MAX_RUN_SECONDS  <  queue worker --max-time  <  cron interval
AI_MAX_RUN_SECONDS  <  DB_QUEUE_RETRY_AFTER
```

The shipped defaults satisfy the first (180 < 280 < 300) and **violate the second**:
`DB_QUEUE_RETRY_AFTER` defaults to 90. If you enable AI, set it explicitly in `.env`:

```
DB_QUEUE_RETRY_AFTER=600
```

Otherwise a long agent run can be handed to a second worker while the first is still going.
See [QUEUE.md](QUEUE.md#retry_after-against-long-jobs).

Suggested starting points by account size:

| Your EP limit | `AI_MAX_RUN_SECONDS` | Worker crons | Notes |
|---|---|---|---|
| 20 or fewer | `90` | One, `--queue=default,ai`, `--max-time=280` | Keep runs short. Complex objectives will hit the ceiling and finish with `limit_reached` rather than failing |
| 20–40 | `180` (default) | One, `--queue=default,ai` | The shipped configuration |
| 40+ | `180`–`300` | Two: `--queue=default` and `--queue=ai` | Raise `--max-time` only if you also lengthen the cron interval |

Lowering `AI_MAX_RUN_SECONDS` does not break anything. A run that reaches the ceiling stops
cleanly, records `AiRunStatus::limit_reached`, keeps everything it already did, and reports
what it completed. Per-workspace ceilings can be lowered further in
**Admin → AI → Settings** (`ai_settings.max_run_seconds`); they can never be raised above
the value in `.env`.

### Memory

If you see processes dying with nothing in the log (PMEM), reduce concurrency before
reducing `memory_limit` — a lower `memory_limit` just converts a silent kill into a PHP
fatal error. Practical steps, in order:

1. Lower `AI_MAX_CONTEXT_TOKENS` from 24000. Prompt size is the largest single allocation
   in an AI run.
2. Run one worker cron, not two.
3. Reduce `planvio.pagination.list` from 50 if you have projects with very wide custom
   field sets.
4. Only then raise `memory_limit`, and only if your PMEM headroom allows it.

---

## Troubleshooting `508 Resource Limit Reached`

**What it means.** Your account hit its entry-process limit. The web server refused a new
request before PHP ran. Nothing is broken and nothing is lost — requests already in flight
completed normally.

**What to do first.** Wait 60 seconds and reload. A 508 from a transient burst clears
itself. A 508 that persists for minutes has a cause worth finding.

**Finding the cause.** Open **Resource Usage** in cPanel (some hosts label it *Server Usage*
or *CPU and Concurrent Connection Usage*). It graphs each LVE limit over time and counts
"faults" — the number of times you hit each one. The limit with the fault count is your
answer.

Then work through this list, most likely first:

1. **A worker without `--stop-when-empty`.** The commonest cause on a Planvio install by a
   wide margin. Open **Cron Jobs** and read every command. `queue:work` without
   `--stop-when-empty` never exits, and every five minutes cron starts another one. Fix the
   command; the stuck processes clear when the host reaps them or you contact support.
2. **`--max-time` at or above the cron interval.** `--max-time=300` on a five-minute cron
   guarantees overlap. Use `--max-time=280`.
3. **More than one worker cron.** Two crons plus normal web traffic on a 20-EP account is
   tight. Consolidate to `--queue=default,ai`.
4. **A crawler or scraper.** Check **Metrics → Visitors** or the raw access log for one IP
   or user-agent making rapid requests. Planvio's app routes require authentication, but a
   bot can still burn entry processes on redirects. Block it in **IP Blocker**.
5. **Genuine concurrent use.** Twenty people using Planvio simultaneously on a 20-EP account
   will hit the limit. This is the honest case, and the answer is a larger plan.
6. **Another application on the same account.** LVE limits are per *account*, not per
   domain. A WordPress site in another folder shares your ceiling.

**What does not cause it.** Database size, number of projects, number of tasks — none of
these consume entry processes. 508 is about concurrency, never about how much data you
have.

**When to contact your host.** If Resource Usage shows EP faults with no explanation from
the list above, ask them to confirm your EP and NPROC limits and whether anything outside
cPanel is entering your LVE. Give them the fault counts from the graph; that is the
language they work in.

---

## Checking your limits without SSH

| What you want | Where |
|---|---|
| Your actual LVE limits and fault counts | cPanel → **Resource Usage** |
| PHP version, binary path, `memory_limit`, `max_execution_time` as PHP sees them | Planvio → **Admin → System Information** |
| Whether cron ran, and when | Planvio → **Admin → System Health** |
| Queue depth and the oldest pending job | Planvio → **Admin → System → Queue** |
| The extensions actually loaded | cPanel → **Select PHP Version → Extensions**, confirmed by the installer's Requirements step |
| Disk and inode usage | cPanel → **File Manager** footer, or the **Statistics** sidebar |

Inodes are worth watching. A Planvio release is roughly 15,000 files before you upload a
single attachment. Accounts with a 200,000-inode limit are fine; accounts with a
50,000-inode limit shared with other applications are not.

---

## Limitations

- **Planvio cannot see your LVE limits.** CageFS filters `/proc`, and there is no API for
  an unprivileged account to read its own LVE configuration. **Admin → System Information**
  reports PHP's view of the world — version, binary, memory limit, execution time — and
  stops there. For real limits and fault counts, use cPanel's Resource Usage page.
- **Planvio cannot detect a 508.** The web server produces it before PHP runs, so nothing
  is logged in `storage/logs/` and nothing appears in System Health. An intermittently
  unreachable site with clean Planvio logs is the signature.
- **PMEM kills leave no trace.** A process killed by the kernel writes nothing. Planvio
  cannot report what it was never told about. A job that disappears from `jobs` without
  appearing in `failed_jobs`, and no log line, is a PMEM kill.
- **AI on a tight account is genuinely constrained.** With an EP limit of 20 and a 512 MB
  PMEM allowance, long autonomous runs are not realistic. Assistant and copilot modes,
  which do less per run, are. This is a hosting constraint, not something a setting fixes.
- **Extension names in PHP Selector are not always the names PHP reports.** Planvio checks
  what PHP loaded, not what the tick boxes say. Trust the installer's Requirements step
  over the PHP Selector list.
- **MySQL Governor throttling is invisible to Planvio.** Queries simply take longer.
  Because Planvio uses the database for the queue, cache and sessions, database throttling
  makes the whole application feel slow at once — which reads like a Planvio problem and is
  not one. Resource Usage will show it.

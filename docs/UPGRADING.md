# Upgrading Planvio

An upgrade is three things: replace the files, let the browser step run the migrations,
repoint your cron jobs. Nothing is downloaded for you and nothing runs without you asking.

This guide assumes **no SSH**. Everything is done with cPanel's File Manager, phpMyAdmin
and a browser. If you do have a shell, the same steps work — you will just move files faster.

**Time required:** about 20 minutes, most of it the database backup.

---

## Versioning

Planvio uses semantic versioning: `MAJOR.MINOR.PATCH`.

| Part | Changes when | What it means for you |
|---|---|---|
| **MAJOR** | Something breaks compatibility | Read the version-specific notes before you start. May need manual steps. |
| **MINOR** | New features, backward compatible | Usually adds migrations. Safe, but back up first. |
| **PATCH** | Fixes only | No schema change, no configuration change. |

Release archives are named after the version: `planvio-v1.0.0.zip`. The release builder
prints a SHA-256 for every archive it produces. Check it before you extract — that hash is
the only integrity guarantee, because Planvio does not verify downloads for you.

### Where the version is shown

| Where | What it shows |
|---|---|
| **Login footer** | The application version, on the sign-in page, before you authenticate |
| **The installer, Welcome screen** | The version you are about to install |
| **Admin → System Information** | Application version, database version, Laravel version, PHP version and the PHP binary path |

Admin → System Information is the one to trust during an upgrade: it reports what the
running code and the running database actually are, not what you think you uploaded.

---

## Two versions, not one

`config/planvio.php` carries both:

```php
'version'    => '1.0.0',   // the release
'db_version' => '1.0.0',   // the schema generation
```

The same two values are mirrored as constants on `App\Support\Version`
(`Version::APP_VERSION` and `Version::DB_VERSION`) for code that needs them without booting
the config cache.

| | `version` | `db_version` |
|---|---|---|
| Changes | On every release | Only when a release adds migrations |
| Lives in | `config/planvio.php` (shipped) | `config/planvio.php` (shipped) **and** the `settings` table, key `db_version` (stored) |
| Answers | "Which Planvio is this?" | "Does the database match the code?" |

The shipped `db_version` moves with the release ZIP. The stored `db_version` is written into
the `settings` table when the installer finishes, and rewritten after every successful
upgrade. It stays behind until you actually run the migrations.

### How the upgrade screen decides what to offer

On each request Planvio compares the **stored** `db_version` against the **shipped** one.

| Stored vs shipped | What happens |
|---|---|
| Equal | Nothing to migrate. The application serves normally. |
| Stored is lower | Migrations are pending. Planvio shows the upgrade screen instead of the application. |
| No stored value, but the install lock exists | Treated as "older than shipped". The upgrade screen appears and lists every migration that has not run. |
| Stored is **higher** | Your files are older than your database — you rolled back the code without rolling back the data. Planvio refuses to run anything and tells you to restore the newer release. It will not migrate downwards. |

A patch release that adds no migrations leaves `db_version` untouched, so no upgrade screen
appears. That is correct: replacing the files is the whole upgrade. Because you extract each
release into a **fresh directory** and carry across only `.env` and `storage/app`, the
compiled caches in `bootstrap/cache/` and `storage/framework/views/` start out empty. There
is nothing stale to clear.

The one cache that does survive a folder swap is the settings cache, which lives in the
database `cache` table. The upgrade screen invalidates it. After a migration-free patch
release, sign in and check **Admin → System Information** shows the new version; if it shows
the old one, clear the cache table (see [Caches](#what-gets-cleared)).

---

## Back up first

Do this every time. A five-minute export is cheaper than a restore you cannot perform.

### 1. Export the database with phpMyAdmin

1. In cPanel, open **phpMyAdmin**.
2. Select your Planvio database in the left-hand list (for example `acme_planvio`).
3. Click the **Export** tab.
4. Choose **Custom**.
5. Under *Tables*, leave every table selected.
6. Under *Output*, choose **Save output to a file** and set **Compression** to `gzipped`.
7. Under *Format-specific options*, set **Object creation options** to include
   `DROP TABLE / VIEW / PROCEDURE / FUNCTION / EVENT` — you want a restore to replace
   tables, not fail because they already exist.
8. Click **Export** and keep the `.sql.gz` file somewhere off the server.

Name the file with the version you are leaving, for example
`acme_planvio-before-1.1.0.sql.gz`. You will not remember later.

### 2. Copy the directories that hold your data

Everything else in the release is replaceable. These are not:

| Path | Contents | Size |
|---|---|---|
| `.env` | Database credentials, `APP_KEY`, mail settings | Tiny — but losing `APP_KEY` makes every encrypted value unreadable |
| `storage/app/private/` | Task and comment attachments | Whatever your team has uploaded |
| `storage/app/public/` | Avatars and workspace logos | Small |
| `storage/logs/` | Recent application logs | Useful if the upgrade goes wrong |

In File Manager: select the folder, **Copy**, and copy it somewhere outside the Planvio
directory (for example `/home/youraccount/backups/planvio-1.0.0/`).

You do **not** need to back up `vendor/`, `public/build/`, `bootstrap/cache/` or
`storage/framework/`. They ship in the release ZIP or are rebuilt on demand.

> **`APP_KEY` is not recoverable.** It encrypts AI provider API keys, two-factor secrets and
> encrypted settings. If you lose it, those values are gone permanently — not "resettable",
> gone. Copy `.env` before you touch anything.

---

## Upgrading, step by step

The procedure keeps the old installation intact until the new one is proven. You never
overwrite a working directory.

### Step 1 — Read the notes

Read [Version-specific notes](#version-specific-notes) for **every** version between the one
you are running and the one you are installing, not just the newest. Manual steps do not
accumulate automatically.

### Step 2 — Turn on maintenance mode

Sign in as a platform administrator and switch on maintenance mode in
**Admin → Settings → General**.

Maintenance mode is stored in the database, not in a file, so it survives the directory swap
you are about to do and it can be toggled without a shell. Platform administrators keep full
access while it is engaged; everyone else sees the maintenance message.

### Step 3 — Back up

[As above.](#back-up-first) Database export **and** the four paths.

### Step 4 — Upload and extract the new release

1. Open **File Manager** and go to your home directory (`/home/youraccount`).
2. Create a new folder named after the new version, for example `planvio-1.1.0`.
   Do not extract on top of the running installation.
3. Enter it, click **Upload**, and upload `planvio-v1.1.0.zip`.
4. Right-click the ZIP → **Extract** → into `/home/youraccount/planvio-1.1.0`.
5. Delete the ZIP.

You now have two complete installations side by side:

```
/home/youraccount/
├── planvio/          <-- currently live, version 1.0.0
└── planvio-1.1.0/    <-- new files, not yet serving
```

### Step 5 — Carry your data across

Copy these from the old folder into the new one, keeping the same relative paths:

```
planvio/.env                  ->  planvio-1.1.0/.env
planvio/storage/app/private/  ->  planvio-1.1.0/storage/app/private/
planvio/storage/app/public/   ->  planvio-1.1.0/storage/app/public/
```

In File Manager, select the item, choose **Copy**, and type the destination path.

Copy the *contents* of `storage/app/private` and `storage/app/public` into the folders that
already exist in the new release — do not replace the folders themselves, or you will lose
the `.htaccess` guards that the release ships inside `storage/`.

**Do not copy** `config/`, `app/`, `vendor/`, `public/`, `routes/` or `bootstrap/` from the
old installation. Those are the release. Copying them forward is how you end up running
1.0.0 code that claims to be 1.1.0.

### Step 6 — Check permissions

In the new folder, confirm these are writable (`0755`, or `0775` if your host runs PHP as a
different user):

```
storage/
storage/app/
storage/framework/
storage/logs/
bootstrap/cache/
```

Right-click → **Change Permissions** → tick `755` → tick **Recurse into subdirectories**.

### Step 7 — Repoint the document root

1. Open **Domains** in cPanel.
2. Find the domain serving Planvio and edit its document root.
3. Change it from `/home/youraccount/planvio/public` to
   `/home/youraccount/planvio-1.1.0/public`.
4. Save.

This is the moment the new version goes live. Everything before it was reversible by doing
nothing.

### Step 8 — Run the upgrade in the browser

Open your Planvio URL. Because the stored `db_version` is now behind the shipped one,
Planvio shows the upgrade screen instead of the application. Work through it —
[what it does is described below](#what-the-upgrade-screen-does).

### Step 9 — Update the two cron jobs

**This is the step people forget.** Your cron commands still point at the old directory, so
the scheduler and the queue worker are running the previous release's `artisan`.

Open **Cron Jobs** in cPanel and edit both entries to use the new path:

```
/opt/cpanel/ea-php83/root/usr/bin/php /home/youraccount/planvio-1.1.0/artisan schedule:run >/dev/null 2>&1
```

```
/opt/cpanel/ea-php83/root/usr/bin/php /home/youraccount/planvio-1.1.0/artisan queue:work database --queue=default,ai --stop-when-empty --max-time=280 --tries=3 >/dev/null 2>&1
```

**Admin → System Information** shows the exact PHP binary path Planvio is running under and
generates both lines with the new directory already filled in. Copy from there rather than
editing by hand.

### Step 10 — Turn maintenance mode off and verify

1. Switch maintenance mode off in **Admin → Settings → General**.
2. Check **Admin → System Information**: application version and database version should
   both read `1.1.0`.
3. Check **Admin → System Health** is green, including *Scheduler: last ran N minutes ago*
   (wait two minutes after editing cron).
4. Open a project, a board and a task. Confirm an existing attachment still downloads and an
   avatar still renders.

### Step 11 — Keep the old folder for a week

Do not delete `/home/youraccount/planvio` immediately. It costs disk and it is your fastest
rollback. Delete it once you have run a full week without a problem — and keep the database
export longer than that.

---

## What the upgrade screen does

Five phases, in this order. Nothing is written to your database until phase 3, and phase 3
only starts when you click the button.

Before any of it, the upgrade screen asks you to **sign in as a platform administrator**
(`users.is_admin`). It is not an anonymous endpoint: anyone who could reach it unauthenticated
could run schema changes on your database.

### 1. Verify requirements

The same checks the installer runs, against the new release's requirements:

| Check | Requirement |
|---|---|
| PHP version | 8.3.0 or newer |
| Required extensions | `pdo` `pdo_mysql` `mbstring` `openssl` `json` `fileinfo` `xml` `ctype` `tokenizer` `curl` `bcmath` `gd` |
| Writable paths | `storage/app` `storage/framework` `storage/logs` `bootstrap/cache` |
| Database | Reachable with the credentials in `.env`, and the `settings` table readable |

A red item blocks the upgrade. Fix it — usually a PHP version or an extension in
*Select PHP Version* — and click **Re-check**. The upgrade screen does not offer an
"ignore and continue".

### 2. Show pending migrations — before running them

You get the list of every migration that has not yet run, by filename, with a count, and the
stored and shipped `db_version` side by side.

Nothing has executed at this point. Read the list. If it contains migrations you do not
expect — for example, migrations from a version you thought you had already installed — stop
and check that you copied the right release into the right folder.

### 3. Run the migrations

One pass, in order, inside the migration runner's own transaction handling.

If a migration fails, the run **stops at that migration**. You are shown its filename, a
plain description of what went wrong, and the log reference to look up in
`storage/logs/`. Migrations that already succeeded stay applied — the database is at a
partial state, and the fix is the [rollback procedure](#rolling-back-a-failed-upgrade),
not a retry against a half-migrated schema.

### 4. Clear the right caches

<a id="what-gets-cleared"></a>

| What | Where it lives | Why it must go |
|---|---|---|
| Compiled configuration | `bootstrap/cache/config.php` | Holds the old `version` and `db_version` |
| Compiled routes | `bootstrap/cache/routes-*.php` | New release may add or move routes |
| Package manifest | `bootstrap/cache/packages.php`, `bootstrap/cache/services.php` | Rebuilt from the new `vendor/` |
| Compiled Blade views | `storage/framework/views/` | Templates changed |
| Settings cache | The `cache` table, `database` store | Survives the folder swap — this is the one that bites you |

The settings cache is invalidated by bumping a version segment in its cache keys, so a single
write invalidates every entry at once, including cached "this key does not exist" answers.

If you ever need to do this by hand — the upgrade screen is unreachable, say — delete every
`.php` file in `bootstrap/cache/` (keep `.gitignore`) and empty `storage/framework/views/`
in File Manager. Both directories are regenerated automatically. To drop the settings cache,
empty the `cache` table in phpMyAdmin; it is a cache, nothing in it is authoritative.

### 5. Verify health

The final phase writes the new `db_version` into the `settings` table and then re-runs the
health checks:

- Database connection and the applied migration count
- Writable paths
- `public/storage` points at `storage/app/public` (avatars and logos); the upgrade screen
  recreates the symlink when your host allows PHP to create one
- Scheduler last-run time — expect this to be stale immediately after an upgrade, because
  you have not yet repointed cron
- `APP_DEBUG` is `false`
- `https://yourdomain.com/.env` returns 403

Then **Finish** takes you to the sign-in page.

Planvio never reports a check as passing without evidence. "Scheduler: never run" means
exactly that — it is not a warning you can dismiss, it is a cron job that is not working.

---

## Rolling back a failed upgrade

Roll back the **files and the database together**. Rolling back only one produces the
"stored is higher than shipped" state described above, and Planvio will refuse to run.

1. **Turn maintenance mode on** if it is not already, or take the site offline by pointing
   the document root at an empty folder. Do not let users write into a partially migrated
   database.
2. **Repoint the document root** back to `/home/youraccount/planvio/public` — the old
   release you kept in Step 11.
3. **Restore the database.** In phpMyAdmin, select the Planvio database, open the **Import**
   tab, choose your `.sql.gz` export, and import it. Because you exported with
   `DROP TABLE`, the import replaces the partially migrated tables rather than colliding
   with them.
4. **Restore `storage/app/private` and `storage/app/public`** from your backup copy into the
   old folder, if anything was uploaded after you took the backup.
5. **Repoint the two cron jobs** back at `/home/youraccount/planvio/artisan`.
6. Open your domain, sign in, and check **Admin → System Information** reports the old
   version for both application and database.
7. Turn maintenance mode off.

Then work out what failed before you try again. `storage/logs/` in the **new** folder holds
the error; the upgrade screen gave you the reference to look for.

> **Migrations are not reversed from the browser.** Planvio does not offer a "roll back
> migrations" button, because a down-migration that runs after a partial failure is a good
> way to destroy data that the failed migration had already moved. Restoring your database
> export is the rollback. This is why the backup step is not optional.

---

## Planvio does not update itself

Planvio has **no automatic update mechanism**. It does not check a remote server for new
versions, it does not download release archives, and it cannot replace its own files. You
download the release, you verify the hash, you upload it.

This is deliberate, and it is a security decision rather than a missing feature:

- **No self-writing code path.** An application that can overwrite its own PHP files has, by
  construction, a mechanism for turning a network response into executed code. Planvio does
  not have that mechanism, so it cannot be abused — not by a compromised update server, not
  by DNS hijacking, not by a bug in signature verification, and not by an attacker who gains
  a foothold in the admin panel.
- **No phone-home.** A self-hosted installation that polls a vendor endpoint leaks its
  existence, its version, its hostname and its patch level to that vendor and to anything
  watching the connection. Planvio makes no outbound request you did not configure. The only
  outbound HTTPS it ever makes is to the AI provider you entered yourself, and only when AI
  is enabled.
- **Shared hosting cannot be trusted to write its own web root.** The application user often
  *can* write to `app/` and `vendor/`. That is a liability, not a capability. Planvio's
  design assumes those directories are read-only in spirit, and the `.htaccess` guards that
  ship with the release exist to keep them unreachable and inert.
- **You choose the moment.** An upgrade that runs migrations is a change to your data. It
  belongs in a window you picked, after a backup you took, not at 3 a.m. because a version
  check fired.

The trade-off is real: you have to notice that a release exists and you have to do the work.
That is the intended trade.

---

## Version-specific notes

Read every entry between your current version and your target version, oldest first.

### 1.0.0 — first release

**Application version:** `1.0.0` · **Database version:** `1.0.0`

There is no upgrade path into 1.0.0. It is the initial release: you install it, you do not
upgrade to it. See [INSTALLATION.md](INSTALLATION.md) and [CPANEL.md](CPANEL.md).

Baseline established by this release, for reference when later versions change it:

| | |
|---|---|
| Minimum PHP | 8.3.0 |
| Required extensions | `pdo` `pdo_mysql` `mbstring` `openssl` `json` `fileinfo` `xml` `ctype` `tokenizer` `curl` `bcmath` `gd` |
| Optional extensions | `intl` `zip` `exif` `sodium` |
| Database | MySQL 5.7+ / MariaDB 10.6+ (MySQL 8.0 recommended) |
| Session, cache, queue | All `database`. No Redis. |
| Scheduler | One-minute cron calling `artisan schedule:run` |
| Queue | Cron-invoked `artisan queue:work database --queue=default,ai --stop-when-empty` |
| AI | Off by default (`AI_ENABLED=false`) |
| Install lock | `storage/app/planvio-installed.lock` |

No manual steps. No configuration migrations. No deprecated keys.

---

## Configuration and upgrades

### Never edit files under `config/`

`config/planvio.php`, `config/ai.php` and every other file in `config/` ship inside the
release ZIP and are **replaced on every upgrade**. Any change you make there is silently lost
the next time you extract a release — which is worse than it failing loudly.

Configuration belongs in one of two places:

| Change | Where it goes | Survives upgrade |
|---|---|---|
| Environment, credentials, feature switches | `.env` | Yes — you copy it forward |
| Anything an administrator can change in the UI | The `settings` table | Yes — it is in the database |

### New `.env` keys

A new release may add environment keys. Your existing `.env` will not have them, and that is
fine: every key in `.env.example` has a working default in `config/`, so Planvio runs without
them.

To see what a release added, open the new folder's `.env.example` in File Manager and compare
it against your `.env`. Copy across only the keys you actually want to change. Keys removed
from `.env.example` are simply ignored if they remain in your `.env`.

Never copy an old `.env.example` forward as your `.env`. It has no `APP_KEY`.

---

## Limitations

Stated plainly, so you plan around them rather than discover them.

- **No automatic updates, no update notifications.** Planvio will not tell you a new version
  exists. Nothing in the application checks. Subscribe to whatever release channel you get
  your ZIPs from.
- **No in-place file replacement.** You upload and extract every release yourself. There is
  no "update from the browser" button and there will not be one, for the reasons above.
- **No zero-downtime upgrade.** Repointing the document root and running migrations means a
  short window where the site is unavailable or read-inconsistent. Use maintenance mode.
- **Migrations are forward-only from the UI.** Rollback means restoring your database export.
  Planvio cannot undo a migration for you.
- **The upgrade screen cannot back up your database.** It has no guaranteed access to
  `mysqldump` on shared hosting and will not pretend otherwise. The phpMyAdmin export is a
  manual step and there is no substitute for it.
- **Skipping versions is supported within a MAJOR line; the notes are not.** Migrations are
  cumulative and run in order, so you can go from any 1.x directly to the newest 1.x in one
  pass. Manual steps described in the version-specific notes are *not* cumulative — you must
  read and perform each one.
- **Downgrades are not supported.** Restoring a database export taken under an older version
  is the only supported way back.
- **The release archive has no cryptographic signature.** You get a SHA-256 from the release
  builder's output. Verify it against the archive you downloaded; that is the whole
  integrity story today.
- **`public/storage` may not survive the move.** If your host disables PHP's `symlink()`,
  the upgrade screen reports it and avatars and workspace logos will not render until the
  link exists. Task and comment attachments are unaffected — they never go through that path,
  they are streamed from `storage/app/private/` by an authorising controller.

---

## Troubleshooting

**"Your database is newer than this release"**
You repointed the document root at an older release without restoring the database. Point it
back at the newer folder, or restore the database export that matches the older files.

**The upgrade screen never appears; the old version keeps serving**
The document root still points at the old folder. Check **Domains** in cPanel, and confirm in
**Admin → System Information** which directory is actually running.

**The upgrade screen appears again after you finished it**
The new `db_version` was not stored — usually because the `settings` table write failed, or
because you are looking at a *third* copy of Planvio whose document root you never changed.
Check `storage/logs/` in the new folder.

**Version says 1.1.0 in the footer but 1.0.0 in System Information**
A stale compiled config. Delete the `.php` files in `bootstrap/cache/` and empty the `cache`
table in phpMyAdmin.

**Scheduler and queue stopped working after the upgrade**
Step 9. The cron commands still point at the old directory.

**Attachments 404 after the upgrade**
`storage/app/private/` was not copied across, or was copied to the wrong path. It must be
`storage/app/private/` inside the new release folder, with the same subdirectory structure.

**A migration failed halfway**
Do not retry against a half-migrated schema. Follow
[Rolling back a failed upgrade](#rolling-back-a-failed-upgrade), then read
`storage/logs/` in the new folder.

---

## See also

- [INSTALLATION.md](INSTALLATION.md) — requirements, the installer, first-time setup
- [CPANEL.md](CPANEL.md) — the full no-SSH cPanel walkthrough
- [DEPLOYMENT.md](DEPLOYMENT.md) — Nginx, Apache, permissions, production hardening
- [CRON.md](CRON.md) — the scheduler and what depends on it
- [QUEUE.md](QUEUE.md) — database queues without a daemon
- [SECURITY.md](SECURITY.md) — threat model and hardening checklist
- [ARCHITECTURE.md](ARCHITECTURE.md) — the normative architecture contract

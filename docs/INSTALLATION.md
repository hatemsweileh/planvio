# Installing Planvio

Planvio ships as a single ZIP archive. You upload it, extract it, point a web server at
`public/`, and finish the job in a browser. There is no build step, no dependency manager
and no container.

This document covers what Planvio requires, the three ways to install it, and exactly what
the browser installer does to your server.

---

## Requirements

| | Requirement |
|---|---|
| **PHP** | 8.3.0 or newer. 8.3 and 8.4 are the tested versions. |
| **Required extensions** | `pdo` `pdo_mysql` `mbstring` `openssl` `json` `fileinfo` `xml` `ctype` `tokenizer` `curl` `bcmath` `gd` |
| **Recommended extensions** | `intl` `zip` `exif` `sodium` |
| **Database** | MySQL 5.7 or newer, or MariaDB 10.6 or newer. MySQL 8.0 recommended. |
| **Web server** | Apache 2.4 or LiteSpeed / OpenLiteSpeed (`.htaccess` ships in the release), or Nginx (you write the server block yourself) |
| **Disk** | ~350 MB for the application, plus room for attachments |
| **Memory** | `memory_limit` 256M. 512M if you plan to use AI heavily. |
| **Cron** | One entry that can run every minute, and one that can run every five minutes |
| **SSL** | Strongly recommended. Free with AutoSSL / Let's Encrypt on almost every host. |
| **Outbound HTTPS** | Only needed if you enable AI, and only to your AI provider |

The required extension list above is the one Planvio actually checks, from
`config/planvio.php` (`install.required_extensions`). The installer verifies every entry and
names any that are missing.

`zip` sits in the *recommended* list rather than the required one: nothing in Planvio needs
it at runtime, because cPanel's File Manager extracts the release for you. Enable it anyway
if your host offers it — CSV and archive handling is smoother with it present.

### PHP settings

Set these in *Select PHP Version → Options* on cPanel, or in `php.ini` on a VPS:

```
memory_limit          = 256M
max_execution_time    = 120
upload_max_filesize   = 32M
post_max_size         = 32M
```

`upload_max_filesize` and `post_max_size` must both be at least as large as Planvio's own
upload limit, which defaults to 20 MB (`PLANVIO_MAX_UPLOAD_KB=20480`). PHP rejects an
oversized request before Planvio ever sees it, so the PHP values are the real ceiling.

### What Planvio does not need

No Docker. No Kubernetes. No Redis. No Node.js. No npm. No Composer. No Supervisor. No
systemd unit. No root. No persistent background process.

The release ZIP already contains `vendor/` with production dependencies and `public/build/`
with the compiled frontend assets. Sessions, cache and the queue all run on the database.
The queue is drained by a short-lived worker that cron starts and that exits on its own.

---

## Choose your path

| Path | Use it when | Guide |
|---|---|---|
| **cPanel shared hosting** | You have cPanel and a browser, and no shell | [CPANEL.md](CPANEL.md) — the full walkthrough |
| **LAMP / LEMP server** | You have SSH and root on a VPS or dedicated box | [Below](#installing-on-a-lamp--lemp-server), then [DEPLOYMENT.md](DEPLOYMENT.md) |
| **Local development** | You are working on Planvio itself | [DEVELOPMENT.md](DEVELOPMENT.md) — and a summary [below](#local-development) |

All three end in the same place: the browser installer. The difference is only how the files
get onto disk and how the web server is pointed at them.

---

## Before you start

### Get the release

You need `planvio-v1.0.0.zip`. Releases are built with `scripts/build-release.php`, which
stages the tree, installs production-only Composer dependencies, trims development files out
of `vendor/`, refuses to package if it finds a `.env`, a key file, a database file or VCS
metadata, and then prints a SHA-256 of the archive it produced.

Verify that hash against the archive you downloaded before you extract it. Planvio does not
verify downloads for you and never fetches anything on its own — see
[UPGRADING.md](UPGRADING.md#planvio-does-not-update-itself) for why.

### Create the database

Planvio does not create its own database. Create an empty one plus a user with full
privileges on it, and have these four values ready:

```
Database name      planvio
Database user      planvio
Database password  (generated, not chosen)
Database host      127.0.0.1
```

Use `utf8mb4` with `utf8mb4_unicode_ci`. On cPanel the name and user are prefixed with your
account name, so they become something like `acme_planvio`.

---

## Installing on a LAMP / LEMP server

You have a shell here, so this is quick. You still do not need Composer or Node: the release
ships everything compiled.

The examples use Ubuntu 24.04, Nginx and PHP-FPM 8.3. Adjust paths for your distribution.

### 1. Install PHP and the extensions

```bash
sudo apt update
sudo apt install -y php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml \
                    php8.3-curl php8.3-bcmath php8.3-gd php8.3-intl php8.3-zip
```

`pdo`, `json`, `ctype`, `tokenizer`, `fileinfo` and `openssl` are compiled into the core
packages on Debian and Ubuntu; you do not install them separately. Confirm with:

```bash
php8.3 -m
```

### 2. Create the database

```sql
CREATE DATABASE planvio CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'planvio'@'localhost' IDENTIFIED BY 'a-long-generated-password';
GRANT ALL PRIVILEGES ON planvio.* TO 'planvio'@'localhost';
FLUSH PRIVILEGES;
```

### 3. Extract the release

```bash
sudo mkdir -p /var/www/planvio
sudo unzip planvio-v1.0.0.zip -d /var/www/planvio
sudo chown -R www-data:www-data /var/www/planvio
sudo find /var/www/planvio -type d -exec chmod 755 {} \;
sudo find /var/www/planvio -type f -exec chmod 644 {} \;
sudo chmod -R 775 /var/www/planvio/storage /var/www/planvio/bootstrap/cache
```

The web server user must be able to write to `storage/app`, `storage/framework`,
`storage/logs` and `bootstrap/cache`. It must **not** need write access to anything else —
`app/`, `config/`, `vendor/` and `public/` are read-only in normal operation.

### 4. Configure Nginx

`public/.htaccess` and the root `.htaccess` protect an Apache or LiteSpeed installation.
Nginx ignores both files entirely, so you must reproduce their protections in the server
block. This one does:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name projects.example.com;

    root /var/www/planvio/public;
    index index.php;
    charset utf-8;

    client_max_body_size 32M;

    add_header X-Content-Type-Options   "nosniff" always;
    add_header X-Frame-Options          "SAMEORIGIN" always;
    add_header Referrer-Policy          "strict-origin-when-cross-origin" always;
    add_header Cross-Origin-Opener-Policy "same-origin" always;
    add_header Permissions-Policy       "geolocation=(), microphone=(), camera=(), payment=(), usb=()" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 180;
    }

    # The storage symlink carries avatars and workspace logos only. Attachments are
    # never here - they are streamed by an authorising controller. Never execute
    # anything found under this path.
    location ^~ /storage/ {
        location ~ \.(php|phtml|phar|php[3-8]|inc|pl|py|cgi|sh)$ {
            deny all;
        }
    }

    # Dotfiles, except ACME challenges.
    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Enable it, test it, reload:

```bash
sudo ln -s /etc/nginx/sites-available/planvio /etc/nginx/sites-enabled/planvio
sudo nginx -t
sudo systemctl reload nginx
```

Then issue a certificate:

```bash
sudo certbot --nginx -d projects.example.com
```

Once HTTPS works on every hostname you serve, enable HSTS by adding
`add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;` to the
server block.

**On Apache**, none of this is necessary. Set the `DocumentRoot` to
`/var/www/planvio/public`, make sure `AllowOverride All` is set for that directory so the
shipped `.htaccess` is honoured, and enable `mod_rewrite` and `mod_headers`.

Deeper hardening for both servers is in [DEPLOYMENT.md](DEPLOYMENT.md).

### 5. Add the cron jobs

Run them as the web server user, not as root:

```bash
sudo -u www-data crontab -e
```

```cron
* * * * * /usr/bin/php8.3 /var/www/planvio/artisan schedule:run >/dev/null 2>&1
*/5 * * * * /usr/bin/php8.3 /var/www/planvio/artisan queue:work database --queue=default,ai --stop-when-empty --max-time=280 --tries=3 >/dev/null 2>&1
```

`--stop-when-empty` is the point: the worker drains whatever is waiting and exits, so there
is no daemon to supervise and no process to restart after a deploy. Details in
[CRON.md](CRON.md) and [QUEUE.md](QUEUE.md).

### 6. Run the installer

Open `https://projects.example.com` in a browser and work through the wizard. It is the same
wizard on every platform — [described in full below](#the-browser-installer).

---

## Local development

Full instructions are in [DEVELOPMENT.md](DEVELOPMENT.md). The short version, from a clone of
the repository rather than a release ZIP:

```bash
composer setup     # composer install, .env, key:generate, migrate, npm install, npm run build
composer dev       # runs the dev server, queue listener, log tail and Vite together
```

Development is the one place Composer, Node and npm are used. They exist so that
`scripts/build-release.php` can produce a ZIP that needs none of them.

Local development also uses the browser installer if you want to exercise it — visit
`http://localhost:8000` on a database with no tables. `composer setup` bypasses it by running
`artisan migrate` directly, which is faster when you are resetting a working tree twenty
times a day.

---

## The browser installer

Open your Planvio URL. If the install lock is absent, Planvio serves the wizard instead of
the application, whatever path you requested.

Nine screens, in this order. Nothing is written to disk or to your database until the
**Install** screen, and that screen does not start until you click the button.

### Welcome

**On screen:** the Planvio wordmark, the version you are about to install
(`1.0.0`), a one-paragraph summary of what the wizard will do, the licence, and a single
**Get started** button.

**What it asks:** you accept the licence.

**What it does:** nothing. It is a confirmation that you are installing the version you
meant to install — check the version number here against the ZIP you extracted.

### Requirements

**On screen:** three grouped lists — PHP, extensions, directories — each row a check name, a
detected value and a green tick or a red cross. A summary line at the top reads
"12 of 12 required extensions present" or names the failures. A **Re-check** button sits at
the bottom, next to **Continue**, which stays disabled while anything required is red.

**What it asks:** nothing. It reports.

**What it checks:**

| Group | Checks |
|---|---|
| PHP | Version is 8.3.0 or newer |
| Extensions | The twelve required extensions, individually. Optional ones (`intl`, `zip`, `exif`, `sodium`) are listed as warnings, not failures. |
| Directories | `storage/app`, `storage/framework`, `storage/logs` and `bootstrap/cache` are writable |
| Web server | The `.env` file is not reachable over HTTP |

A red directory row means permissions. A red extension row means your PHP build — fix it in
*Select PHP Version* on cPanel, or install the package on a VPS, then **Re-check**. There is
no override.

### Database

**On screen:** five fields — connection (MySQL), host, port, database name, username,
password — plus a **Test connection** button that reports the server version it reached, or
the exact driver error if it could not.

**What it asks:** the four values from [Create the database](#create-the-database).

**What it does:** opens a connection, reads the server version, and checks whether the
database already contains Planvio tables. It writes nothing. If tables are already present it
says so and refuses to continue, because installing over an existing database is how people
destroy data they meant to keep.

Common failures: `localhost` versus `127.0.0.1` (they use different sockets), a database user
that was created but never granted privileges on the database, and a cPanel account prefix
left off the name.

### Application

**On screen:** site name, site URL, timezone, locale, currency and date format, each with a
sensible default pre-filled. The URL field is pre-populated from the address you are viewing.

**What it asks:**

| Field | Becomes | Default |
|---|---|---|
| Site name | `APP_NAME`, and the name of your first workspace | `Planvio` |
| Site URL | `APP_URL` | The URL you are currently on |
| Timezone | `APP_TIMEZONE` and the first workspace's timezone | `UTC` |
| Locale | `APP_LOCALE` and the first workspace's locale | `en` |
| Currency | `PLANVIO_CURRENCY` and the first workspace's currency | `USD` |
| Date format | `PLANVIO_DATE_FORMAT` and the first workspace's date format | `Y-m-d` |

There is no separate workspace screen: the first workspace is created from these answers
during the Install step, and you can rename it — or create more — as soon as you sign in.

Get the URL right, including `https://` and any subdirectory. It is used to build links in
notification emails, which are sent by cron and have no request to infer it from.

### Administrator

**On screen:** name, email, password and password confirmation, with a live strength meter
and the password rules listed beneath the field.

**What it asks:** the account that becomes the platform super-administrator
(`users.is_admin = true`) and the owner of the first workspace.

**Password rules**, from `config/planvio.php` (`security.password`):

- At least 10 characters (`PLANVIO_PASSWORD_MIN`)
- Mixed case required
- At least one number
- Symbols allowed but not required

This account can reach `/admin`, so treat it accordingly. Two-factor authentication can be
turned on afterwards from your profile.

### Email

**On screen:** SMTP host, port, encryption, username, password, from-address and from-name,
a **Send test email** button, and a **Skip for now** link.

**What it asks:** plain SMTP details. Planvio does not require SendGrid, SES, Postmark or any
transactional provider.

```
SMTP host       mail.example.com
SMTP port       587
Encryption      TLS
Username        planvio@example.com
Password        (the mailbox password)
From address    planvio@example.com
From name       Planvio
```

**You can skip this screen.** Planvio installs and runs without email; invitations, password
resets and notification digests just will not send until you configure it in
**Admin → Settings → Email**. That is a genuine limitation, not a soft one — plan to come
back to it.

### AI

**On screen:** a driver selector (OpenAI, Anthropic, OpenAI-compatible endpoint, custom HTTP
endpoint), base URL, API key, model, a **Test connection** button, and a prominent
**Skip — set up AI later** link.

**What it asks:** nothing you have to answer. AI is **off by default**
(`AI_ENABLED=false`) and skipping this screen is the expected choice for most installations.

If you do configure it here, the wizard stores the provider row with the API key encrypted
and leaves the AI master switch off until you enable it in **Admin → AI**. Nothing in the
rest of Planvio depends on AI being present: disabling it removes the AI surfaces and changes
nothing else.

Full detail in [AI.md](AI.md).

### Install

**On screen:** a checklist that fills in as each step completes, with a spinner on the current
step and a tick on the finished ones. This is the only screen that writes anything.

**On success:** a summary of what was created and a **Finish** button.

**On failure:** the failed step in red, a plain-language explanation, and a log reference —
see [If the installer fails](#if-the-installer-fails).

### Finish

**On screen:** confirmation, the two cron commands with your actual PHP binary path and
installation directory already filled in, a short list of what to do next, and a **Sign in**
button.

Copy the cron lines from here. They are correct for your server; a line you write yourself
usually is not.

---

## What the installer writes

In this order. Each step is a checkpoint; see [failure recovery](#if-the-installer-fails).

### 1. `.env`

Written from the shipped `.env.example` template, with your answers substituted. This is why
you never edit `.env` by hand — the wizard owns the initial file.

Keys the installer sets from your input:

```
APP_NAME, APP_URL, APP_TIMEZONE, APP_LOCALE
DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
MAIL_MAILER, MAIL_HOST, MAIL_PORT, MAIL_SCHEME, MAIL_USERNAME, MAIL_PASSWORD,
MAIL_FROM_ADDRESS, MAIL_FROM_NAME
PLANVIO_CURRENCY, PLANVIO_DATE_FORMAT
```

Keys it sets to production values regardless of what you asked for:

```
APP_ENV=production
APP_DEBUG=false
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
SESSION_SECURE_COOKIE=true
LOG_LEVEL=error
AI_ENABLED=false
```

`.env.example` documents every supported key with a comment. Read it if you want to know what
else you can set.

### 2. `APP_KEY`

A fresh 256-bit application key is generated and written into `.env`.

This key encrypts AI provider API keys, two-factor secrets and any setting stored with
`is_encrypted = true`. **It is not recoverable.** Back up `.env` before you do anything else
with the installation, and never reuse a key from a different installation.

### 3. The migration run

Every migration in `database/migrations/` runs in order. This creates the whole schema:
users, workspaces and members, projects and statuses, tasks and their statuses, milestones,
comments, attachments, activities, notifications, time entries, expenses, wiki pages, saved
views, custom fields, templates, settings, webhooks, audit logs, and the AI tables.

The framework tables — `sessions`, `cache`, `jobs`, `failed_jobs`, `job_batches`,
`personal_access_tokens` — are created here too. There is no separate "set up queues" step
because the queue is a database table.

### 4. Seeded defaults

| Seeded | What you get |
|---|---|
| **Project statuses** | Planning (default), Active, On Hold, Completed, Cancelled — per workspace, reusable across projects |
| **Task statuses** | Backlog, To Do (default), In Progress, Review, Blocked, Completed, Cancelled — copied into each new project |
| **Tags** | Urgent, Client, Internal, Design, Marketing, Finance, Operations |
| **System project templates** | `workspace_id = null`, `is_system = true` starter templates covering the built-in project types |
| **AI defaults** | One global `ai_settings` row with `workspace_id = null`, `is_enabled = false`, assistant as the default mode, and the shipped run limits |
| **Release identity** | `db_version` written into the `settings` table — this is what [UPGRADING.md](UPGRADING.md) compares against |

All of these are starting points, not fixtures. Rename them, recolour them, reorder them or
delete them; nothing in the application depends on a seeded row still existing.

The exact values live in `config/planvio.php` under `defaults`, so you can see precisely what
you are getting before you install.

### 5. The administrator

One `users` row with `is_admin = true`, `is_active = true`, your name, email and hashed
password. `email_verified_at` is set — you verified it by typing it into the installer.

### 6. The first workspace

One `workspaces` row, named from the site name you gave on the Application screen and owned
by the administrator, plus one `workspace_members` row giving them the `owner` role. The
seeded project statuses and tags are scoped to this workspace.

### 7. The storage symlink

`public/storage` is linked to `storage/app/public`.

That link carries **avatars and workspace logos only**. Task and comment attachments live in
`storage/app/private/` and are never web-reachable: they are streamed by a controller that
checks the requesting user's workspace membership and policy first. The shipped
`public/.htaccess` additionally refuses to execute anything found under `/storage/`.

If your host disables PHP's `symlink()`, the installer reports it and continues. Everything
works except avatar and logo images.

### 8. The install lock and `APP_INSTALLED`

`storage/app/planvio-installed.lock` is created, and `APP_INSTALLED=true` is written into
`.env`.

This is the last thing the installer does. Up to this point, a failure leaves you able to
retry. From this point on, the installer is closed — see [the next section](#the-install-lock).

---

## The install lock

```
storage/app/planvio-installed.lock
```

The path is configured at `install.lock_file` in `config/planvio.php`.

Once that file exists, **every installer request is refused server-side**. Not the link
hidden, not the button greyed out — the request is checked and rejected before any installer
logic runs. Guessing a step URL, replaying a form post, or hand-crafting a request gets the
same answer: the installer has already been run.

This matters because the installer, by design, does things no authenticated user can do:
write `.env`, generate an application key, create a super-administrator. An installer that
stayed reachable after installation would be a complete authentication bypass sitting at a
predictable URL. The lock is the control that closes it.

### Reinstalling on purpose

Two separate things must happen, and neither one alone is enough:

1. **Delete `storage/app/planvio-installed.lock`** in File Manager.
2. **Drop the Planvio tables** in phpMyAdmin — select the database, tick all tables, choose
   **Drop** from the *With selected* menu.

Deleting only the lock gets you as far as the Database screen, where the installer detects
the existing tables and stops. Dropping only the tables leaves the lock in place and the
installer still refuses to run — and now you have an application pointed at an empty schema.

The two-step requirement is deliberate. A single delete-this-file gesture that wipes a
production installation is a footgun; needing to destroy the database *and* remove the lock
means nobody does it by accident.

**Reinstalling destroys everything.** It is not a repair procedure. If Planvio is misbehaving,
read `storage/logs/` and check **Admin → System Health** first.

---

## If the installer fails

The installer records a checkpoint after each of the eight steps above. A failure is not a
dead end and never leaves you permanently locked out — the lock file is written *last*, only
after a complete success.

**What you see:** the step that failed, in red, with the completed steps still ticked above
it; a plain explanation of what went wrong, with no stack trace and no credentials in it; a
log reference identifying the entry to look for in `storage/logs/`; and a **Retry** button.

**What happens on retry:** the installer resumes from the failed step. Steps that already
succeeded are not repeated — your `.env` is not rewritten, your `APP_KEY` is not regenerated
(which would make anything already encrypted unreadable), and migrations that already ran are
not re-run.

Typical failures and their fixes:

| Failure | Cause | Fix |
|---|---|---|
| Cannot write `.env` | The application root is not writable by PHP | Set the Planvio directory to `755` and confirm PHP runs as your account's user |
| Migration failed | The database user lacks `CREATE`, `ALTER` or `INDEX` | Grant ALL PRIVILEGES on the database, then **Retry** |
| Migration timed out | `max_execution_time` too low on a slow shared host | Raise it to 120 and **Retry** — completed migrations are not repeated |
| Seeding failed | Almost always a partial migration run underneath | Drop the tables, delete the lock if present, start again |
| Symlink failed | Host disables `symlink()` | Not fatal. Continue; avatars and logos will not render |
| Test email failed | Outbound SMTP blocked, or wrong port | Skip the Email screen and configure it later in **Admin → Settings → Email** |

The error messages are written to be safe to screenshot: they never contain your database
password, your `APP_KEY` or an API key. The log reference exists so that the detail stays in
`storage/logs/`, where it is not web-reachable.

If you are truly stuck, the clean restart is
[the two-step reinstall](#reinstalling-on-purpose) — but read the log first. The log almost
always names the problem outright.

---

## Post-installation checklist

Work through this before you invite anyone.

**Security**

- [ ] `https://yourdomain.com/.env` returns **403 Forbidden**, not a file download
- [ ] `https://yourdomain.com/storage/` does not list files
- [ ] `https://yourdomain.com/vendor/` returns 403
- [ ] `APP_DEBUG` reads `false` in **Admin → System Information**
- [ ] HTTPS is working and *Force HTTPS Redirect* is on
- [ ] `.env` has been copied somewhere safe — `APP_KEY` is not recoverable
- [ ] Two-factor authentication enabled on the administrator account

**Function**

- [ ] Signing in works and the dashboard renders with styles, not as plain text
- [ ] **Admin → System Health** is green
- [ ] A test email arrives
- [ ] Both cron jobs are added and the scheduler reports a recent run (wait two minutes)
- [ ] Upload an attachment to a task and download it again
- [ ] An avatar image renders — if not, the storage symlink is missing

**Operational**

- [ ] A database backup schedule exists, through cPanel or your host
- [ ] You know where `storage/logs/` is and how to read it in File Manager
- [ ] The release ZIP and its SHA-256 are archived somewhere you can find them
- [ ] You have read [UPGRADING.md](UPGRADING.md) before you need it

Then, when you are ready: [AI.md](AI.md) to turn on the AI layer, and
[SECURITY.md](SECURITY.md) for the full hardening pass.

---

## Limitations

- **The installer is the only supported first-run path.** There is no unattended or
  scripted install, no `artisan planvio:install` command, and no way to provision an
  installation from a configuration file. Every installation is completed by a human in a
  browser.
- **No automatic updates.** Planvio never contacts a remote server, never checks for a new
  version and cannot replace its own files. Upgrades are manual and deliberate — see
  [UPGRADING.md](UPGRADING.md#planvio-does-not-update-itself).
- **MySQL and MariaDB only.** `DB_CONNECTION` accepts other drivers because Laravel does, but
  the schema and the queries are written and tested against MySQL and MariaDB. PostgreSQL and
  SQLite are not supported for production.
- **Nginx needs manual configuration.** The `.htaccess` files that harden an Apache or
  LiteSpeed installation are inert under Nginx. If you use Nginx, the protections in your
  server block are the only ones you have — including the `/storage/` execution guard and the
  dotfile deny rule.
- **`.htaccess` protection depends on your host.** If `AllowOverride` is off, the shipped
  rules are ignored silently. This is why the recommended layout puts the application above
  the web root and only `public/` inside it. Verify with the `.env` 403 check.
- **No email without SMTP.** Skipping the Email screen is supported, but invitations,
  password resets and reminder digests will not send until you configure it. Planvio has no
  built-in mail transport.
- **Scheduled work needs cron.** Without the one-minute scheduler entry, due-date reminders,
  recurring tasks, AI automations and retention cleanup never fire. Planvio keeps working;
  those features simply do not run, and **Admin → System Health** says so rather than
  pretending otherwise.
- **The queue is not real-time.** Jobs wait for the next cron tick, so a queued email may sit
  for up to five minutes. That is the cost of not running a daemon, and it is the intended
  trade on shared hosting.
- **Avatars depend on `symlink()`.** Hosts that disable it lose avatar and workspace logo
  rendering. Attachments are unaffected — they never use that path.
- **One installation per database.** Planvio does not support two installations sharing a
  schema, and the Database screen refuses to install over existing tables.

---

## See also

| Document | For |
|---|---|
| [CPANEL.md](CPANEL.md) | The complete no-SSH cPanel walkthrough |
| [CLOUDLINUX.md](CLOUDLINUX.md) | PHP Selector, LVE limits, CageFS |
| [DEPLOYMENT.md](DEPLOYMENT.md) | Nginx, Apache, permissions, production hardening |
| [CRON.md](CRON.md) | The scheduler and everything that depends on it |
| [QUEUE.md](QUEUE.md) | Database queues without a daemon |
| [UPGRADING.md](UPGRADING.md) | Versioning and the upgrade procedure |
| [SECURITY.md](SECURITY.md) | Threat model and hardening checklist |
| [AI.md](AI.md) | Setting up and using the AI layer |
| [DEVELOPMENT.md](DEVELOPMENT.md) | Working on Planvio itself |
| [ARCHITECTURE.md](ARCHITECTURE.md) | The normative architecture contract |

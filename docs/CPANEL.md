# Installing Planvio on cPanel

This guide assumes you have **no SSH access** and no root. Everything here is done with
cPanel's File Manager, MySQL Databases, PHP Selector and Cron Jobs tools, plus your browser.

You will not run a single command line instruction.

**Time required:** about 15 minutes.

---

## What you need

| | |
|---|---|
| Hosting | Any cPanel account (shared hosting is fine, including CloudLinux) |
| PHP | 8.3 or 8.4 |
| Database | MySQL 5.7+ or MariaDB 10.6+ |
| Disk | ~350 MB for the application, plus room for your attachments |
| Memory | 256 MB PHP memory limit (512 MB recommended) |
| SSL | Recommended. Free with AutoSSL / Let's Encrypt in most cPanel accounts |

Planvio does **not** need Docker, Node.js, npm, Composer, Redis, Supervisor, or a
persistent background process.

---

## Step 1 — Set the PHP version and extensions

1. In cPanel, open **Select PHP Version** (sometimes listed under *Software* as
   *MultiPHP Manager* or, on CloudLinux, *PHP Selector*).
2. Choose **PHP 8.3** or **8.4**.
3. On the **Extensions** tab, make sure all of these are ticked:

   ```
   bcmath   ctype    curl     fileinfo  gd
   json     mbstring openssl  pdo       pdo_mysql
   tokenizer  xml
   ```

   `intl`, `zip`, `exif` and `sodium` are optional but recommended. Planvio does not
   need `zip` to install — cPanel's File Manager extracts the release, not PHP.

   This list is the one Planvio's installer actually enforces
   (`config/planvio.php → install.required_extensions`).

4. On the **Options** tab, set:

   ```
   memory_limit          = 256M    (512M if you plan to use AI heavily)
   max_execution_time    = 120
   upload_max_filesize   = 32M
   post_max_size         = 32M
   ```

5. Click **Save**.

> If an extension is missing and greyed out, ask your host to enable it. Planvio's
> installer checks every one of these and tells you exactly which is absent.

---

## Step 2 — Create the database

1. Open **MySQL® Databases**.
2. Under *Create New Database*, enter `planvio` and click **Create Database**.
   cPanel prefixes it with your account name, so the real name becomes something like
   `acme_planvio`. **Write down the full name.**
3. Under *MySQL Users → Add New User*, create a user (for example `planvio`) and use the
   password generator. **Copy the password somewhere safe** — cPanel will not show it again.
4. Under *Add User To Database*, select your new user and your new database, click **Add**,
   then tick **ALL PRIVILEGES** and click **Make Changes**.

You now have three values the installer will ask for:

```
Database name      acme_planvio
Database user      acme_planvio
Database password  (the one you generated)
Database host      localhost
```

---

## Step 3 — Upload and extract Planvio

1. Open **File Manager**.
2. Navigate to your account's home directory (`/home/youraccount`), *not* `public_html`.
3. Create a folder called `planvio`.
4. Enter it, click **Upload**, and upload `planvio-v1.0.0.zip`.
5. Back in File Manager, right-click the ZIP and choose **Extract**. Extract into
   `/home/youraccount/planvio`.
6. Delete the ZIP once extraction finishes.

You should now see this structure:

```
/home/youraccount/planvio/
├── app/
├── bootstrap/
├── config/
├── database/
├── public/          <-- this is the web root
├── resources/
├── routes/
├── storage/
├── vendor/
└── artisan
```

> **Why not `public_html`?** Only `public/` should ever be reachable from the web.
> Keeping the rest above the web root means your `.env` file, database credentials and
> uploaded files cannot be fetched over HTTP even if a rewrite rule is misconfigured.
> If your host forces you to use `public_html`, see [the fallback](#fallback-when-you-must-use-public_html).

---

## Step 4 — Point your domain at `public/`

### If Planvio is on the main domain

1. Open **Domains** (or *Addon Domains* / *Subdomains* on older cPanel builds).
2. Find your domain and click **Manage** or the edit icon next to the document root.
3. Change the document root to:

   ```
   /home/youraccount/planvio/public
   ```

4. Save.

### If Planvio is on a subdomain

1. Open **Domains → Create A New Domain** (or **Subdomains**).
2. Enter the subdomain, for example `projects.example.com`.
3. Set the **Document Root** to `/home/youraccount/planvio/public`.
4. Untick "Share document root with…" if that option appears.
5. Create.

Give DNS a few minutes if the subdomain is new.

---

## Step 5 — Check permissions

In File Manager, confirm these directories are writable (permissions `0755`, or `0775` if
your host runs PHP as a different user):

```
storage/
storage/app/
storage/framework/
storage/logs/
bootstrap/cache/
```

To set them: right-click a folder → **Change Permissions** → tick the boxes for `755` →
tick **Recurse into subdirectories** → **Change Permissions**.

The installer verifies these and will tell you if anything is wrong.

---

## Step 6 — Run the installer

Open your domain in a browser:

```
https://projects.example.com
```

Planvio detects that it is not installed yet and starts the setup wizard.

| Step | What you do |
|---|---|
| **Welcome** | Confirm the version and licence |
| **Requirements** | Every check must be green. Fix any red items and click *Re-check* |
| **Database** | Enter the values from Step 2, click **Test Connection**, then continue |
| **Application** | Site name, URL, timezone, locale, currency, date format |
| **Administrator** | Your name, email and a strong password. This becomes the platform admin |
| **Email** | SMTP details (see Step 8). You can skip this and configure it later |
| **AI** | Optional. You can skip this entirely and set it up later |
| **Install** | Watch the progress. This creates tables, seeds defaults, and writes `.env` |
| **Finish** | Click through to sign in |

You never edit `.env` by hand. The installer writes it, generates the application key and
locks itself so it cannot be run again.

---

## Step 7 — Set up the two cron jobs

Planvio needs cron for scheduled work: due-date reminders, recurring tasks, AI automations,
notification emails and cleanup. Without cron, Planvio still works — those features just
will not fire.

Open **Cron Jobs** in cPanel. Add these two entries.

### 7a. The scheduler — every minute

**Common Settings:** *Once Per Minute (\* \* \* \* \*)*

**Command:**

```
/usr/local/bin/php /home/youraccount/planvio/artisan schedule:run >/dev/null 2>&1
```

### 7b. The queue worker — every five minutes

**Common Settings:** *Every Five Minutes (\*/5 \* \* \* \*)*

**Command:**

```
/usr/local/bin/php /home/youraccount/planvio/artisan queue:work database --queue=default,ai --stop-when-empty --max-time=280 --tries=3 >/dev/null 2>&1
```

`--stop-when-empty` is what makes this safe on shared hosting: the worker processes
whatever is waiting and exits, rather than running forever as a daemon.

### Getting the PHP path right

`/usr/local/bin/php` is usually the account's *default* PHP, which may not be the version
you selected in Step 1. To be certain, use the version-specific binary. On most cPanel
servers it is one of:

```
/opt/cpanel/ea-php83/root/usr/bin/php
/opt/cpanel/ea-php84/root/usr/bin/php
```

On CloudLinux with PHP Selector:

```
/opt/alt/php83/usr/bin/php
/opt/alt/php84/usr/bin/php
```

**How to find yours without SSH:** after installing, sign in to Planvio and open
**Admin → System Information**. Planvio shows the exact PHP binary path it is running under
and generates both cron lines with that path filled in, ready to copy.

### Confirming cron is working

Admin → System Health shows *Scheduler: last ran N minutes ago*. If it says the scheduler
has never run, the cron command is wrong — usually the PHP path or the path to `artisan`.

Planvio never claims cron is healthy without evidence; it reports what it actually observed.

---

## Step 8 — Configure email

Planvio uses plain SMTP. No SendGrid, SES or Postmark account is required.

### Using your cPanel mail account

1. In cPanel, open **Email Accounts** and create one, for example `planvio@example.com`.
2. Use these settings in Planvio (installer step 7, or **Admin → Settings → Email**):

   ```
   SMTP host       mail.example.com
   SMTP port       587
   Encryption      TLS
   Username        planvio@example.com
   Password        (the mailbox password)
   From address    planvio@example.com
   From name       Planvio
   ```

3. Click **Send Test Email** and check it arrives.

> Some hosts block outbound port 587 or 465 to external SMTP servers. If external SMTP
> fails, use your own cPanel mailbox on `localhost:25` with encryption set to *None*.

---

## Step 9 — Enable SSL

1. Open **SSL/TLS Status**.
2. Select your domain and click **Run AutoSSL**.
3. Once the certificate is issued, open **Domains** and turn on **Force HTTPS Redirect**.
4. In Planvio, check **Admin → Settings → General** and make sure the site URL begins
   with `https://`.

After HTTPS is confirmed working on every hostname you serve, you can enable HSTS by
uncommenting the `Strict-Transport-Security` line in `public/.htaccess`.

---

## Step 10 — Configure AI (optional)

Planvio works fully without AI. To turn it on:

1. Sign in and go to **Admin → AI**.
2. Add a provider: choose OpenAI, Anthropic, or any OpenAI-compatible endpoint, then enter
   the base URL (if required), API key and model.
3. Click **Test Connection**.
4. Set the default mode:
   - **Assistant** — answers questions, changes nothing
   - **Copilot** — proposes actions, you approve each one
   - **Autonomous** — acts on its own within the limits you set
5. Review **Allowed tools** and **Approval required** before enabling Copilot or Autonomous.

Your API key is encrypted in the database and is never shown again after saving, never sent
to the browser, and never written to a log.

**Outbound HTTPS must be allowed.** Some shared hosts block outbound connections by default.
If **Test Connection** times out, ask your host to allow outbound HTTPS (port 443) to your
provider's domain.

---

## Fallback: when you must use `public_html`

Some hosts do not let you change the document root. In that case:

1. Extract Planvio directly into `public_html`, so you end up with
   `public_html/app`, `public_html/public`, `public_html/vendor` and so on.
2. Do nothing else. The `.htaccess` file at the root of the release already handles it:
   it forwards requests into `public/` and blocks direct access to `app/`, `config/`,
   `storage/`, `vendor/` and `.env`.

This layout works, but it is **less safe** than Step 3: your application files sit inside
the web root, and their protection depends on `.htaccess` being honoured. If your host ever
disables `AllowOverride`, those files become reachable. Prefer the document-root approach
whenever your host permits it.

To verify the protection is active, visit `https://yourdomain.com/.env` — you should get a
403 Forbidden, not a download. Planvio's **Admin → System Health** page runs this same check
for you and warns if it fails.

---

## Upgrading

1. Back up your database (**phpMyAdmin → Export**) and your `storage/app` directory.
2. Upload and extract the new release ZIP into a fresh folder.
3. Copy your existing `.env` file and `storage/app/private` directory into the new folder.
4. Repoint the document root at the new `public/` directory.
5. Open your domain. Planvio detects the version change and offers to run pending
   migrations, showing you what will change before it does anything.

Full detail in [UPGRADING.md](UPGRADING.md).

---

## Troubleshooting

**"500 Server Error" straight after extraction**
`storage/` or `bootstrap/cache/` is not writable. Set both to `755` recursively (Step 5).

**Blank white page**
Your PHP version is below 8.3, or a required extension is missing. Set it in
*Select PHP Version* (Step 1). Check `storage/logs/laravel.log` in File Manager for the
actual error.

**"The installer has already been run"**
`storage/app/planvio-installed.lock` exists. This is intentional. To reinstall
deliberately, delete that file **and** drop the database tables — never one without the
other.

**Styles missing, page looks like plain text**
The document root is pointing at the project folder instead of `public/`. Recheck Step 4.

**Scheduler and queue show as inactive**
The PHP path in your cron command is wrong. Copy the exact command from
**Admin → System Information**, which uses the binary Planvio is actually running under.

**Attachments fail to upload**
`upload_max_filesize` or `post_max_size` is lower than Planvio's configured limit. Raise
both in *Select PHP Version → Options*, or lower Planvio's limit in
**Admin → Settings → Uploads**.

**AI test connection times out**
Outbound HTTPS is blocked, or the base URL is wrong. Confirm with your host that outbound
port 443 is open.

---

## What to check after installing

- [ ] `https://yourdomain.com/.env` returns 403, not a file download
- [ ] `https://yourdomain.com/storage/` does not list files
- [ ] Signing in works and the dashboard renders with styles
- [ ] **Admin → System Health** is all green
- [ ] A test email arrives
- [ ] The scheduler reports a recent run (wait two minutes after adding cron)
- [ ] `APP_DEBUG` is `false` — check **Admin → System Information**
- [ ] You have a database backup schedule, either through cPanel or your host

See [SECURITY.md](SECURITY.md) for the full hardening checklist.

# Deploying Planvio

This is the general deployment reference: web server configuration, permissions, production
environment, caching, updates and backup. It assumes you control the web server.

If you are on cPanel, read [CPANEL.md](CPANEL.md) instead. It covers the same ground
through cPanel's own tools, with no command line at all.

Planvio is built for boring hosting. It needs PHP, a MySQL-compatible database and a
one-minute cron. It does **not** need Docker, Redis, Node.js, npm, Composer, Supervisor,
systemd units or root at runtime. `vendor/` and `public/build/` ship inside the release ZIP,
so there is nothing to install and nothing to compile on the server.

---

## What you need

| | |
|---|---|
| PHP | 8.3 or 8.4, with PHP-FPM or LSAPI |
| Extensions | `pdo` `pdo_mysql` `mbstring` `openssl` `json` `fileinfo` `xml` `ctype` `tokenizer` `curl` `bcmath` `gd` (`intl`, `zip`, `exif`, `sodium` optional) |
| Database | MySQL 8 / MariaDB 10.6+ (5.7 works) |
| Web server | Apache 2.4, LiteSpeed / OpenLiteSpeed, or nginx |
| Cron | One entry per minute, one every few minutes |
| Disk | ~350 MB for the application, plus your attachments |
| Memory | 256 MB PHP memory limit, 512 MB if you use AI heavily |

---

## The two supported layouts

| | **A — document root at `public/`** | **B — everything in `public_html`** |
|---|---|---|
| Application lives in | `/var/www/planvio` (outside the web root) | `/home/you/public_html` (inside the web root) |
| Document root | `/var/www/planvio/public` | `/home/you/public_html` |
| What is reachable over HTTP | Only `public/` | Everything, unless `.htaccess` says otherwise |
| Protects `.env` by | The filesystem | `.htaccess` rules |
| Works on nginx | Yes | **No** |
| Recommended | **Yes** | Only when your host forbids changing the document root |

### Layout A — document root at `public/`

```
/var/www/planvio/
├── app/
├── bootstrap/
├── config/
├── database/
├── public/          <-- document root
├── resources/
├── routes/
├── storage/
├── vendor/
└── artisan
```

Your `.env`, database credentials, encryption key, application source and every uploaded
attachment sit above the document root. There is no URL that reaches them. If a rewrite
rule breaks, the worst outcome is a broken site — not a leaked credential.

### Layout B — everything in `public_html`

Extract the release directly into `public_html`, so you get `public_html/app`,
`public_html/config`, `public_html/vendor`, `public_html/public` and so on. Change nothing
else: the root `.htaccess` that ships with the release forwards requests into `public/` and
blocks the rest.

**The honest trade-off.** In layout B your application files are inside the web root, and
the only thing keeping them private is `.htaccess` being honoured. That is a real
dependency, not a theoretical one:

- If your host sets `AllowOverride None`, every deny rule in the file stops applying and
  `/.env`, `/config/planvio.php` and `/storage/logs/laravel.log` become fetchable.
- If a future server migration moves you from Apache to nginx, the file is ignored entirely
  and nothing replaces it.
- Directory listings, backup files a control panel drops in place, and editor swap files
  all land inside the served tree.

Layout A does not have those failure modes because there is no path from the web root to
those files at all. Use layout B only when your host genuinely will not let you change the
document root, and verify the protection afterwards:

```
https://yourdomain.com/.env          -> must return 403, not a download
https://yourdomain.com/storage/      -> must not list files
https://yourdomain.com/composer.json -> must return 403
```

Layout B is Apache and LiteSpeed only. nginx does not read `.htaccess` files, so there is
nothing to forward requests into `public/` and nothing to deny the rest. **On nginx, use
layout A.**

---

## Upload limits

Four numbers have to agree, or uploads fail with a confusing error. Planvio's own limit
comes from `PLANVIO_MAX_UPLOAD_KB` (default `20480`, i.e. 20 MB per file), read through
`config/planvio.php → uploads.max_size_kb`.

| Setting | Value for the 20 MB default | Why |
|---|---|---|
| `PLANVIO_MAX_UPLOAD_KB` | `20480` | Planvio rejects anything larger, with a clear message |
| PHP `upload_max_filesize` | `20M` | Must be at least Planvio's limit |
| PHP `post_max_size` | `24M` | The whole multipart body, which is larger than the file |
| nginx `client_max_body_size` | `24m` | Match `post_max_size`, or nginx returns 413 before PHP sees anything |
| Apache `LimitRequestBody` | `25165824` | 24 MB in bytes; `0` disables the check |

Avatars are capped separately at 2 MB (`uploads.avatar_max_size_kb`) and that value is not
configurable through the environment.

If you lower `PLANVIO_MAX_UPLOAD_KB`, you do not need to touch the others. If you raise it,
raise all four.

---

## nginx

For layout A. Adjust `server_name`, `root`, the certificate paths and the PHP-FPM socket.

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name projects.example.com;

    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;                       # nginx < 1.25.1: use `listen 443 ssl http2;` above

    server_name projects.example.com;
    root /var/www/planvio/public;   # the public/ directory, never the project root
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/projects.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/projects.example.com/privkey.pem;

    charset utf-8;

    # Must be at least PHP's post_max_size. See the upload limits table.
    client_max_body_size 24m;

    # Streaming an attachment out of storage/app/private can take a while on a slow link.
    send_timeout 300;

    server_tokens off;

    # ---------------------------------------------------------------------
    # Security headers. These mirror what public/.htaccess sets on Apache.
    # NOTE: add_header does not inherit into a location that sets its own,
    # so any location block below that adds a header repeats these.
    # ---------------------------------------------------------------------
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Cross-Origin-Opener-Policy "same-origin" always;
    add_header Permissions-Policy "geolocation=(), microphone=(), camera=(), payment=(), usb=(), interest-cohort=()" always;

    # Enable only once HTTPS works on every hostname you serve. It is hard to undo.
    # add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types text/plain text/css text/xml application/javascript application/json
               application/xml image/svg+xml;

    # ---------------------------------------------------------------------
    # Dotfiles: never served. ACME challenges are the one exception.
    # ---------------------------------------------------------------------
    location ~ /\.(?!well-known) {
        return 404;
    }

    # ---------------------------------------------------------------------
    # public/storage is a symlink to storage/app/public and carries avatars and
    # workspace logos ONLY. Task and comment attachments are never placed here:
    # they live in storage/app/private, outside the document root, and are
    # streamed by an authorising controller. Serve these, never execute them.
    # ---------------------------------------------------------------------
    location ^~ /storage/ {
        location ~* \.(php|phtml|phar|php[3-8]|inc|pl|py|cgi|sh|bash|htaccess)$ {
            return 404;
        }

        autoindex off;
        try_files $uri =404;

        add_header X-Content-Type-Options "nosniff" always;
        add_header Content-Disposition "inline" always;
    }

    # ---------------------------------------------------------------------
    # Fingerprinted Vite output. Safe to cache forever.
    # ---------------------------------------------------------------------
    location ^~ /build/ {
        expires 1y;
        access_log off;
        try_files $uri =404;

        add_header Cache-Control "public, immutable" always;
        add_header X-Content-Type-Options "nosniff" always;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    # ---------------------------------------------------------------------
    # Front controller.
    # ---------------------------------------------------------------------
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # ---------------------------------------------------------------------
    # PHP. index.php is the only executable file in the document root.
    # ---------------------------------------------------------------------
    location ~ ^/index\.php(/|$) {
        include fastcgi_params;

        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_hide_header X-Powered-By;

        # Long enough for a queued AI run started from the browser to finish, and for
        # a large attachment download to complete.
        fastcgi_read_timeout 300;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
    }

    # Anything else ending in .php is not ours. 404, not 403 — do not confirm it exists.
    location ~ \.php$ {
        return 404;
    }

    error_log  /var/log/nginx/planvio.error.log;
    access_log /var/log/nginx/planvio.access.log;
}
```

Two ordering details that matter: regex `location` blocks are matched in the order they are
written, so `^/index\.php(/|$)` must come before `\.php$`; and `^~` prefix matches beat
regex matches, which is why `/storage/` and `/build/` are written that way.

The release's `.htaccess` files do nothing under nginx. Everything they would have enforced
is in the block above — if you edit one, edit the other.

---

## Apache

For layout A. `mod_rewrite`, `mod_headers` and `mod_deflate` should be enabled;
`public/.htaccess` uses all three.

```apache
<VirtualHost *:80>
    ServerName projects.example.com
    Redirect permanent / https://projects.example.com/
</VirtualHost>

<VirtualHost *:443>
    ServerName projects.example.com
    DocumentRoot /var/www/planvio/public

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/projects.example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/projects.example.com/privkey.pem

    # 24 MB. Match PHP's post_max_size. See the upload limits table.
    LimitRequestBody 25165824

    # Everything above the document root is unreachable. Apache applies Directory
    # sections shortest path first, so the more specific block below wins for public/.
    <Directory /var/www/planvio>
        Require all denied
        AllowOverride None
        Options None
    </Directory>

    <Directory /var/www/planvio/public>
        Require all granted

        # Let the shipped public/.htaccess do its job: rewrites, security headers,
        # the storage guard and the cache policy all live there.
        AllowOverride All

        Options -Indexes -MultiViews +FollowSymLinks
    </Directory>

    # PHP-FPM. Adjust the socket path for your distribution.
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost/"
    </FilesMatch>

    ProxyTimeout 300

    ServerSignature Off

    ErrorLog  /var/log/apache2/planvio.error.log
    CustomLog /var/log/apache2/planvio.access.log combined
</VirtualHost>
```

### If you must use `AllowOverride None`

Some operators refuse `.htaccess` on principle. Then the rules have to move into the vhost.
This is the minimum from `public/.htaccess` — the rewrite behaviour and the storage guard:

```apache
    <Directory /var/www/planvio/public>
        Require all granted
        AllowOverride None
        Options -Indexes -MultiViews +FollowSymLinks

        RewriteEngine On

        # CGI/FastCGI strips Authorization; API tokens depend on it.
        RewriteCond %{HTTP:Authorization} .
        RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

        # Dotfiles, except ACME challenges.
        RewriteRule (^|/)\.(?!well-known/) - [F,L]

        # Trailing slash on a non-directory.
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteCond %{REQUEST_URI} (.+)/$
        RewriteRule ^ %1 [L,R=301]

        # Front controller.
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteRule ^ index.php [L]

        # Nothing under the storage symlink is ever executed.
        RewriteRule ^storage/.*\.(php|phtml|phar|php[3-8]|inc|pl|py|cgi|sh|htaccess)$ - [F,L,NC]

        Header always set X-Content-Type-Options "nosniff"
        Header always set X-Frame-Options "SAMEORIGIN"
        Header always set Referrer-Policy "strict-origin-when-cross-origin"
        Header always set Cross-Origin-Opener-Policy "same-origin"
        Header always set Permissions-Policy "geolocation=(), microphone=(), camera=(), payment=(), usb=(), interest-cohort=()"
        Header always unset X-Powered-By
    </Directory>
```

Adopting this means you now own those rules: a future release that changes
`public/.htaccess` will not change your vhost.

### LiteSpeed and OpenLiteSpeed

LiteSpeed reads `.htaccess` and both shipped files are written for it — the storage guard
uses `<IfModule lsapi_module>` with `RemoveHandler` alongside the Apache `php_flag engine
off`. Configure it like Apache: point the document root at `public/` and leave
`AllowOverride` permissive. On OpenLiteSpeed, `.htaccess` support is off by default; turn
it on for the virtual host, or port the rules as above.

---

## Permissions and ownership

Two identities matter: the account that owns the files (call it `deploy`) and the account
PHP runs as (`www-data` on Debian and Ubuntu, `apache` or `nginx` elsewhere, your cPanel
username on shared hosting). PHP must be able to write four places and no more.

```bash
# Ownership: owned by you, group-shared with PHP.
chown -R deploy:www-data /var/www/planvio

# Baseline: directories traversable, files readable, nothing writable.
find /var/www/planvio -type d -exec chmod 0755 {} \;
find /var/www/planvio -type f -exec chmod 0644 {} \;

# The four writable trees.
chmod -R 0775 /var/www/planvio/storage
chmod -R 0775 /var/www/planvio/bootstrap/cache

# Attachments. Group-only: nothing outside the application should read them.
chmod 0770 /var/www/planvio/storage/app/private

# Secrets.
chmod 0640 /var/www/planvio/.env

# artisan needs to be executable for cron.
chmod 0755 /var/www/planvio/artisan
```

| Path | Mode | Who writes it |
|---|---|---|
| Everything else | `0755` / `0644` | Nobody at runtime |
| `storage/` | `0775` | PHP — sessions, cache, views, logs |
| `storage/app/private/` | `0770` | PHP — every attachment |
| `storage/app/public/` | `0775` | PHP — avatars and workspace logos |
| `bootstrap/cache/` | `0775` | PHP — compiled config, routes, packages |
| `.env` | `0640` | Nobody. Read by PHP only |

If your host runs PHP as your own user (CloudLinux, most shared hosting), `0755` is enough
everywhere and `0775` is unnecessary. If you find yourself reaching for `0777`, the
ownership is wrong — fix that instead.

### The storage symlink

```bash
cd /var/www/planvio && php artisan storage:link
```

This creates `public/storage` pointing at `storage/app/public`. It carries **avatars and
workspace logos only**. Task and comment attachments are never placed there: they live in
`storage/app/private`, outside the document root, and are streamed by a controller that
authorises the request first. That separation is deliberate — do not "fix" a missing
attachment by moving it into `public/`.

Some shared hosts disable PHP's `symlink()`. If `storage:link` fails, avatars and logos will
not resolve. Creating the link by hand through a file manager works if the panel offers it.

---

## Production environment

Copy `.env.example` and edit. `.env.example` documents every supported key; these are the
ones that decide whether your install is safe.

| Key | Production value | Why |
|---|---|---|
| `APP_ENV` | `production` | Turns off development affordances across the framework |
| `APP_DEBUG` | `false` | **The single most important line in the file.** With it `true`, an unhandled exception renders a stack trace containing your database credentials, your `APP_KEY` and the contents of `.env` |
| `APP_KEY` | generated, never changed | Encrypts `ai_providers.api_key`, `users.two_factor_secret` and `users.two_factor_recovery_codes`. Change it and those values become permanently unreadable |
| `APP_URL` | `https://projects.example.com` | Used for generated links, password resets and the `public` disk URL |
| `APP_INSTALLED` | `true` | Written by the installer. Do not set it by hand |
| `SESSION_SECURE_COOKIE` | `true` | Marks the session cookie `Secure`, so it is never sent over plain HTTP. Set it `false` only for a plain-HTTP install on a trusted network, and understand that the session is then interceptable |
| `SESSION_SAME_SITE` | `lax` | Default. `strict` breaks links from email |
| `SESSION_ENCRYPT` | `false` | Sessions live in your own database. Set `true` if that database is shared with anyone |
| `LOG_LEVEL` | `error` | `debug` fills the disk and records request context you did not intend to keep |
| `LOG_STACK` | `daily` | With `LOG_DAILY_DAYS=14`, rotation is automatic and bounded |
| `SESSION_DRIVER` | `database` | No Redis, no file locking contention |
| `CACHE_STORE` | `database` | Same |
| `QUEUE_CONNECTION` | `database` | Same. The queue is drained by cron, not a daemon |
| `MAIL_MAILER` | `smtp` | Plain SMTP. No transactional email provider needed |
| `AI_ENABLED` | `false` unless you want AI | The outermost gate. When `false`, no AI route, job, tool or provider call runs anywhere. Nothing else changes |

Things that must never be in `.env`: an AI provider API key (those live encrypted in the
`ai_providers` table), and `AI_STORE_PROMPTS=true` (prompt bodies contain workspace
content).

### Verify `APP_DEBUG` is actually off

Setting it in `.env` is not proof — a cached config from a previous deploy overrides the
file. After deploying, request a URL that does not exist:

```
https://projects.example.com/this-does-not-exist
```

You should get Planvio's 404 page. If you get a coloured stack trace with a file listing,
debug is on and your credentials are on screen. Fix it and clear the config cache before
doing anything else.

---

## Cron

Two entries. Both run as the account that owns the files.

```cron
* * * * * /usr/bin/php8.3 /var/www/planvio/artisan schedule:run >/dev/null 2>&1
*/5 * * * * /usr/bin/php8.3 /var/www/planvio/artisan queue:work database --queue=default,ai --stop-when-empty --max-time=280 --tries=3 >/dev/null 2>&1
```

**The scheduler** must run every minute. Laravel's scheduler decides internally what is due;
a less frequent cron silently skips work.

**The queue worker** is short-lived on purpose. `--stop-when-empty` processes whatever is
waiting and exits, so there is no daemon to supervise and no process to leak memory.
`--max-time=280` keeps it comfortably inside a five-minute window even if work keeps
arriving.

### If AI is enabled, name the queues

AI jobs go to a separate queue — `config/ai.php → queue.name`, default `ai` (set by
`AI_QUEUE`). A worker started without `--queue` only drains `default`, so AI runs would sit
untouched forever. Use:

```cron
*/5 * * * * /usr/bin/php8.3 /var/www/planvio/artisan queue:work database --queue=default,ai --stop-when-empty --max-time=280 --tries=3 >/dev/null 2>&1
```

Queues are drained left to right, so `default` work is prioritised over AI work. That is
usually what you want: an AI run is allowed to wait, a notification email is not.

### Getting the PHP binary right

Use the absolute path to the same PHP version the web server uses, not `php`. Cron's `PATH`
is minimal and frequently points at a different build. Common locations:

```
/usr/bin/php8.3
/usr/local/bin/php83
/opt/cpanel/ea-php83/root/usr/bin/php
/opt/alt/php83/usr/bin/php
```

A mismatched binary usually shows up as a scheduler that "runs" but never does anything,
because a missing extension makes it fail before it reaches the schedule.

---

## Production caches

Four caches. They turn per-request work into a compiled file in `bootstrap/cache/`.

```bash
cd /var/www/planvio

php artisan config:cache     # merges every config/*.php into one file
php artisan route:cache      # serialises the route table
php artisan view:cache       # precompiles Blade templates
php artisan event:cache      # precompiles the event/listener map
```

`php artisan optimize` runs all four. `php artisan optimize:clear` reverses all four plus
the application cache.

### When NOT to cache config

**Never cache config before or during installation.** `config:cache` freezes the merged
configuration into `bootstrap/cache/config.php`, and once that file exists, `env()` returns
`null` everywhere outside `config/*.php`. Two consequences:

- The installer writes `.env` as its final step. If a cached config already exists, the
  application keeps using the values captured *before* installation — including the database
  credentials, which will be the placeholders from `.env.example`. The installer appears to
  succeed and the application then cannot connect.
- Any later change to `.env` — a new SMTP password, a corrected `APP_URL`, flipping
  `AI_ENABLED` — has no effect until the cache is rebuilt.

So the order is always:

1. Deploy files.
2. **Clear** the config cache: `php artisan config:clear`.
3. Run the installer (first install) or the upgrade screen (update).
4. **Then** `php artisan config:cache`.

The same rule applies to `optimize`, which includes `config:cache`.

`route:cache` has a separate requirement: it fails if any route uses a closure. `view:cache`
and `event:cache` are always safe, and both are worth doing on shared hosting where
compiling Blade on first request is noticeably slow.

### Running artisan when you have no shell

Add a cron job scheduled a couple of minutes out, wait for it to fire, then delete it:

```cron
17 14 * * * /usr/bin/php8.3 /var/www/planvio/artisan optimize >/tmp/planvio-optimize.log 2>&1
```

Redirecting to a file rather than `/dev/null` lets you read what happened. Most control
panels can also email cron output — set the notification address before you rely on it.

---

## Updating

Planvio has no CLI deploy tool, no symlinked releases directory and no blue/green switch.
What it has is a version check on boot: when the deployed `config/planvio.php → version` is
newer than the version recorded in the database, Planvio shows an upgrade screen that lists
the pending migrations before running anything.

The procedure below keeps the window where the site is unavailable down to the time it takes
to change one setting and run the migrations. It is not zero downtime. It is short and
reversible.

### Without SSH

1. **Back up first.** Database export plus `storage/app/private` and `.env`. See
   [Backup](#backup). Do not skip this because the release is "just a patch" — migrations
   are the part that is hard to undo.
2. **Note the current release path**, e.g. `/home/deploy/planvio-1.0.0`.
3. **Upload and extract the new release** into a *new* sibling directory, e.g.
   `/home/deploy/planvio-1.1.0`. Never extract over a running installation: a release ZIP
   does not delete files that were removed since the last version, so you end up running a
   mixture of two releases.
4. **Copy your state across**, from the old directory into the new one:
   - `.env`
   - `storage/app/private/` (every attachment)
   - `storage/app/public/` (avatars and workspace logos)
   - `storage/app/planvio-installed.lock`
5. **Set the permissions** on the new tree — `storage/` and `bootstrap/cache/` writable, as
   in [Permissions](#permissions-and-ownership).
6. **Put the running site into maintenance mode** from the admin UI, so nobody writes to the
   database while the migrations run.
7. **Repoint the document root** to `/home/deploy/planvio-1.1.0/public`. This is the switch,
   and it is a single change in one place.
8. **Open the site.** Planvio detects the version change and offers to run the pending
   migrations, showing you what will change first.
9. **Rebuild the caches** (`optimize`), via the one-shot cron trick if you have no shell.
10. **Take maintenance mode off** and check that you can sign in, open a project and download
    an attachment.
11. **Keep the old directory for a week.** Then delete it.

**Rolling back** means repointing the document root at the old directory — and it only works
if step 8 did not run. Once a migration has changed the schema, the previous release's code
no longer matches the database, and the only route back is restoring the backup from step 1.
Say so out loud before you start: this is why step 1 is not optional.

### With SSH

Same shape, fewer clicks. Serve through a symlink so the switch is atomic:

```bash
# One-time: document root points at /var/www/planvio-current/public
ln -s /var/www/planvio-1.0.0 /var/www/planvio-current

# Each update
cd /var/www
unzip planvio-v1.1.0.zip -d planvio-1.1.0
cp planvio-1.0.0/.env planvio-1.1.0/.env
cp planvio-1.0.0/storage/app/planvio-installed.lock planvio-1.1.0/storage/app/
cp -a planvio-1.0.0/storage/app/private/. planvio-1.1.0/storage/app/private/
cp -a planvio-1.0.0/storage/app/public/.  planvio-1.1.0/storage/app/public/
chown -R deploy:www-data planvio-1.1.0
chmod -R 0775 planvio-1.1.0/storage planvio-1.1.0/bootstrap/cache

cd planvio-1.1.0
php artisan config:clear
php artisan migrate --force
php artisan storage:link
php artisan optimize

ln -sfn /var/www/planvio-1.1.0 /var/www/planvio-current
systemctl reload php8.3-fpm      # drop the stale realpath cache
```

`ln -sfn` replaces the symlink in one operation. Reload PHP-FPM afterwards or requests keep
resolving the old path out of the realpath cache.

---

## Backup

### What to back up

| | Why |
|---|---|
| **The database** | Everything: projects, tasks, comments, activity, settings, AI configuration and history |
| **`storage/app/private/`** | Every task and comment attachment. Not in the database, not in the release |
| **`storage/app/public/`** | Avatars and workspace logos |
| **`.env`** | Contains `APP_KEY`. Without the same key, `ai_providers.api_key` and every user's two-factor secret and recovery codes are unrecoverable — a database restore alone will not bring them back |

### What not to bother with

`vendor/`, `public/build/` and the application source all ship inside the release ZIP —
re-download it instead. `storage/framework/`, `storage/logs/` and `bootstrap/cache/` are
regenerated.

### An example nightly job

```cron
30 2 * * * mysqldump --single-transaction --quick --default-character-set=utf8mb4 -u planvio -p'...' planvio | gzip > /home/deploy/backups/planvio-$(date +\%F).sql.gz
45 2 * * * tar -czf /home/deploy/backups/planvio-files-$(date +\%F).tar.gz -C /var/www/planvio storage/app/private storage/app/public .env
```

`--single-transaction` gives a consistent dump of InnoDB tables without locking the site.
Put the output somewhere that is not the same disk, and test a restore before you need one.

### Restoring

1. Create an empty database and user.
2. Import the dump.
3. Extract the release of the **same version** the dump came from.
4. Restore `.env` — the original file, with the original `APP_KEY`.
5. Restore `storage/app/private/` and `storage/app/public/`.
6. Fix permissions, point the document root, `php artisan config:clear`, then `optimize`.

### The honest part

**Planvio does not implement server-wide backup.** There is no backup command, no scheduled
database dump, no snapshot UI, and no restore tooling. Nothing in the application will
notice that you have not backed up, and nothing will stop you deleting a workspace that has
no copy anywhere.

`config/planvio.php → retention` is not backup. It is the opposite: it *deletes* data on a
schedule — activity rows (`PLANVIO_ACTIVITY_RETENTION_DAYS`, `0` keeps them forever), audit
logs (`PLANVIO_AUDIT_RETENTION_DAYS`, default 730), notifications after 120 days and webhook
deliveries after 30.

Hosting-level backup remains necessary. Use your host's snapshots, a cron job like the one
above, or both — and confirm at least once that you can actually restore from them.

---

## Production readiness checklist

**Web server**

- [ ] Document root is `public/`, not the project root (layout A), or the layout B
      verification URLs all return 403
- [ ] `https://yourdomain.com/.env` returns 403 or 404, not a download
- [ ] `https://yourdomain.com/storage/` does not list files
- [ ] `https://yourdomain.com/storage/anything.php` does not execute
- [ ] HTTP redirects to HTTPS, and the certificate covers every hostname you serve
- [ ] `client_max_body_size` / `LimitRequestBody` matches PHP's `post_max_size`

**PHP**

- [ ] 8.3 or 8.4, with every required extension loaded
- [ ] `memory_limit` at least `256M`
- [ ] `upload_max_filesize` and `post_max_size` at or above Planvio's upload limit
- [ ] `expose_php = Off`

**Environment**

- [ ] `APP_DEBUG=false`, confirmed by requesting a URL that does not exist
- [ ] `APP_ENV=production`
- [ ] `APP_URL` starts with `https://`
- [ ] `SESSION_SECURE_COOKIE=true`
- [ ] `LOG_LEVEL=error`
- [ ] `.env` is mode `0640` and not readable over HTTP
- [ ] `APP_KEY` is backed up somewhere other than the server

**Filesystem**

- [ ] `storage/` and `bootstrap/cache/` are writable by PHP
- [ ] `storage/app/private/` is `0770` and outside the document root
- [ ] `public/storage` exists and resolves
- [ ] No `.env.backup`, `.zip` or editor swap files anywhere under the document root

**Runtime**

- [ ] The scheduler cron runs every minute with an absolute PHP path
- [ ] The queue cron runs and includes `--queue=default,ai` if AI is enabled
- [ ] `/up` returns 200
- [ ] A test email arrives
- [ ] Sign-in works and the interface renders with styles

**Operations**

- [ ] Config, route, view and event caches were built **after** installation, not before
- [ ] A database backup runs on a schedule and you have restored from it once
- [ ] `storage/app/private` is included in that backup
- [ ] You know which release version is deployed and have kept the ZIP

---

## Limitations

Stated plainly, so you plan around them rather than discover them.

**No backup, and no restore.** Covered above. This is the largest operational gap and it is
entirely on you.

**No zero-downtime deployment.** The document-root or symlink swap is fast, but migrations
run against a live schema and there is a window where old code could meet a new schema. Put
the site in maintenance mode for the migration step. There is no rolling deploy, no
migration compatibility layer and no automated rollback.

**Rollback after a migration means restoring a backup.** Repointing the document root at the
previous release does not undo a schema change.

**nginx ignores the shipped hardening.** Both `.htaccess` files — the root one and
`public/.htaccess` — are Apache/LiteSpeed only. Under nginx you own the equivalent rules,
and a future release that tightens `.htaccess` will not tighten your server block. Diff them
when you upgrade.

**Layout B depends on `AllowOverride`.** If your host disables it, the protection disappears
silently. There is no runtime check that would catch this for you today.

**`storage:link` needs `symlink()`.** Hosts that disable it leave avatars and workspace logos
broken. Attachments are unaffected — they never use the symlink.

**No first-party monitoring or alerting.** `/up` is Laravel's health endpoint and reports
that the framework booted; it does not check the database, the queue backlog, disk space or
whether cron has run recently. Point an external uptime monitor at it and treat that as the
floor, not the ceiling.

**Nothing tells you the queue has stalled.** If the queue cron stops firing, jobs
accumulate in the `jobs` table and no notification is sent. Failed jobs land in
`failed_jobs` and stay there.

**Windows and IIS are not supported or documented.** Planvio is developed on Windows but
deployed on Linux; no IIS configuration ships and none is tested.

**Not yet built in this tree.** Several things this document and [CPANEL.md](CPANEL.md)
refer to are specified in [ARCHITECTURE.md](ARCHITECTURE.md) but are not present in the
current source: the `/install` wizard, the upgrade screen, the admin **System Health** and
**System Information** pages, the database-backed maintenance mode toggle (its config keys
exist in `config/planvio.php → maintenance`, the UI does not), and the authorising
attachment controller. `routes/console.php` also registers no scheduled tasks yet, so the
one-minute cron is harmless but currently does nothing. Until those land, verify the
checklist above by hand rather than from a status page.

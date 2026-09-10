# Hosting providers

Notes on specific hosts, contributed by people running Planvio on them.

**This page is community-maintained and unverified.** Nobody tests Planvio against every
host, and details change without notice — a provider that used PHP 8.1 last year may offer
8.4 now. Treat everything here as a starting point, and please correct anything you find to
be wrong. Editing this wiki does not require any permission.

If you get Planvio running somewhere not listed, adding a few lines here is genuinely one
of the more useful contributions available.

---

## What to record

The three things that vary between hosts and cost people the most time:

1. **The CLI PHP binary path.** Shared hosts frequently have a different PHP for cron than
   for the web, and the default `php` on the cron path is often an old one. Getting this
   wrong is the single most common reason the scheduler never runs.
2. **How the document root is set,** because it must point at `planvio/public` and not at
   `planvio`.
3. **Anything unusual** — a symlink restriction, a `disable_functions` entry, a process
   limit that a queue worker trips over.

A useful entry looks like:

```
### Provider name

- PHP:            selectable 8.1–8.4, chosen in <where>
- CLI PHP path:   /usr/local/bin/ea-php83
- Document root:  set in <where>
- Symlinks:       allowed / not allowed
- Notes:          anything that surprised you
```

---

## cPanel with CloudLinux

The configuration Planvio is designed around, and the one with a dedicated guide:
[docs/CLOUDLINUX.md](https://github.com/hatemsweileh/planvio/blob/main/docs/CLOUDLINUX.md).

- **PHP:** chosen per-domain in *Select PHP Version* (PHP Selector). Extensions are ticked
  on the same screen — this is where a missing `intl` or `zip` is fixed.
- **CLI PHP path:** usually `/usr/local/bin/ea-php83` or `/opt/alt/php83/usr/bin/php`.
  Plain `php` in a cron entry often resolves to the system PHP, which may be far older than
  the one your site uses. If the scheduler never runs, check this first.
- **Document root:** *Domains* → the domain → *Document Root*, pointed at
  `public_html/planvio/public` or wherever you extracted it.
- **LVE limits:** CloudLinux caps concurrent processes and memory per account. The queue
  worker is short-lived by design and does not normally trouble these, but a very low
  `nproc` limit can make cron entries fail intermittently rather than consistently, which
  is a confusing failure to diagnose.
- **CageFS:** each account sees its own filesystem. Nothing in Planvio minds this, but it
  means a path you found in a general tutorial may not exist for you.

---

## Plesk

- **PHP:** *Websites & Domains* → *PHP Settings*, per domain.
- **Document root:** *Hosting Settings* → *Document root*.
- **Cron:** *Scheduled Tasks*. Choose "Run a PHP script" and Plesk supplies the correct
  binary for the domain's PHP version, which avoids the path problem above entirely.

---

## A plain VPS

Not shared hosting, but the most common non-cPanel case.

- Full walkthrough, including an Nginx server block:
  [docs/DEPLOYMENT.md](https://github.com/hatemsweileh/planvio/blob/main/docs/DEPLOYMENT.md).
- The cron entries go in the web user's crontab (`www-data`, `nginx`, whatever runs PHP-FPM)
  rather than root's — otherwise the files the jobs create are owned by root and the next
  web request cannot write them.
- You *may* run a long-lived queue worker under systemd here, since you have one. Planvio
  does not need it and the cron entry still works; it just lowers the latency on
  notifications.

---

## Docker

Planvio does not ship a Dockerfile, and that is a decision rather than an omission — the
product exists to run where Docker does not.

Nothing prevents you writing one. If you do and it works well, a link here would be useful
to others. What it needs is ordinary: PHP 8.3+ with the listed extensions, a web server
pointed at `public/`, and the two scheduled jobs as either cron inside the container or
external invocations of `artisan`.

---

## Known not to work

- **Hosts without `proc_open`.** Some very restricted shared plans disable it. The queue
  worker and the scheduler both need to spawn a process.
- **PHP below 8.3.** The floor is real, not cautious.
- **Hosts that only offer PostgreSQL.** Planvio targets MySQL and MariaDB; the migrations
  are not written for PostgreSQL and it is listed in
  [LIMITATIONS.md](https://github.com/hatemsweileh/planvio/blob/main/docs/LIMITATIONS.md).

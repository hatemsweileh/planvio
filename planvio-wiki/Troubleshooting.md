# Troubleshooting

Symptom first, because that is what you have.

Before anything else: **`storage/logs/`**. Planvio writes a daily log there, and almost
everything below leaves a line in it. If you can reach the administration area,
**Admin → System Health** runs the same checks a person would.

---

## The page is blank, or a bare "500"

A production install has `APP_DEBUG=false`, so a fatal error renders the branded error page
rather than a stack trace. The detail is in `storage/logs/laravel-<date>.log`.

The usual causes, in the order they occur:

**`storage/` is not writable.** Planvio writes sessions, cache, compiled views and logs
there. On cPanel, `storage/` and `bootstrap/cache/` need `755` and to be owned by the
account that PHP runs as. If the log file itself cannot be created you will get a blank
page and no log, which is the confusing case — check the directory permissions before
concluding there is nothing wrong.

**A missing PHP extension.** The installer checks these, but a host that later switches
PHP version can drop one. Compare `php -m` against the extension list on the
[[Home]] page.

**`APP_KEY` changed or is empty.** Anything encrypted with the old key — provider API keys,
two-factor secrets — cannot be read back, and sessions break. Never regenerate the key on
an installation that already has data. If it has been lost, see the FAQ.

---

## Everything is unstyled — plain text on a white page

The document root is pointing at the Planvio folder rather than at `planvio/public`.

The browser is fetching `/build/assets/…` and getting a 404 because those files are one
directory further down than the server thinks. It is the single most common shared-hosting
mistake and it looks alarming, but nothing is damaged: fix the document root and reload.

The give-away is that the sign-in page renders as readable but unstyled text, and the
browser's network tab shows 404s for the CSS and JS.

---

## Nothing happens on a schedule — reminders, recurring tasks, digests

The scheduler cron is missing or wrong.

Planvio records a heartbeat every time the scheduler runs, so this is checkable rather than
guessable: **Admin → System Health** shows when the scheduler was last seen. If it says
never, the cron has never fired.

Things that quietly stop without it: recurring tasks are never created, due-soon
notifications never send, webhook retries never happen, and old activity is never pruned.

See [docs/CRON.md](https://github.com/hatemsweileh/planvio/blob/main/docs/CRON.md) for the
exact entries. Two frequent mistakes: giving a PHP binary path that is not the one your
host wants for CLI (many cPanel hosts want `/usr/local/bin/ea-php83` rather than `php`),
and pointing the cron at the wrong directory so `artisan` is not found.

---

## Emails and notifications never arrive, but nothing errors

There are two separate cron jobs and this is the second one.

Planvio queues notifications rather than sending them during the request — a slow SMTP
server should never make saving a task slow. Jobs sit in the `jobs` table until a worker
drains them, and the worker is a short-lived process invoked by cron.

If the scheduler is running but mail never arrives, check the queue entry specifically.
A growing `jobs` table with nothing being removed is the confirmation.

Then, separately, check the mail configuration itself: **Admin → Settings** has a send-test
button, and a failure there is an SMTP problem rather than a queue one.

---

## "419 Page Expired" when signing in or submitting a form

The session was not where the next request looked for it.

- **Sessions are stored in the database by default.** If the `sessions` table is missing,
  every request starts a new session and every form fails this way.
- **`APP_URL` does not match the address in the browser.** Cookies are scoped to a domain;
  a mismatch between `https://planvio.example.com` and `http://planvio.example.com`, or
  between `www` and bare, means the cookie is set for one and read for the other.
- **Behind a proxy or a load balancer** the request may arrive as HTTP while the browser
  sent HTTPS. See the trusted-proxy notes in
  [docs/DEPLOYMENT.md](https://github.com/hatemsweileh/planvio/blob/main/docs/DEPLOYMENT.md).

---

## Uploaded files and avatars give a 404

`public/storage` is missing. It is a symlink to `storage/app/public`, and Planvio's
installer creates it — but a host that does not allow symlinks, or a migration that copied
files without following them, leaves it absent.

The installer can recreate it, and **Admin → System Health** reports whether it exists.
On a host that genuinely forbids symlinks, a directory alias in the web server
configuration does the same job.

---

## The AI does nothing, or says it is unavailable

Work through it in this order — each rules out the one below:

1. **Is a provider configured?** Admin → AI providers. Without one, the assistant is off
   and says so.
2. **Is the workspace's AI mode set?** Assistant answers but changes nothing; Copilot
   proposes and waits; Autonomous acts within limits. A workspace left in Assistant will
   never take an action, which is correct rather than broken.
3. **Is the kill switch on?** It halts autonomous execution across the installation without
   disabling anything else. Someone may have used it and not said.
4. **Is the queue running?** Agent runs are queued. No queue worker means a run that is
   accepted and then never starts — the tell-tale is a run stuck in "queued".
5. **Is anything waiting for approval?** Destructive tools always require it. Check the
   approval queue before concluding the model ignored you.

Every tool call is recorded with its arguments, result, risk level and outcome —
Admin → AI tool runs is the place to see what it actually tried.

---

## Arabic does not appear, or a language is missing from the picker

A language exists in `locales` and is *offered* separately. A code stored on a user is not
permission to render in it: the middleware accepts it only when a `locales` row carries it
**and** is enabled. So a language switched off disappears from every picker immediately,
without touching a single user row.

Check **Admin → Platform → Languages**. If Arabic is listed but not enabled, enable it.

If a language renders but parts of it are English, that is the catalogue rather than a
fault — Admin → Platform → Translations shows what is untranslated, and
[docs/LOCALISATION.md](https://github.com/hatemsweileh/planvio/blob/main/docs/LOCALISATION.md)
covers the three categories that are deliberately left as data.

---

## Composer fails on PHP 8.3 with a Symfony version conflict

If you regenerate `composer.lock` yourself, do it with the platform pin in place —
`composer.json` sets `config.platform.php` to `8.3.0` precisely so the lock resolves for
the oldest supported PHP rather than for whatever is on the machine that ran the command.

Note that `composer check-platform-reqs` answers for the PHP actually running, not for the
pin, so it will not catch this. `composer why-not php 8.3` will.

---

## Still stuck

Open a [Q&A discussion](https://github.com/hatemsweileh/planvio/discussions) with the
output of **Admin → System Information** — redacting anything sensitive. It carries the
PHP version, database, server, extensions and Planvio version, which is most of what
anyone would otherwise have to ask you for.

# Known limitations

Everything below is a deliberate boundary of Planvio 1.0.0, stated plainly so you can
decide whether it matters to you before you deploy rather than after.

Nothing here is a bug. Bugs get fixed; these are things Planvio does not do, or does in a
particular way for a particular reason.

---

## AI

**Responses are not streamed.** You send a message, the run is queued, and the interface
polls for the result. There is no token-by-token typing effect. This follows directly from
the hosting target: Planvio has no persistent worker, so an agent run is a queued job
drained by cron. If your queue cron runs every five minutes, a reply can wait that long —
run it every minute for a responsive assistant. See [QUEUE.md](QUEUE.md).

**No vector search or RAG.** Context retrieval is structured and keyword-based against
MySQL: the AI is handed the focused project, the focused task, recent activity, relevant
memories and bounded search results. It is predictable and cheap, and it will not find a
task by conceptual similarity the way an embedding index would. The context layer is built
as a set of `ContextProvider` implementations specifically so a vector backend can be added
later without touching a single tool.

**The AI does not read your attachments.** It works with text and structured records.
Uploading a PDF and asking what is in it will not work.

**Token counts depend on the provider.** Some OpenAI-compatible endpoints do not return
usage data. Those runs record zero tokens rather than an estimate, because a guess in a
usage report is worse than a gap.

**No currency cost is displayed.** Providers do not return per-request pricing. Planvio
shows token counts and request counts; apply your own rates. A figure Planvio invented and
presented as a cost would be a fabrication in an accounting screen.

**The Custom HTTP driver cannot use tools.** It supports assistant-style answers only. Tool
calling requires the OpenAI or Anthropic wire format.

**One active provider per workspace.** Fallback is limited to a fallback *model* on the same
provider, not a second provider.

**Autonomous mode is only as good as the model.** A weak model with unreliable tool calling
makes autonomous mode a poor experience. Start in Assistant, move to Copilot, and read
[AI_AUTONOMOUS_MODE.md](AI_AUTONOMOUS_MODE.md) before enabling it.

**There is no one-click undo for an AI run.** Tasks and projects are soft-deleted and every
change is in the activity log with its previous value, so a manual revert is always
possible. Restoring from a database backup remains the guaranteed path.

---

## Security

**No malware scanner ships with Planvio — only the seam for one.** Planvio validates
extension *and* sniffed MIME type against an allow-list, blocks executable extensions
unconditionally, sanitises SVG, stores files outside the web root and streams them through
an authorising controller. It does not carry virus signatures, because signatures need daily
updates and a resident daemon, and a bundled scanner with month-old definitions is worse
than none: people trust it. What it does carry is `App\Services\Uploads\ScansUploads`, a
`clamav` driver that speaks INSTREAM to a `clamd` you already run over a unix socket or TCP,
and one config value to switch it on. A configured scanner that cannot be reached **refuses
the upload** rather than passing it through. With no scanner configured — the default — file
contents are not inspected, and a real Word document carrying a real macro passes every
check Planvio can make on its own. See [SECURITY.md §6.7](SECURITY.md).

**The Content-Security-Policy keeps `'unsafe-inline'` and `'unsafe-eval'` in `script-src`.**
One is now sent on every page — the product's and the administration panel's — and it is
worth having: no script or style from another origin, no `fetch` or form post to another
origin, no `<base>` rewrite, no plugins, no framing by a site you do not control. It does
**not** prevent XSS, and Planvio does not say it does. A policy that would means removing
every inline script and every Alpine `x-on:` expression from the product — Alpine evaluates
its expressions at runtime, which needs `'unsafe-eval'` whatever else changes — and that is
a front-end rewrite, not a header. A nonce alongside `'unsafe-inline'` would be theatre,
because browsers ignore the nonce once the keyword is present.
`security.csp.extra_directives` exists for administrators who have audited their own install
and want to tighten it, with `report_only` to try it safely, and `security.csp.panel` takes
the same three settings for `/admin` alone.

**The panel gets the same directives, not stricter ones.** `/admin` builds its own middleware
stack rather than running through the `web` group, so the policy has to be registered there
separately — it now is, first in that stack. What it is not is a tighter policy: Filament is
Alpine and Livewire too, so `'unsafe-inline'` and `'unsafe-eval'` are as necessary there as
in the product, and the panel's own assets are served from this origin. The gap that has
closed is that `/admin` used to have no policy at all, on the surface where AI provider
credentials are entered.

**HSTS ships commented out.** Enabling `Strict-Transport-Security` before HTTPS works on
every hostname you serve locks people out of your own site, and it is hard to undo. The
line is in `public/.htaccess` ready to uncomment once you have verified your certificate.

**No SSO, SAML, LDAP or OAuth sign-in.** Email and password, with optional TOTP two-factor.

**No WebAuthn or hardware keys.** Two-factor is TOTP plus recovery codes.

**No IP allow-listing** for the admin panel or the API.

**Attachments are not encrypted at rest.** They are stored outside the web root with
unguessable names and served only through an authorising controller. Disk-level encryption
is your host's responsibility.

**Requiring 2FA of *platform administrators* is still config-only.**
`PLANVIO_2FA_REQUIRED_ADMINS` has no screen, deliberately: a platform admin belongs to no
workspace, and no workspace's settings should be able to reach them. The per-workspace roles
are configurable from **Settings → Security**, which shows how many current members would be
forced to enrol before you save; `security.two_factor.required_for_roles` in
`config/planvio.php` remains the installation-wide floor that a workspace can add to and
cannot remove.

**Compromised-password checking is off by default.** `PLANVIO_CHECK_PWNED=true` enables it;
it makes an outbound request to the Have I Been Pwned range API on password change, which
some self-hosted installations will not want.

**The idle session timeout is off by default** (`PLANVIO_IDLE_TIMEOUT=0`).

**Rate limiting is in-process and per account, not a WAF.** Sign-in is throttled per email
plus address, `/api/v1` per token, ordinary page requests per user id, and search, AI run
creation, exports and status reports get their own much tighter budgets on top. All of it is
counted in Planvio's own cache — which on a default install is the database, so the page
limiter costs two extra queries per request. It bounds a stuck client, a scraper holding a
token, and a retry loop. It does nothing about a distributed flood of unauthenticated
traffic; that is a network-layer problem and wants Cloudflare, your host's WAF or
`mod_evasive` in front. The numbers are in `config/planvio.php` under
`security.rate_limits`, each with the reasoning for its value.

**The `.htaccess` protections depend on `AllowOverride`.** If your host disables it, the
per-directory guards stop applying. The recommended layout — document root pointed at
`public/` — does not depend on them at all, which is why it is recommended.

**Dependencies are patched by release.** There is no in-app dependency updater. A security
release of Laravel or Filament reaches you when Planvio ships a new version.

---

## Product

**Two languages ship: English and Arabic. Anything else is work.** `locales` says which
languages the installation offers, `translations` holds an administrator's own wording and
beats the shipped files, `SetLocale` resolves the language per request from the person, then
the workspace, then the default, and `lang/en.json` is a real catalogue of every literal
string in the product — 3,611 of them, generated by `php artisan lang:scan` rather than kept
by hand, because a hand-kept list of that size is a list that is wrong. With the four dotted
groups (`actions`, `ai`, `enums`, `search`) and the framework's own four (`auth`,
`passwords`, `pagination`, `validation`), the catalogue is 4,209 keys, and Arabic has all of
them: `php artisan lang:missing ar` reports nothing missing.

`tests/Feature/Localisation/TranslationIntegrityTest.php` is what keeps that true. It fails
the build when a key in `lang/en.json` has no line in `lang/ar.json`, when a line is empty,
when a translation dropped a placeholder the English needs, and when a dotted group key has
leaked into the JSON catalogue. Without it a string added to the product renders English
inside an Arabic page and nothing at all says so.

A third language is `lang:sync`, `lang:export`, a translator and `lang:import`; nothing has
to be refactored first. See [LOCALISATION.md](LOCALISATION.md).

**Right-to-left is supported and has been driven, not proved exhaustively.** The layout
computes `dir` from the locale, `SetLocale` shares the resolved direction with every view,
and the stylesheet works on the inline axis. Every screen of the product, plus the installer
and the signed-out pages, has been rendered in Arabic in a real browser at 1440×900 and
compared against the same screen in English: the sidebar mirrors, no page scrolls
horizontally, directional chevrons mirror and the brand mark does not, and every one of the
123 GET routes answers with the same status in both languages. What that does **not** amount
to is a claim about every viewport, every component state and every combination of filters.
If you find something laid out mirrored-wrong, it is a bug and it is fixable in one place.

The charts and the timeline mirror with the rest of it. Bars grow from the right, the value
axis sits on the right, and the Gantt runs in reading order — September to the right of
October in Arabic — with the row headers on the right edge and the dependency arrows pointing
the way time runs. What stays put is the *frame*: each `<svg>` carries its own `dir="ltr"`,
because an SVG `x` is measured from the left of the viewBox whatever the page does, and the
reflection is applied to the coordinates rather than to the picture. Two consequences worth
knowing before you deploy in Arabic. Figures stay in Western digits, left to right, in axis
labels and value labels: Planvio has no Arabic-Indic numeral option. And a Latin label long
enough to be truncated puts its ellipsis at the Arabic end of the run rather than beside the
character it cut — plain bidi, and the alternative would make each label's anchor depend on
its own content, which is how an axis label lands on the wrong side of its tick.
See [LOCALISATION.md §7](LOCALISATION.md).

**No real-time collaboration.** Two people editing the same task will not see each other's
cursors, and the second save wins. Broadcasting is configured to the `log` driver: adding
websockets would mean adding a daemon, which is the one thing the hosting target rules out.

**CSV import only.** The import pipeline is built around a `Source` abstraction with a
column-mapping step, so Jira, Trello, Asana, Monday and Notion importers are additions
rather than rewrites — but only CSV ships.

**Local filesystem only. There is no object-storage option, and the dead `s3` disk has been
removed.** Attachments live on the server's disk, under `storage/app/private`, and that is
the only supported arrangement.

Laravel's skeleton ships an `s3` disk in `config/filesystems.php`. Planvio deleted it rather
than wiring it up, for three reasons that all point the same way. Nothing selected it —
`uploads.disk` has always been `private`. The package that would make it work,
`league/flysystem-aws-s3-v3`, is not a dependency and cannot become one for this hosting
target: production runs with **no Composer**, so the AWS SDK would have to ship inside the
release ZIP that administrators upload through cPanel's file manager, adding tens of
megabytes to a download for a feature almost nobody on shared hosting can use. And the one
thing S3 usually buys — serving files directly from the bucket — is exactly what Planvio
must not do: every byte goes through the authorising controller
([ARCHITECTURE.md §9](ARCHITECTURE.md)), so an S3 disk would add a round trip and an egress
bill without removing the PHP request in front of it.

Leaving the disk configured was the worse option. `AWS_ACCESS_KEY_ID` and friends in a
config file read as a supported option; an administrator who filled them in would find out
at the first upload that the driver does not exist. A missing feature named in
LIMITATIONS.md is honest. A present-looking feature that throws is not.

If you need object storage, the shape of the change is small and known: add the Flysystem
adapter, make `uploads.disk` mean something other than `private`, and keep
`AttachmentController` in front of it so nothing becomes publicly readable. It is a
deliberate omission, not an oversight.

**No third-party integrations.** No Google Calendar, Microsoft Calendar, Slack, Teams,
Drive, Dropbox, GitHub or GitLab. Webhooks are outbound only. The AI tool registry is the
natural place to add these later.

**Not a PWA.** `/site.webmanifest` is served — by a controller rather than as a file in
`public/`, so its name, description, `lang` and `dir` are the reader's — and the layout is
structured for it, but there is no service worker and no offline capability.

**No native mobile app.** The web interface is responsive and usable on a phone for the
common tasks — viewing, updating status, commenting, assigning, checklists, AI — but it is
a web app.

**No in-app backup.** Planvio deliberately does not implement server-wide backup: a web
application writing archives of its own host is a liability, not a feature. Export to CSV
is available; hosting-level database and file backup remains necessary and is documented in
[DEPLOYMENT.md](DEPLOYMENT.md).

---

## Operational

**Cron is required for anything scheduled.** Without it, reminders, recurring tasks, AI
automations, notification emails and cleanup simply never fire. The rest of Planvio works
normally. **Admin → System Health** reports the last observed scheduler run and never claims
cron is healthy without evidence.

**The queue needs its cron too.** Email, notifications, webhooks and AI runs are queued. A
missing queue worker cron means they queue up and never send.

**MySQL and MariaDB only.** The schema is portable and the migrations are verified against
both SQLite and MariaDB, but PostgreSQL is not tested and not supported.

**PHP 8.3 or 8.4.** Not 8.2, which Laravel 13 does not support.

**Upgrades are manual packages.** Download, extract, point the document root, open the
browser. Planvio deliberately does **not** download updates from a remote server: an
application that can fetch and execute new code over the network is a supply-chain risk in
a product whose whole premise is that you host it yourself.

---

## What was verified, and how

So the claims above are calibrated:

- **2,031 automated tests**, 12,641 assertions, covering unit, feature, security,
  localisation and installer suites. Five of them are browser-render reviews that skip
  unless a review run asks for them, so an ordinary `php artisan test` reports 2,026 passed
  and 5 skipped.
- **Migrations run on both SQLite and MariaDB 10.11**, forward and rollback.
- **The installer was walked end to end over HTTP** against a real MariaDB database — not
  only through in-process tests — which is how three fatal browser-only bugs were found and
  fixed before release.
- **Every route was loaded** and asserted, twice: once with the acting user set to English
  and once to Arabic. 123 GET routes, identical status in both languages, nothing above 400
  that was not a deliberate gate — the installer redirecting because the installation
  exists, `register` answering 404 because self-service registration is off, `api/v1/search`
  answering 422 without a query.
- **Arabic was rendered, not assumed.** The whole product, the installer and the signed-out
  pages were driven in a real browser in both directions and compared: `dir` and `lang` on
  every page, no horizontal document overflow, the sidebar on the correct edge, the brand
  mark unmirrored while directional chevrons mirror, and no console error in either.
- **The plots were measured, not eyeballed.** Every reports tab, the printable status report
  and the project timeline at three zoom levels were rendered in both languages and compared
  side by side, and each axis label's box was read out of the live document to confirm it
  sits in its own gutter on the correct side of its tick rather than over the bars.
- **Workspace isolation was proven empirically** by building a record in a second workspace
  for each scoped model and confirming `find`, `exists`, `count`, `get` and `pluck` all
  refuse it, on both engines.
- **The AI containment properties were attacked, not assumed**: cross-workspace tool calls,
  `</untrusted-data>` prompt escapes, policies attempting to auto-approve deletions, and
  workspace settings attempting to raise the global limit ceiling each have a test that
  asserts the attempt fails.
- **The Content-Security-Policy was read off a live server in a real browser**, not only
  asserted in a test: signed-out and signed-in pages carry it, the attachment route keeps
  its own stricter `default-src 'none'; sandbox` instead of being overwritten by it, and
  the product — Livewire updates, Alpine, the command palette, the Filament panel — was
  driven through it with no console violations.
- **The upload scanner was proven to fail closed**, by pointing it at a TCP port nothing is
  listening on and confirming the upload is refused with `scanner_unavailable`, no
  `attachments` row and no bytes on disk. Its ClamAV wire protocol — `zINSTREAM`,
  length-prefixed chunks, the terminating zero length, and each of the `OK`, `FOUND` and
  `ERROR` verdicts — is exercised over a socket pair rather than mocked away.
- **Every rate limit was hit deliberately** and the 429 observed, including the check that
  one person's spent budget does not refuse a colleague on the same address.

Anything not in that list should be treated as unverified.

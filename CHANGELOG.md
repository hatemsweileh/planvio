# Changelog

All notable changes to Planvio are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and Planvio
uses [semantic versioning](https://semver.org/spec/v2.0.0.html): `MAJOR.MINOR.PATCH`.

A release that requires migrations also bumps `db_version` in `config/planvio.php`; the
upgrade screen compares the stored value against the shipped one to decide what to offer.

---

## [Unreleased]

Nothing yet.

---

## [1.2.0] — 2026-10-18

An application release. The database version stays `1.0.0`: this release adds no migrations.
Installations of 1.0.0 update by uploading `planvio-v1.2.0-update.zip` over the directory
they already run. New installations use `planvio-v1.2.0.zip` and the browser installer.

### Added

- Two release archives. `planvio-v1.2.0.zip` is a fresh install: production dependencies,
  compiled assets, the installer, and an empty storage skeleton. `planvio-v1.2.0-update.zip`
  is the same application with `storage/` and `bootstrap/cache/` left out, so extracting it
  over a live directory does not replace `.env`, uploads, or the install lock.
- An in-place update. After those files are on disk, the next request or the next cron run
  records application version 1.2.0, clears compiled caches, and removes
  `resources/views/welcome.blade.php`, which 1.0.0 shipped and nothing routes to. Planvio
  does not download the archive and does not run migrations.

### Fixed

- Task descriptions are sanitised before they are stored, including descriptions written by
  the API and by CSV import. A stored script can no longer run when the description is shown.
- The task editor no longer throws when the toolbar acts on a selection the browser has
  dropped, and it no longer registers the link mark twice.
- Checklist items written by the editor survive sanitising: the checkbox markup is kept, and
  other inputs are still stripped.
- The description editor and its read-only view no longer share one Livewire identity, so
  opening the editor does not reuse the read-only node.
- The description Edit button stays in the corner of the description instead of shifting
  with the toolbar.
- Dragging a card you are not allowed to move shows "You cannot move this task." instead of
  a framework error, and the drag handle is not offered.
- A touch on a card waits before it starts a drag, so scrolling the board does not pick the
  card up. The Move button stays visible without a hover pointer.
- Dropping a card back where it already was, or choosing its current column, does not write
  a move.
- Reopening a task from Done restores the progress it had before it was completed, or the
  checklist's completion when it has one. Leaving Cancelled does not rewrite progress.
- An empty Done or Cancelled column says that done work is hidden, and offers to show it,
  instead of looking like a column with no tasks.
- Moving a card refreshes the board once.
- Project CSV export no longer repeats or skips a project, and it includes every task when
  a project has 500 or more.
- Filament 5.10 strings that the packaged Arabic was missing — copy, global search, list
  tabs, the query builder, the column manager — are translated.

### Changed

- Laravel 13.36 and Filament 5.10.2.
- TipTap 3.32, Vite 8.3, and the HTML sanitiser and Markdown libraries current with their
  patch releases.
- The release builder reads `.gitignore` itself, refuses to package environment files, and
  publishes Filament's assets from the `vendor/` directory the archive ships.
- Dependabot may open PHPUnit 12 patch updates. PHPUnit 13 stays closed, because it requires
  PHP 8.4.1 and Planvio still supports PHP 8.3.

### Security

- Stored task descriptions are cleaned with the same sanitiser comments and wiki pages
  already used. See [docs/SECURITY.md](docs/SECURITY.md).
- `shell-quote` is pinned to 1.12.0 (GHSA-pqg4-j6r4-53mv). The build-time dependency does
  not ship in the release archive.

### Notes

- Requires PHP 8.3 or 8.4 and MySQL 5.7+ / MariaDB 10.6+. Unchanged from 1.0.0.
- No schema change. `db_version` stays `1.0.0`. There is no migration step and no upgrade
  screen for this release.
- A fresh install still starts at the browser wizard. An update does not. See
  [docs/UPGRADING.md](docs/UPGRADING.md).

---

## [1.0.0] — 2026-09-09

First public release.

### Added

**Work management**
- Workspaces with five roles (owner, admin, manager, member, guest) and project-level roles,
  so a project manager can run their projects without being an administrator.
- Projects with types, custom statuses, health, budgets, custom fields and templates.
- Tasks with subtasks, checklists, dependencies, watchers, recurrence, tags, attachments and
  per-project keys (`WEB-142`).
- Milestones, time tracking with a timer, expenses and project budgets.
- A per-project wiki, file library and activity history.

**Views**
- Kanban board with drag and drop, where the server computes the drop position from the
  neighbouring cards rather than trusting a client-sent index.
- List view with sorting, filtering, column control, grouping, bulk actions and saved views
  whose filter state lives in the URL.
- Calendar (month, week, day) and a Gantt timeline with dependencies and zoom levels.
- Dashboard, inbox and My Tasks.

**AI**
- A governed tool layer of 39 tools. Every call validates its arguments, checks the
  permission *as the acting user*, asserts workspace scope, runs one application action
  inside a transaction, and writes an audit record.
- Three modes — Assistant, Copilot, Autonomous — set per workspace and overridable per
  project, with an approval queue and a kill switch.
- Four destructive tools that always require human approval and cannot be waived by any
  policy.
- Provider abstraction for OpenAI, Anthropic, any OpenAI-compatible endpoint, and local
  runtimes such as Ollama and LM Studio.
- Scheduled and event-driven automations, each taking a database lock so an overlapping cron
  tick is a no-op rather than a duplicate run.
- Prompt-injection defence by structural separation: all workspace-derived text reaches the
  model wrapped in `<untrusted-data>`, with the wrapper and transcript role tags escaped so
  a crafted task description cannot close it early.

**Languages**
- English and Arabic, at full key parity, with complete right-to-left layout, Arabic
  typography and a vendored Arabic typeface — no webfont CDN, so Planvio installs air-gapped.
- Administrators can add a language and translate it in-app, with placeholder validation on
  save, JSON import and export for external translators, and an optional AI-drafted first
  pass that is never marked reviewed.

**Platform**
- A browser installation wizard: requirements, database, application, administrator, email
  and AI, with checkpoints so a failed install resumes rather than locking you out.
- Administration panel, system information (including the exact cron lines for your server),
  health checks, maintenance mode and an audit log.
- Two-factor authentication with TOTP and recovery codes.
- REST API at `/api/v1` with Sanctum tokens, and signed outbound webhooks.
- CSV import with column mapping and per-row validation, and CSV export.

### Security

- Three independent tenancy layers — column, global scope, policy — arranged so the scope
  fails open and the policy fails closed.
- Uploads validated on extension **and** sniffed MIME, with an unconditional blocked-extension
  list, SVG sanitisation, storage outside the web root, and an authorising download
  controller. An optional ClamAV driver refuses the upload when it is configured but
  unreachable, rather than passing it through.
- Content-Security-Policy on application pages and the administration panel.
- Rate limiting on sign-in, the API, page requests, and separately on search, AI runs,
  exports and reports.
- Secrets are never logged or persisted: API keys, passwords, SMTP credentials and tokens are
  scrubbed from audit rows, error messages and stack frames.

### Notes

- Requires PHP 8.3 or 8.4 and MySQL 5.7+ / MariaDB 10.6+.
- Runs without Docker, Redis, Node, Composer, a daemon or root in production.
- 2,034 automated tests. Migrations verified on SQLite and MariaDB. The installer is walked
  end to end over HTTP against a real database as part of release verification.
- Known boundaries are documented in [docs/LIMITATIONS.md](docs/LIMITATIONS.md) rather than
  left for you to discover.

[Unreleased]: https://github.com/hatemsweileh/planvio/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/hatemsweileh/planvio/compare/v1.0.0...v1.2.0
[1.0.0]: https://github.com/hatemsweileh/planvio/releases/tag/v1.0.0

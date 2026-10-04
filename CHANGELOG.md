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

[Unreleased]: https://github.com/hatemsweileh/planvio/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/hatemsweileh/planvio/releases/tag/v1.0.0

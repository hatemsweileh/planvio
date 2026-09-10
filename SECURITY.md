# Security policy

## Reporting a vulnerability

**Please do not open a public issue for a security problem.**

Report it privately through GitHub:

1. Go to the [Security tab](https://github.com/hatemsweileh/planvio/security) of this
   repository.
2. Choose **Report a vulnerability**.
3. Describe what you found.

That opens a private advisory visible only to you and the maintainers. You will get an
acknowledgement, and we will keep you updated as it is investigated and fixed. If you would
like credit in the advisory and the release notes, say so — and if you would rather stay
anonymous, that is fine too.

### What helps

- The Planvio version, PHP version and database.
- Whether AI is enabled, and in which mode.
- The workspace role of the account involved, if the issue is about permissions.
- The smallest set of steps that reproduces it.
- What an attacker gets out of it. A crash and a cross-workspace read are very different
  problems, and knowing which it is helps us triage honestly.

Proof-of-concept code is welcome. Please only ever run it against your own installation.

### What to expect

| | |
|---|---|
| Acknowledgement | Within a few days |
| Initial assessment | Within a week |
| Fix for a confirmed critical issue | As fast as we can, and we will tell you the plan |
| Public advisory | After a fix ships, credited unless you prefer otherwise |

Planvio is maintained by one person. These are honest intentions rather than a contractual
SLA, and a complicated issue may take longer than either of us would like — but you will not
be left without an answer.

---

## Supported versions

| Version | Supported |
|---|---|
| 1.0.x | Yes |
| < 1.0 | No |

Planvio has no in-app dependency updater. A security release of Laravel, Filament or any
other dependency reaches you when Planvio ships a new version, so **keep up with releases**.

---

## Scope

### In scope

- Cross-workspace data access of any kind. This is the property Planvio cares about most.
- Authorization bypass: reaching a record, action or route your role does not permit.
- Authentication bypass, session fixation, or defeating the login throttle.
- AI containment failures — an AI run reaching another workspace, invoking a denied tool,
  executing a destructive action without the required approval, exceeding the acting user's
  permissions, or escaping the `<untrusted-data>` boundary through injected content.
- Secret disclosure: an API key, password, SMTP credential or token reaching a log, an
  audit row, an error message or an HTTP response.
- Stored or reflected XSS, SQL injection, CSRF, SSRF, path traversal.
- Upload handling: executing an uploaded file, escaping the storage directory, or defeating
  the extension and MIME allow-lists.
- Anything that lets an unauthenticated visitor read `.env`, `storage/` or `vendor/`.
- Installer flaws, including re-running the installer on a live installation.

### Out of scope

- Missing hardening that [`docs/LIMITATIONS.md`](docs/LIMITATIONS.md) already states is
  absent. It is a deliberate boundary, documented before you deployed. If you think one of
  those boundaries is wrong, that is a feature request and a good one — open an issue.
- The Content-Security-Policy retaining `'unsafe-inline'` and `'unsafe-eval'`. This is
  documented and explained: Alpine evaluates its expressions at runtime. The CSP limits the
  blast radius of an XSS bug; it does not prevent one, and Planvio does not claim it does.
- Vulnerabilities in a dependency with no demonstrated impact on Planvio. Report those
  upstream. If you can show the path through Planvio, that is very much in scope.
- Findings from an automated scanner with no working proof of concept.
- Missing rate limiting on unauthenticated flooding. Planvio's limiter is in-process and
  per account; a distributed flood is a network-layer problem that wants a WAF in front.
- Anything requiring physical access, a compromised host, or an already-administrator
  account.
- Self-XSS, clickjacking on a page with no state-changing action, or a missing header with
  no exploitable consequence.

---

## For people running Planvio

Two documents matter to you:

- [`docs/SECURITY.md`](docs/SECURITY.md) — the threat model, what each control actually
  does, and a hardening checklist to work through after installing.
- [`docs/AI_SECURITY.md`](docs/AI_SECURITY.md) — how the AI is contained, and what to check
  before enabling Copilot or Autonomous mode.

The short version: point your document root at `public/`, keep `APP_DEBUG=false`, enable
HTTPS, add both cron jobs, and confirm that `https://your-domain/.env` returns 403.
**Admin → System Health** checks that last one for you.

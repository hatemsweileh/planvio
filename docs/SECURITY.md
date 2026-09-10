# Planvio Security

This document describes how Planvio protects your data, what it deliberately does not do,
and the checklist an administrator should work through after installing.

It is written for the person who runs the server. You do not need SSH, root, or a shell to
apply anything here — every control is either shipped in the release, set in
`config/planvio.php`, set in `.env` by the installer, or toggled in **Admin → Settings**.

Related reading: [CPANEL.md](CPANEL.md) for installation, [ARCHITECTURE.md](ARCHITECTURE.md)
for the normative contract every claim below is measured against.

---

## 1. Security model

Planvio's threat model starts from one assumption: **a workspace must never see another
workspace's data, and no single mistake should be able to break that.**

Everything else — passwords, uploads, AI — is built on top of that.

### Three independent tenancy layers

Every tenant-owned table carries a `workspace_id`. Access to a row is gated three times, by
three mechanisms that do not depend on each other.

| Layer | What it is | Where it lives | Fails |
|---|---|---|---|
| **1. Column** | `workspace_id` on every tenant table — foreign key, indexed, `cascadeOnDelete` | `database/migrations/*` | Structurally. A row physically belongs to exactly one workspace |
| **2. Scope** | `WorkspaceScope`, a global Eloquent scope that appends `where workspace_id = ?` to every query | `app/Models/Scopes/WorkspaceScope.php`, applied by `app/Models/Concerns/BelongsToWorkspace.php` | **Open.** When no workspace is bound the scope adds nothing |
| **3. Policy** | Per-model policy that re-resolves the user's `workspace_members` row from the database, independently of the scope | `app/Policies/*` | **Closed.** No membership row, no access |

The layers are arranged so that the two failure modes cancel each other out.

**The scope fails open, on purpose.** `WorkspaceScope` reads the workspace bound on
`App\Support\CurrentWorkspace` — a request- or job-scoped singleton. When nothing is bound,
the scope adds no `where` clause at all. That is what lets the installer, the Filament admin
panel and console commands operate across tenants. It also means a background job that
forgets to bind a workspace will query every tenant's rows. **This is exactly why the scope
is never the authority.**

**The policy fails closed, on purpose.** Every policy method begins by resolving the acting
user's `WorkspaceMember` record for `$model->workspace_id`. If there is no row, the method
returns `false` — before it looks at roles, projects or anything else. It never asks the
scope whether the row "should" have been visible; it re-checks membership itself.

So:

- A **forgotten scope binding** leaks nothing, because the policy still denies the read.
- A **policy bug** is contained, because the scoped query never returned the foreign row in
  the first place.
- A **column bug** — a row written with the wrong `workspace_id` — is the only failure that
  both other layers would honour, which is why `workspace_id` is back-filled automatically on
  create from the bound workspace and constrained by a foreign key rather than being passed
  in by the caller.

### The escape hatch

`Model::query()->withoutWorkspaceScope()` removes layer 2. It exists for the admin panel,
the installer and system maintenance. It never removes layer 3 — a policy check still applies
wherever one is invoked. Product code (Livewire components, controllers, AI tools) does not
use it.

### `CurrentWorkspace`

```
->set(Workspace $w)                       bind
->get(): ?Workspace                       read
->id(): ?int                              read the key
->has(): bool
->forget()
->runFor(Workspace $w, Closure $fn)       bind temporarily, restore afterwards
```

`SetCurrentWorkspace` middleware binds it for web requests under `/w/{workspace}`. Jobs, AI
runs and console commands must bind it explicitly — usually with `runFor()`, which restores
the previous binding in a `finally` block so one workspace's work can never leak into the
next item on the queue.

**Cross-workspace access is treated as a security bug, not a feature request.** Regression
tests for it live in `tests/Feature/Security/`.

---

## 2. Authentication

### Password storage

Passwords are hashed with **bcrypt at cost 12** — Laravel's default hasher — through the
`'password' => 'hashed'` cast on `App\Models\User`. The plaintext exists only in the request
that sets it and is never written to a log, a session, a queue payload or an activity record.

`App\Models\User` declares `#[Hidden(['password', 'remember_token'])]`, so neither value can
be serialised into JSON, an API response, a Livewire payload or a notification.

There is no way to read a password back out of Planvio. An administrator resetting somebody
else's password sends them a reset link; they do not choose the new password.

### Password policy

Enforced on registration, on invitation acceptance, on reset and on change. Configured in
`config/planvio.php` under `security.password`:

| Setting | Default | `.env` key | Meaning |
|---|---|---|---|
| `min_length` | `10` | `PLANVIO_PASSWORD_MIN` | Minimum characters |
| `require_mixed_case` | `true` | — | At least one upper and one lower case letter |
| `require_numbers` | `true` | — | At least one digit |
| `require_symbols` | `false` | — | Symbols allowed, not demanded |
| `check_compromised` | `false` | `PLANVIO_CHECK_PWNED` | Reject passwords found in known breach corpora |

`check_compromised` is off by default because it makes an outbound HTTPS request during
sign-up, and many shared hosts block outbound connections. It uses k-anonymity — only the
first five characters of the SHA-1 hash leave the server, never the password. If your host
allows outbound HTTPS, turn it on:

```
PLANVIO_CHECK_PWNED=true
```

Symbols are not required deliberately. Length plus a breach check rejects far more bad
passwords than a symbol rule does, and symbol rules push people towards `Password1!`.

### Login throttling

Configured in `config/planvio.php` under `security.login`. These values are not
env-configurable — they are a floor, not a preference.

| Setting | Value | Meaning |
|---|---|---|
| `max_attempts` | `5` | Failed attempts before lockout |
| `decay_minutes` | `5` | Window the attempts are counted over |
| `lockout_minutes` | `15` | How long the lockout lasts |

Throttling is keyed on **the submitted email plus the client IP**, not on the IP alone. That
matters both ways: it stops one attacker from grinding a single account from many addresses,
and it stops one office behind a shared NAT from locking each other out.

A locked-out attempt returns the same generic "these credentials do not match our records"
message as a wrong password, with the retry delay stated. It does not say whether the account
exists, whether it is active, or whether the password was the part that was wrong.

Every lockout is written to `audit_logs` with the event, the IP and the user agent.

### Sessions

Sessions are stored in the database (`sessions` table). There is no Redis, no file-session
race on shared hosting, and no session data on disk in a world-readable directory.

Defaults written by the installer into `.env`:

| Key | Default | Why |
|---|---|---|
| `SESSION_DRIVER` | `database` | No daemon, no Redis |
| `SESSION_LIFETIME` | `120` | Minutes of inactivity before the session expires |
| `SESSION_SECURE_COOKIE` | `true` | Cookie is only sent over HTTPS |
| `SESSION_SAME_SITE` | `lax` | Cookie is not sent on cross-site POSTs |
| `SESSION_ENCRYPT` | `false` | Session payloads are already server-side only |
| `SESSION_PATH` | `/` | |
| `SESSION_DOMAIN` | `null` | Host-only cookie — not shared with subdomains |

`http_only` is `true` (from `config/session.php`), so JavaScript cannot read the session
cookie.

Set `SESSION_ENCRYPT=true` if other applications on the same account can read your database,
or if your host's DBAs should not be able to read session payloads. It costs a small amount of
CPU per request.

**Session fixation** is prevented by regenerating the session identifier on every successful
login and on privilege changes, and by invalidating and regenerating the CSRF token on logout.
A session ID captured before sign-in is worthless after it.

**Idle timeout.** `config/planvio.php` → `security.session.idle_timeout_minutes`, set from
`PLANVIO_IDLE_TIMEOUT`. It is `0` — disabled — by default, so `SESSION_LIFETIME` alone
governs. Set it to a value below `SESSION_LIFETIME` to force re-authentication after a period
of inactivity even while the session cookie is still valid:

```
PLANVIO_IDLE_TIMEOUT=30
```

### Remember me

Optional, off unless the user ticks the box. It issues the standard Laravel remember-me
cookie backed by the `users.remember_token` column. The token is rotated on every use, and
cleared on password change, on password reset, and on logout. Changing your password
therefore signs out every remembered device.

`remember_token` is in the model's `#[Hidden]` list and never reaches the browser except as
its own `HttpOnly` cookie.

Remember-me does not bypass two-factor authentication. A remembered device still presents the
second factor unless the user explicitly trusted that browser during 2FA setup.

### Email verification

`users.email_verified_at` records when the address was proven. Verification links are signed
and time-limited, and are consumed once.

The account created by the installer is verified at creation — you proved you controlled the
server, and there is no mail transport configured yet at that point. Invited users are
verified by accepting the invitation, because the invitation token was delivered to the
address being claimed. Self-registered users, where an administrator has enabled open
registration, must verify before they can act.

### Password reset does not reveal whether an email exists

Submitting the "forgot password" form always produces the same response and the same timing,
whether or not the address belongs to an account:

> If that email address is registered, we have sent a password reset link to it.

This is deliberate. The alternative — "we could not find a user with that email address" —
turns the reset form into a free account-enumeration oracle. An attacker can then confirm
which of a leaked address list has a Planvio account before spending any effort on them.

The same rule holds elsewhere: the login form never distinguishes "no such user" from "wrong
password", and the invitation flow never confirms whether an invited address is already
registered.

Reset tokens are single-use, are hashed in `password_reset_tokens`, expire after **60
minutes** (`config/auth.php` → `passwords.users.expire`), and are rate limited to one request
per **60 seconds** per address (`passwords.users.throttle`). Using a reset link invalidates
every other outstanding token for that account and signs out every existing session.

---

## 3. Two-factor authentication

Planvio ships TOTP-based 2FA using `pragmarx/google2fa`, with QR codes rendered locally by
`bacon/bacon-qr-code`. No second-factor service, no SMS gateway, no outbound call is involved
— everything happens on your server.

Configured in `config/planvio.php` under `security.two_factor`:

| Setting | Default | Meaning |
|---|---|---|
| `enabled` | `true` | 2FA is offered to all users |
| `window` | `1` | Accepts the current 30-second code plus one step either side, tolerating ~±30s of clock drift |
| `recovery_code_count` | `8` | Recovery codes generated per user |
| `required_for_roles` | `[]` | Workspace roles that must have 2FA before doing anything else |
| `required_for_platform_admins` | `false` (`PLANVIO_2FA_REQUIRED_ADMINS`) | Force 2FA for `users.is_admin` accounts |

### How enrolment works

1. The user opens **Profile → Security → Two-factor authentication** and clicks *Enable*.
2. Planvio generates a secret, stores it encrypted in `users.two_factor_secret`, and renders a
   QR code for any authenticator app (1Password, Bitwarden, Aegis, Google Authenticator, Authy).
3. The user enters a code from the app. Only then is `users.two_factor_confirmed_at` set.
   **An unconfirmed secret does not switch 2FA on** — a user who scans the QR code and then
   closes the tab is not locked out.
4. Planvio shows the eight recovery codes exactly once and requires the user to confirm they
   have stored them.

`two_factor_secret` and `two_factor_recovery_codes` are `text` columns holding values
encrypted with `AES-256-CBC` under your `APP_KEY`. Somebody who reads a database dump without
also holding `.env` gets ciphertext.

### Recovery codes

Eight single-use codes. Each is consumed on use and struck from the stored list. When a user
has two or fewer left, Planvio prompts them to regenerate. Regenerating replaces all eight —
the old set stops working immediately.

Recovery codes are the only way back in without the authenticator. If a user loses both, a
platform administrator can clear that user's 2FA from **Admin → Users**, which writes an
`audit_logs` entry naming the administrator who did it. There is no self-service bypass.

### Requiring 2FA for selected roles

There are two places this is set, and they are not alternatives.

**Per workspace, from the product.** **Settings → Security** in a workspace lists the five
workspace roles as checkboxes. Ticking one holds every member who holds that role at the
enrolment page from their next request onwards. The screen is behind `workspace.manage`, so
owners and workspace administrators can set it; managers and members cannot.

Before you save, the screen tells you **how many current members would be forced to enrol** —
per role, and in total — counting only people who have not already set up an authenticator.
That number is the point of the screen. Requiring a second factor of forty people on a Monday
morning with no warning is a support incident, and the count is there so it is a decision
rather than a surprise.

The selection is stored in the `settings` table, keyed by workspace id, and read by
`EnsureTwoFactorConfirmed` on every request.

**Installation-wide, from the config file.** `security.two_factor.required_for_roles` in
`config/planvio.php` takes the same `WorkspaceRole` values and applies to *every* workspace:

```php
'two_factor' => [
    'enabled' => true,
    'window' => 1,
    'recovery_code_count' => 8,
    'required_for_roles' => ['owner', 'admin'],
    'required_for_platform_admins' => env('PLANVIO_2FA_REQUIRED_ADMINS', false),
],
```

Valid values are `owner`, `admin`, `manager`, `member`, `guest`.

**The two combine as a union, and the config file is the floor.** A role listed there shows up
on the workspace screen ticked and disabled: a workspace can be stricter than the installation
and never looser. That asymmetry is deliberate — the person who controls the server's
filesystem outranks the person who administers one tenant, and a workspace owner must not be
able to switch off a policy the server's owner set.

The per-workspace half is also why the screen writes a per-workspace key rather than a global
one. A global setting edited from a tenant-scoped screen would let the owner of one workspace
force every member of every *other* workspace to enrol, which is a cross-tenant effect from a
screen that has no business having one.

Inside a workspace, the requirement is about the role held **there**. Outside one — the
profile screens, the workspace picker — a person is held if *any* workspace they belong to
requires the role they hold in it, because what the second factor protects is the account.

Existing sessions are re-checked on the next request, so a change takes effect immediately for
everyone already signed in. A held user can reach the enrolment screen and the sign-out
button, and nothing else.

Every change is written to `audit_logs` as `two_factor.required_roles_changed`, with the
previous and new role lists.

Platform super-admins are covered separately, because they are not workspace members and no
workspace's settings should be able to reach them:

```
PLANVIO_2FA_REQUIRED_ADMINS=true
```

Turn that on. A platform admin account is the highest-value credential in the system.

---

## 4. Authorization

### The `Permission` enum

Every capability in Planvio is a case of `App\Enums\Permission` — a string-backed enum with
36 cases. Permissions are never strings typed at a call site, never rows in a database table,
and never editable at runtime. If a permission is not in the enum, it does not exist.

Grouped by prefix:

```
workspace.view  workspace.manage  workspace.delete
project.view  project.create  project.update  project.delete  project.archive  project.manage_members
task.view  task.create  task.update  task.delete  task.assign  task.comment
milestone.view  milestone.manage
time.log  time.view_all
budget.view  budget.manage
wiki.view  wiki.manage
attachment.upload  attachment.delete
reports.view
settings.manage
users.manage
webhooks.manage
templates.manage
ai.use  ai.manage  ai.autonomous  ai.manage_policies  ai.view_logs  ai.approve
```

### The capability matrix

`App\Support\Permissions` holds the whole matrix as a constant. It is static, it hits no
database, and it cannot be changed by an administrator, an API call or the AI. Roles are
fixed; what varies is who holds which role in which workspace.

| | owner | admin | manager | member | guest |
|---|---|---|---|---|---|
| workspace.view | Y | Y | Y | Y | Y |
| workspace.manage | Y | Y | | | |
| workspace.delete | Y | | | | |
| project.view | Y | Y | Y | Y | * |
| project.create | Y | Y | Y | | |
| project.update | Y | Y | + | | |
| project.delete | Y | Y | | | |
| project.archive | Y | Y | + | | |
| project.manage_members | Y | Y | + | | |
| task.view | Y | Y | Y | Y | * |
| task.create | Y | Y | Y | Y | |
| task.update | Y | Y | Y | ~ | |
| task.delete | Y | Y | + | | |
| task.assign | Y | Y | Y | | |
| task.comment | Y | Y | Y | Y | * |
| milestone.view | Y | Y | Y | Y | * |
| milestone.manage | Y | Y | + | | |
| time.log | Y | Y | Y | Y | |
| time.view_all | Y | Y | + | | |
| budget.view | Y | Y | + | | |
| budget.manage | Y | Y | | | |
| wiki.view | Y | Y | Y | Y | * |
| wiki.manage | Y | Y | Y | Y | |
| attachment.upload | Y | Y | Y | Y | * |
| attachment.delete | Y | Y | + | own | own |
| reports.view | Y | Y | Y | Y | |
| settings.manage | Y | Y | | | |
| users.manage | Y | Y | | | |
| webhooks.manage | Y | Y | | | |
| templates.manage | Y | Y | Y | | |
| ai.use | Y | Y | Y | Y | |
| ai.manage | Y | Y | | | |
| ai.autonomous | Y | Y | | | |
| ai.manage_policies | Y | Y | | | |
| ai.view_logs | Y | Y | + | | |
| ai.approve | Y | Y | + | | |

| Marker | Meaning |
|---|---|
| `Y` | Granted unconditionally, anywhere in the workspace |
| `+` | Only inside projects where the user is `ProjectRole::manager` |
| `*` | Only inside projects the guest is explicitly a member of |
| `~` | Only tasks the user is the assignee or the reporter of |
| `own` | Only records the user uploaded or created |
| blank | Not granted, ever |

### Refinements are an upper bound, not a grant

A conditional cell says what a policy is *allowed* to permit. It does not permit anything by
itself. `App\Support\Permissions` exposes the distinction explicitly:

```php
Permissions::for(WorkspaceRole $role): array          // 'Y' cells only
Permissions::projectScoped(WorkspaceRole $role): array // '+' and '*' cells
Permissions::has(WorkspaceRole $role, Permission $p): bool
Permissions::requiresProjectScope(WorkspaceRole $role, Permission $p): bool
Permissions::ownOnly(WorkspaceRole $role, Permission $p): bool
```

`for()` returns only the unconditional set. A policy that stops at `for()` is correct but
strict. A policy dealing with a `+`, `*`, `~` or `own` cell must go on to resolve the
refinement:

- **Project manager (`+`)** — load the `project_members` row for this user and this project
  and confirm `role = manager`. Being a workspace `manager` grants nothing inside a project
  the user does not manage.
- **Guest (`*`)** — a guest sees nothing by default. Access is per project, via an explicit
  `project_members` row. A guest with no project memberships can sign in, see the workspace
  name, and nothing else.
- **Own tasks (`~`)** — a `member` may update a task only where `assignee_id` or
  `reporter_id` is their own user id.
- **Own records (`own`)** — a `member` or `guest` may delete an attachment only where
  `uploaded_by` is their own user id.

### Every policy method, in order

1. Resolve `WorkspaceMember` for `$user` and `$model->workspace_id`. No row → `false`.
2. Check the workspace role against the matrix. Not granted → `false`.
3. Apply the project, task or ownership refinement where the matrix marks `+`, `*`, `~` or
   `own`. Refinement fails → `false`.

Step 1 is repeated in every method and never delegated to the query scope. That repetition is
the point — it is what makes layer 3 independent of layer 2.

### `Gate::before` and the platform-admin exception

Accounts with `users.is_admin = true` are platform super-admins. `Gate::before` grants them
every ability — **except inside a workspace they are not a member of.**

This is the one rule most systems get wrong, so it is worth stating plainly. A platform admin
who navigates to `/w/acme` without a `workspace_members` row for Acme is denied, exactly as
any stranger would be. Being able to administer the server is not the same as being entitled
to read a customer's project data.

A platform admin who needs access to a workspace has two honest routes:

- **Join it.** An owner adds them, or they add themselves — either way a `workspace_members`
  row now exists and the action is visible in the member list and in `audit_logs`.
- **Use `/admin`.** The Filament panel administers users, workspaces, settings, AI
  configuration and health. It is for running the platform, and what it exposes is deliberately
  narrower than what a workspace member sees inside `/w/{workspace}`.

There is no silent read-through. If an administrator has looked at a workspace's work, there
is a membership record saying so.

### The AI acts as a user, never above one

The AI layer holds no permissions of its own. `AgentContext::can(Permission, ?Model)`
delegates to the same `Gate` as the UI, as the acting user, with the same policies and the
same workspace binding. There is no system account, no service role and no bypass.

A tool declares the permission it needs via `AiTool::permission()`, and that permission is
checked before the tool's action runs — after the tool's input has been validated, and after
the workspace and project scope have been asserted. When the check fails, the model receives a
structured permission error and is instructed to report it rather than route around it.

---

## 5. Threats and controls

The list below is the OWASP-shaped threat set Planvio is built against. Each row names the
control and where it lives.

| # | Threat | Control | Where it lives |
|---|---|---|---|
| 1 | **SQL injection** | All data access through Eloquent and the query builder, which bind every value as a PDO parameter. No string-concatenated SQL. The AI layer has no path to raw SQL at all — it can only call registered tools, which call `App\Actions\*` | `app/Models/*`, `app/Services/*`, `app/Actions/*`; AI pipeline in [ARCHITECTURE.md §7.1](ARCHITECTURE.md) |
| 2 | **XSS — reflected / DOM** | Blade escapes every `{{ }}` interpolation as HTML by default. `{!! !!}` is used only on values that have already been through the sanitiser | `resources/views/**`, `resources/views/livewire/app/**` |
| 3 | **XSS — stored, rich text** | HTMLPurifier (`ezyang/htmlpurifier`) runs on comment bodies and wiki content **before persistence**, against an allow-list of elements and attributes. Script, event handlers, `javascript:` URLs, `style` and embedded objects are stripped, not escaped | `comments.body` and `wiki_pages.content` — both documented in the schema as "sanitised HTML" |
| 4 | **XSS — SVG upload** | SVG uploads are parsed and stripped of script, event handlers and external references before being written to disk | `config/planvio.php` → `uploads.sanitise_svg` (`true`) |
| 5 | **CSRF** | Laravel's `ValidateCsrfToken` middleware on the whole web group; `SameSite=lax` session cookie; token regenerated on login and logout. The API uses bearer tokens and is stateless, so it is not CSRF-reachable | `bootstrap/app.php` middleware stack; `public/.htaccess` forwards `X-XSRF-Token` to PHP |
| 6 | **Mass assignment** | Every model declares an explicit fillable list. `$guarded = []` is forbidden anywhere in the codebase. Ownership columns (`workspace_id`, `user_id`, `created_by`, `uploaded_by`, `is_admin`) are never fillable — they are set by the framework or by an action | `#[Fillable([...])]` on `app/Models/User.php` and equivalents on every model |
| 7 | **IDOR** | Every record is reached through a policy check *and* a workspace-scoped query. Route model binding resolves through the scoped builder, so a foreign id 404s before authorization is even consulted | `app/Policies/*` + `app/Models/Scopes/WorkspaceScope.php` |
| 8 | **Workspace data leakage** | The three independent layers of section 1: column, scope, policy | `database/migrations/*`, `app/Models/Concerns/BelongsToWorkspace.php`, `app/Policies/*`; asserted in `tests/Feature/Security/` |
| 9 | **Privilege escalation** | Fixed role matrix with no runtime editing; `Gate::before` grants platform admins everything *except* inside workspaces they are not members of; `is_admin` is not mass-assignable and is set only from `/admin` | `app/Support/Permissions.php`, `app/Providers/AppServiceProvider.php` |
| 10 | **Malicious file upload** | Dual allow-list — the extension *and* the sniffed MIME type must both match — plus an unconditional blocked-extension list, plus SVG sanitisation, plus an optional content scan by a ClamAV daemon you already run. See section 6 | `config/planvio.php` → `uploads`; `app/Services/Uploads/*` |
| 11 | **Uploaded code execution** | Uploads are written outside the web root and are never linked from it. `.htaccess` files in every non-public directory disable the PHP handler and re-type `.php` as `text/plain` | `storage/.htaccess`, `storage/app/.htaccess`, `app/.htaccess`, `config/.htaccess`, and the `storage/` block in `public/.htaccess` |
| 12 | **Path traversal** | Attachments are addressed by primary key, never by a path from the request. The controller loads the `attachments` row, authorizes it, then streams `disk` + `path` as recorded at upload time. Stored paths are generated server-side; the original filename is kept only as a display label in `original_name` | `attachments` table; the download controller in `app/Http/Controllers/` |
| 13 | **Directory listing / source disclosure** | `Options -Indexes` at the document root; `.env`, `.git`, logs, dumps, archives and Composer/npm manifests blocked by pattern; every framework directory denied outright | `.htaccess` (root), `public/.htaccess`, per-directory `.htaccess` files |
| 14 | **Session fixation** | Session identifier regenerated on successful login and on privilege change; session invalidated and CSRF token regenerated on logout | Laravel auth guard; `sessions` table (database driver) |
| 15 | **Session theft** | `HttpOnly`, `Secure` and `SameSite=lax` cookie flags; host-only cookie domain; database-backed sessions with no on-disk payload; optional idle timeout | `.env` session block; `config/session.php`; `config/planvio.php` → `security.session` |
| 16 | **Brute force — login** | 5 attempts per 5-minute window, then a 15-minute lockout, keyed on email + IP. Generic failure message. Every lockout audited | `config/planvio.php` → `security.login`; `audit_logs` |
| 17 | **Brute force — password reset** | One reset request per address per 60 seconds; tokens hashed, single-use, 60-minute expiry | `config/auth.php` → `passwords.users` |
| 18 | **Account enumeration** | Identical response and timing from the reset form, the login form and the invitation flow regardless of whether the address exists | Section 2 |
| 19 | **Credential disclosure at rest** | `APP_KEY`-encrypted (`AES-256-CBC`) columns for `users.two_factor_secret`, `users.two_factor_recovery_codes` and `ai_providers.api_key`; settings rows flagged `is_encrypted` are stored and cached as ciphertext and decrypted per request | `config/app.php` → `cipher`; `app/Support/Settings.php` |
| 20 | **Credential disclosure in transit to logs** | Redaction key list applied to everything persisted from an AI run; `#[Hidden]` on password and remember token; `APP_DEBUG=false` and `LOG_LEVEL=error` in production. See section 7 | `config/ai.php` → `logging.redact_keys`; `.env.example` |
| 21 | **Secrets shipped in a release** | The release builder refuses to package if `.env`, a SQLite file, a `.pem`/`.key`/`.p12`/`.pfx`, or VCS metadata is found in the staging tree | `scripts/build-release.php` → `scanForSecrets()` |
| 22 | **Prompt injection into the AI** | Instructions and data are structurally separated: all workspace-derived text is wrapped in `<untrusted-data>` and the standing rule that it is data, never instruction, is repeated in the system prompt. `PromptBuilder` is the only code allowed to assemble provider messages. A pattern detector logs suspicious content for review — as a signal for the audit trail, never as the control | `config/ai.php` → `untrusted_wrapper`, `injection_guard`; `resources/ai/system.md`; [ARCHITECTURE.md §7.6](ARCHITECTURE.md) |
| 23 | **AI acting beyond its user** | Every tool call is Gate-checked as the acting user; risk gate and approval gate above the configured ceiling; destructive tools always require human approval and cannot be waived by a workspace policy | `config/ai.php` → `approvals`; `AgentContext::can()` |
| 24 | **Runaway AI run** | Bounded loop: max tool calls per run, max seconds, max errors, max repeats of the same tool; per-user hourly run cap; per-workspace kill switch | `config/ai.php` → `limits`; `ai_settings.kill_switch_engaged` |
| 25 | **Overlapping scheduled work** | AI automations take a database lock (`lock_token` + `locked_until`) before running; an overlapping cron tick no-ops | `ai_automations` table; `config/ai.php` → `automations.lock_ttl_seconds` |
| 26 | **Resource exhaustion / unbounded queries** | Every list is paginated, every board column is capped, and the API page size is hard-capped at 200 regardless of what the client asks for. Request *rate* is bounded separately: a generous per-account budget on every page request, and a much tighter one on search, AI run creation, exports and status reports. See §5.2 | `config/planvio.php` → `pagination`, `security.rate_limits` |
| 27 | **Clickjacking** | `X-Frame-Options: SAMEORIGIN` and `Cross-Origin-Opener-Policy: same-origin` on every response | `public/.htaccess` |
| 28 | **MIME sniffing** | `X-Content-Type-Options: nosniff` | `public/.htaccess` |
| 29 | **Referrer leakage** | `Referrer-Policy: strict-origin-when-cross-origin` | `public/.htaccess` |
| 30 | **Browser feature abuse** | `Permissions-Policy` denies geolocation, microphone, camera, payment, USB and topic-based ad cohorts | `public/.htaccess` |
| 31 | **Server fingerprinting** | `X-Powered-By` unset, `ServerSignature Off` | `public/.htaccess` |
| 32 | **Re-running the installer** | The installer refuses to run once `storage/app/planvio-installed.lock` exists, checked server-side on every installer request — hiding the route is explicitly not considered sufficient | `config/planvio.php` → `install.lock_file` |
| 33 | **Injected script reaching another origin** | A Content-Security-Policy on every page, the administration panel included: no script, style, font, image, frame or connection from anywhere but this origin, no `<base>` rewrite, no plugin, no framing by a third party. It does not stop XSS — see §5.1 for what it does and does not buy | `app/Http/Middleware/ContentSecurityPolicy.php`; `app/Providers/Filament/AdminPanelProvider.php`; `config/planvio.php` → `security.csp` |
| 34 | **Uploaded bytes executing in this origin** | Attachments are streamed with `default-src 'none'; sandbox`, and neither the application nor the panel policy is written onto a response that already carries one, so the strict policy always wins | `app/Http/Controllers/AttachmentController.php`; asserted in `tests/Feature/Security/ContentSecurityPolicyTest.php` and `tests/Feature/Security/AdminCspTest.php` |
| 35 | **The admin panel calling out to a third party** | The panel serves Inter from this installation instead of `fonts.bunny.net`, and draws the signed-in administrator's avatar locally instead of asking `ui-avatars.com` for it. Asserted per screen, so a reintroduced CDN fails the build rather than silently breaking under the policy | `app/Providers/Filament/AdminPanelProvider.php`; `app/Filament/Support/LocalAvatarProvider.php` |

### 5.1 Content-Security-Policy

Every response Planvio produces carries a CSP — the product's pages and the administration
panel alike. This is what it is, stated without marketing:

```
default-src 'self'; img-src 'self' data: blob:; font-src 'self';
style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-eval' 'unsafe-inline';
connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self';
object-src 'none'
```

**It does not stop XSS.** `script-src` keeps `'unsafe-inline'` and `'unsafe-eval'`, so script
that reaches a page still runs. Planvio's actual XSS controls are the ones in rows 2, 3 and 4
of the table above — Blade escaping, HTMLPurifier on stored rich text, and SVG sanitisation.
Read this header as a limit on the blast radius of a failure in those, not as a replacement
for them.

What it does buy, concretely:

- **No script from another origin.** Injected markup cannot pull a payload from an attacker's
  CDN; it is confined to what it can write inline in the page it already compromised.
- **No exfiltration to another origin.** `connect-src 'self'` and `form-action 'self'` mean a
  stolen session, CSRF token or form body cannot be posted or `fetch`ed anywhere but back to
  your own host.
- **No `<base>` rewrite.** `base-uri 'self'` stops one injected tag from silently repointing
  every relative URL on the page.
- **No plugins or applets** (`object-src 'none'`).
- **No framing by a third party.** `frame-ancestors 'self'` is the modern form of
  `X-Frame-Options`, which is also still sent.

**Why not a nonce.** A nonce is only worth having if `'unsafe-inline'` can be dropped, and
dropping it means removing every inline script *and* every Alpine expression in the product —
`x-on:click`, `x-show`, `x-data`, `:class` are all evaluated at runtime, which needs
`'unsafe-eval'` regardless. That is a rewrite of the front end, not a header change. A nonce
shipped alongside `'unsafe-inline'` would do nothing at all: browsers ignore the nonce once
the keyword is present. Claiming a nonce-based policy here would be theatre.

**The admin panel gets the same policy.** A Filament panel builds its own middleware stack
instead of running through the `web` group, so until the policy was named in
`AdminPanelProvider` as well, `/admin` was the one surface in Planvio without one — and it is
the surface where AI provider credentials are entered. The middleware now runs there too,
first in the panel's stack so it wraps every response the rest of it produces, including the
sign-in redirect and a maintenance 503.

The directives are the same, deliberately. Filament is Alpine and Livewire as well, so a
stricter `script-src` on `/admin` would break the panel rather than harden it; the panel's own
JavaScript and CSS come from `/js/filament` and `/css/filament` on this origin, which `'self'`
already covers. Making it *work* under the policy took two changes in the panel, both of which
were worth making anyway: Filament's font provider was loading Inter from `fonts.bunny.net`
and its avatar provider was asking `ui-avatars.com` to draw the signed-in administrator's
initials. Both now come from this installation — an outbound request per administration screen
is wrong on a self-hosted product and broken on one with no internet access, and a policy
whose first visible effect is a broken avatar teaches administrators to switch the policy off.
`tests/Feature/Security/AdminCspTest.php` fails if anything off-origin returns to a panel page.

**The one policy that still outranks both.** The attachment download route sets its own, far
stricter policy — `default-src 'none'; sandbox` — which this middleware never overwrites,
because a response made of bytes somebody uploaded is the last place a permissive policy
belongs. That holds for a download started from a panel session too, and is asserted.

**Configuration** lives in `config/planvio.php` under `security.csp`:

| Setting | Default | Meaning |
|---|---|---|
| `enabled` | `true` (`PLANVIO_CSP`) | Send the header at all |
| `report_only` | `false` (`PLANVIO_CSP_REPORT_ONLY`) | Send as `Content-Security-Policy-Report-Only` — evaluated and logged by the browser, not enforced |
| `extra_directives` | `[]` | Merged over the shipped set: a name that already exists replaces it, any other name is added, an empty string removes it |
| `panel.enabled` | unset (`PLANVIO_ADMIN_CSP`) | The same switch for `/admin`. Unset means "whatever the application does", so `PLANVIO_CSP=false` covers both surfaces; set it to move the panel on its own |
| `panel.report_only` | unset (`PLANVIO_ADMIN_CSP_REPORT_ONLY`) | Likewise. Set it to try a change in report-only on the panel while the product stays enforced |
| `panel.extra_directives` | unset | An array here replaces the application's list for the panel rather than adding to it; `[]` means the shipped directives and none of the application's overrides |

If you have audited your own installation and know nothing on the page needs it, tighten it:

```php
'csp' => [
    'enabled' => true,
    'report_only' => false,
    'extra_directives' => [
        'script-src' => "'self' 'unsafe-eval'",   // drop 'unsafe-inline'
        'frame-ancestors' => "'none'",
        'report-uri' => 'https://example.report-uri.com/r/d/csp/enforce',
    ],

    // Left as shipped, the panel would inherit all three of those. This tries
    // them on /admin without enforcing them there yet.
    'panel' => [
        'enabled' => null,
        'report_only' => true,
        'extra_directives' => null,
    ],
],
```

Try any change with `report_only` first. A CSP that is too tight does not error — it silently
stops the page working, and the only evidence is in the browser console.

### 5.2 Rate limiting

Four surfaces are throttled, on three different keys, for three different reasons.

| Surface | Limit | Keyed on | Where |
|---|---|---|---|
| Sign-in | 5 attempts / 5 min, then 15 min lockout | submitted email + IP | `security.login` |
| Password reset | 1 request / 60 s | email address | `config/auth.php` |
| `/api/v1` | 120 / min authenticated, 30 / min anonymous | personal access token | `planvio-api` limiter |
| Application pages | 300 / min signed in, 120 / min signed out | **user id**, or address when signed out | `security.rate_limits.web`, `.guest` |
| Search | 60 / min | user id | `security.rate_limits.search` |
| Starting an AI run | 10 / min | user id | `security.rate_limits.ai_runs` |
| CSV export | 10 / min | user id | `security.rate_limits.exports` |
| Project status report | 20 / min | user id | `security.rate_limits.reports` |

The page limit is deliberately far above anything a person generates — a busy board polling an
AI run in flight does not come close. It exists so that a stuck poller, a runaway script or a
scraper is bounded on hosting that has no other defence, not to pace real use. If you can see
it in normal work, raise it: `PLANVIO_RATE_WEB`.

**Keyed on the account, not the address.** An office behind one NAT address is one address and
many people; charging them to one bucket would make each of them the others' problem. Signed-out
traffic has no account and falls back to the address, at its own number — that surface is three
forms, each already throttled much harder on email plus address.

The four expensive operations are charged *twice*: once against their own bucket, once against
the page bucket. The cheap-request budget is about the server; the expensive-request budget is
about the operation. Search is a `LIKE '%term%'` scan no index can serve; an export streams a
whole workspace out of the database; a status report aggregates a project in one pass; an AI
run queues a job that will call a paid provider and may loop for minutes.

For AI specifically this is a *burst* control and not the budget. `ai.limits` already caps runs
per user per hour and per workspace per day, counted in `ai_runs` rather than in a cache — that
is what protects your provider bill. This sits in front of it so a double-clicked send or a
retrying tab cannot spend an hour's allowance in ten seconds.

Two of these arrive on Livewire's single `POST /livewire/update` endpoint, which no route
middleware can tell apart from any other component action, so the command palette and the AI
composer charge their bucket from inside the component. The palette pauses and says when it
will resume; the composer refuses the send and says the same. Neither throws.

A note on cost: the limiter uses your configured cache store, which on a default installation
is the database. That is two extra queries per request. If that matters on your host, set
`CACHE_STORE=file`.

---

## 6. File upload security

Attachments are the most dangerous thing a project-management tool accepts, because they
combine untrusted bytes, an attacker-chosen filename and a URL that other people click. There
are five layers.

### 6.1 The dual allow-list

**Both** checks must pass. Either one failing rejects the file.

1. The **extension** must appear in `config/planvio.php` → `uploads.allowed_extensions`.
2. The **sniffed MIME type** — read from the file's actual bytes with `finfo`, not from the
   browser-supplied `Content-Type` header — must appear in `uploads.allowed_mimes`.

Neither list alone is sufficient. An extension check alone accepts `payload.jpg` that is
really a PHP script. A MIME check alone accepts a genuine JPEG named `shell.php`. Requiring
both means a file must be what it claims *and* be named accordingly.

The browser's declared content type is used for nothing. It is attacker-controlled.

`fileinfo` is therefore a required PHP extension, listed in
`config/planvio.php` → `install.required_extensions`, and the installer refuses to proceed
without it. Without it there is no reliable sniff, and Planvio will not fall back to trusting
the client.

Accepted extensions cover documents, images, spreadsheets, presentations, archives, audio,
video and a few data formats:

```
jpg jpeg png gif webp svg bmp tiff heic
pdf doc docx odt rtf txt md
xls xlsx ods csv tsv
ppt pptx odp
zip gz tar rar 7z
mp3 wav ogg m4a
mp4 webm mov avi mkv
json xml ics
```

### 6.2 The unconditional blocked list

`uploads.blocked_extensions` is checked **before** anything else and is never overridden, not
by an allow-list entry, not by an administrator setting, not by a workspace policy:

```
php php3 php4 php5 php7 php8 phtml phar phps pht inc
cgi pl py rb sh bash
exe dll so bat cmd com scr msi jar
htaccess htpasswd
```

None of these appear in the allow-list, so in a correctly configured system the blocked list
never fires. It exists for the system that is not correctly configured — a host that executes
`.phtml`, a future edit that widens the allow-list carelessly, a double-extension trick like
`report.pdf.php`. Belt and braces.

`.htaccess` and `.htpasswd` are on the list for a specific reason: an uploaded `.htaccess`
could re-enable PHP execution in the directory it lands in. That upload is refused outright.

### 6.3 SVG sanitisation

SVG is on the allowed list because designers legitimately need it, and it is XML that can
carry `<script>`, `onload=`, `<foreignObject>` and external entity references. With
`uploads.sanitise_svg` set to `true` (the default), every SVG is parsed and stripped of
script, event handlers, external references and embedded entities before it is written. The
file stored on disk is not the file that was uploaded.

If you do not need SVG at all, remove `'svg'` from `uploads.allowed_extensions` and
`'image/svg+xml'` from `uploads.allowed_mimes`.

### 6.4 Storage outside the web root

Attachments are written to the private disk, rooted at:

```
storage/app/private/
```

This directory is **not** under `public/` and is not reachable over HTTP under either
supported layout — whether the document root points at `planvio/public` or Planvio was
extracted into `public_html`.

The `public/storage` symlink exists, but carries avatars and workspace logos only. Task and
comment attachments are never placed there.

Files are written under server-generated paths. The user's original filename is stored in
`attachments.original_name` purely as a display label and as the `Content-Disposition`
filename on download; it never becomes part of a path on disk.

Size limits:

| Setting | Default | `.env` key |
|---|---|---|
| `uploads.max_size_kb` | `20480` (20 MB) | `PLANVIO_MAX_UPLOAD_KB` |
| `uploads.avatar_max_size_kb` | `2048` (2 MB) | — |

Both must sit below your PHP `upload_max_filesize` and `post_max_size` — see
[CPANEL.md Step 1](CPANEL.md).

### 6.5 The authorising download controller

There is no direct URL to an attachment. Every download goes through a controller that, in
order:

1. Loads the `attachments` row by primary key through the workspace-scoped builder.
2. Authorizes the acting user against the attachment's policy — which re-resolves workspace
   membership, then the project and ownership refinements.
3. Streams the file from `disk` + `path` as recorded on the row.
4. Sends `Content-Disposition: attachment` with a sanitised filename, and a `Content-Type`
   taken from the stored, sniffed `mime` column — never from the request.

Because step 1 goes through the scope and step 2 goes through the policy, an attachment id
belonging to another workspace is not found, and an id belonging to a project the user cannot
see is refused. Guessing an integer gets you nothing.

Downloads are not cached by intermediaries, and attachment URLs carry no signed component that
could be forwarded to grant access — every request is authorized afresh against the current
membership. Removing somebody from a project revokes their attachment access immediately.

### 6.6 Per-directory PHP execution guards

Every non-public directory in the release ships an `.htaccess` that denies access and disables
PHP execution:

```apache
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>

Options -Indexes -ExecCGI

<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule lsapi_module>
    RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8
</IfModule>
AddType text/plain .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .inc
```

In the release it ships in `app/`, `bootstrap/`, `config/`, `database/`, `docs/`, `lang/`,
`resources/`, `routes/`, `storage/` and `storage/app/`. `vendor/` has no per-directory file of
its own; it is covered by the blanket directory rule in the root `.htaccess`.
`scripts/build-release.php` rewrites the `storage/` and `storage/app/` copies during packaging,
because the storage tree is reset to an empty skeleton for the release and the guard would
otherwise be lost.

Three notes on this file:

- **`AddType text/plain` is the last line of defence.** If a host ignores both `php_flag` and
  `RemoveHandler`, re-typing `.php` as plain text still stops the handler from claiming it.
- **`Options -ExecCGI`** covers hosts running PHP through suPHP or CGI rather than mod_php.
- **The root `.htaccess` deliberately does *not* disable PHP.** A `php_flag engine off` at
  that level would cascade into `public/` and stop the application from running under the
  `public_html` layout. PHP is disabled per directory, everywhere except `public/`.

`public/.htaccess` adds one more rule specifically for the storage symlink:

```apache
RewriteRule ^storage/.*\.(php|phtml|phar|php[3-8]|inc|pl|py|cgi|sh|htaccess)$ - [F,L,NC]
```

Even if a script somehow reached the avatars directory, requesting it returns 403.

### 6.7 Optional malware scanning

Planvio does not ship a virus scanner and will not. Signatures need updating daily and
scanning needs a resident daemon; neither belongs in a ZIP somebody extracts into a cPanel
account, and a bundled scanner with month-old definitions would be worse than none because
people would trust it.

What Planvio ships is the seam. If your server already runs ClamAV, one config value puts it
in the upload path:

```php
'scanner' => [
    'driver' => env('PLANVIO_UPLOAD_SCANNER'),      // null, or 'clamav'
    'host' => env('PLANVIO_CLAMAV_HOST', '127.0.0.1'),
    'port' => (int) env('PLANVIO_CLAMAV_PORT', 3310),
    'socket' => env('PLANVIO_CLAMAV_SOCKET'),        // wins over host/port when set
    'timeout' => (int) env('PLANVIO_CLAMAV_TIMEOUT', 30),
    'max_bytes' => (int) env('PLANVIO_CLAMAV_MAX_BYTES', 26214400),
],
```

```
PLANVIO_UPLOAD_SCANNER=clamav
PLANVIO_CLAMAV_SOCKET=/var/run/clamav/clamd.ctl
```

A unix socket is preferred when clamd is on the same host: nothing on the network can talk to
it. TCP (`TCPSocket 3310` in `clamd.conf`) is the fallback.

**Where it runs.** Last, after the extension, size and MIME checks and before the SVG rewrite.
Those checks are free and reject the overwhelming majority of bad uploads; there is no reason
to spend daemon CPU on a file the gate has already refused. Scanning before the SVG rewrite is
deliberate too — the bytes worth examining are the ones that arrived, not the ones Planvio
produced from them.

**How.** ClamAV's `INSTREAM` command, not `SCAN <path>`. `SCAN` needs clamd to be able to open
PHP's temporary directory itself, which on shared hosting it usually cannot, and it would put
a path from this process into a command the daemon parses. `INSTREAM` sends the bytes.

**It fails closed, and that is the point.** A scanner that is configured and cannot be reached
— refused connection, timeout, restarted daemon, a response that is not a verdict — **refuses
the upload**. It does not pass the file through. The alternative would be a control that is
present in the config file and absent in fact, and silent about the difference: the one
failure mode where an administrator stops watching because they believe scanning is happening.

The two refusals are separate and say different things. A recognised signature tells the
uploader their file was refused, and records the signature name in the exception context for
the audit trail, not in the sentence they read. An unreachable daemon tells the uploader to
inform an administrator, and records which scanner and why. A driver name Planvio does not
implement refuses everything with that as the reason, rather than quietly falling back to no
scanning.

**What it still does not cover.** Planvio does not look inside archives — a `.zip` is scanned
as a zip, and clamd's own archive handling is clamd's business. And with no scanner
configured, the honest position is unchanged from 1.0: a real Word document carrying a real
macro passes every check Planvio can make on its own.

---

## 7. What Planvio never logs

Planvio does not write the following to `storage/logs/`, to `activities`, to `audit_logs`, to
`ai_tool_runs`, to a queue payload, to a webhook body, or to the browser.

| Never logged | How that is guaranteed |
|---|---|
| **User passwords** | Hashed before they leave the request; `#[Hidden(['password', 'remember_token'])]` on `App\Models\User` keeps them out of every serialisation. Password fields are excluded from request logging and from validation-error output |
| **Database credentials** | Live only in `.env`. `APP_DEBUG=false` in production means no stack trace ever renders a connection array. `.env` is blocked at the web server by pattern in both `.htaccess` files |
| **AI provider API keys** | Stored encrypted in `ai_providers.api_key`. Never returned to the browser after saving, never rendered in a form value, never included in an error message. `config/ai.php` → `logging.redact_keys` strips `api_key`, `apikey`, `password`, `secret`, `token`, `authorization`, `access_token`, `refresh_token`, `private_key` and `client_secret` from anything persisted from a run |
| **SMTP passwords** | Live in `MAIL_PASSWORD` in `.env`. The admin UI shows a masked field and does not echo the stored value. Mail transport exceptions are caught and reported without the credential |
| **API tokens** | Sanctum stores a SHA-256 hash in `personal_access_tokens`, not the token. The plaintext token is shown to the user exactly once, at creation, and cannot be recovered afterwards |
| **Session payloads in logs** | Sessions live in the `sessions` table, not in the log stack |
| **AI prompt bodies** | `config/ai.php` → `logging.store_prompts` is `false` by default (`AI_STORE_PROMPTS=false`). Prompt bodies contain workspace content — task titles, comments, wiki text — so they are not persisted unless you deliberately turn it on for debugging |
| **Secrets in activity records** | `activities.properties` records attribute changes as `{attribute, old, new}` and is documented in the schema as never carrying secrets. Sensitive attributes are excluded from the diff rather than redacted after the fact |

### What *is* recorded, and where

| Table | Contents | Retention |
|---|---|---|
| `activities` | Domain history — who changed what on which record | `PLANVIO_ACTIVITY_RETENTION_DAYS`, default `0` = keep forever |
| `audit_logs` | Security and configuration events — sign-ins, lockouts, 2FA changes, role changes, settings changes — with IP and user agent | `PLANVIO_AUDIT_RETENTION_DAYS`, default `730` |
| `ai_runs` / `ai_tool_runs` | One row per agent run and per tool invocation: tool name, risk, redacted arguments truncated to 2,000 characters, a result summary truncated to 1,000, approval state and who approved | `ai_settings.retention_days` |
| `storage/logs/laravel.log` | Application errors only. `LOG_LEVEL=error`, `LOG_STACK=daily`, `LOG_DAILY_DAYS=14` | 14 days, rotated |

`ai_tool_runs` is the audit spine for AI activity: every tool call the agent made, in
sequence, with the arguments it used and whether a human approved it. It is readable by users
holding `ai.view_logs`.

### Turning on prompt logging temporarily

If you are debugging an AI problem and need to see what the model actually received:

```
AI_STORE_PROMPTS=true
```

Turn it off again afterwards, and delete the stored messages. Prompt bodies contain the
workspace content that was assembled into context, and that content is subject to whatever
confidentiality your projects are subject to.

---

## 8. Hardening checklist

Work through this after installing. Items marked **required** should be done before you invite
anybody.

### Transport and headers

- [ ] **Required.** HTTPS certificate issued (**SSL/TLS Status → Run AutoSSL**) and **Force
      HTTPS Redirect** enabled in **Domains**.
- [ ] **Required.** `APP_URL` in **Admin → Settings → General** begins with `https://`.
- [ ] Once HTTPS is confirmed on every hostname you serve, uncomment the HSTS line in
      `public/.htaccess`:
      ```apache
      Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
      ```
      Do this last. HSTS is not easily reversible in browsers that have already seen it.
- [ ] Confirm the security headers are actually arriving. Load your site and check the
      response headers for `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` and
      `Permissions-Policy`. If they are missing, `mod_headers` is not enabled — ask your host.
- [ ] Confirm `Content-Security-Policy` is on the same response. Unlike the four above it is
      set by PHP, not by `.htaccess`, so it arrives whether or not `mod_headers` is enabled.
      See §5.1 for what it does and does not stop.
- [ ] Confirm it is on `/admin` as well. The panel has its own middleware stack, so this is a
      separate registration and worth checking separately.
- [ ] Optional, and only after reading §5.1: tighten the policy in
      `config/planvio.php` → `security.csp.extra_directives`. Set `report_only` to `true`
      first, use the site for a day, then enforce. `security.csp.panel` takes the same three
      settings if you want to move the panel and the product independently.

### File exposure

- [ ] **Required.** `https://yourdomain.com/.env` returns **403**, not a download.
- [ ] `https://yourdomain.com/storage/` does not list files.
- [ ] `https://yourdomain.com/vendor/` returns 403.
- [ ] `https://yourdomain.com/composer.json` returns 403.
- [ ] **Admin → System Health** reports the `.env` protection check as passing. It runs the
      same request for you.

### Application configuration

- [ ] **Required.** `APP_DEBUG` is `false`. Confirm in **Admin → System Information**. A
      debug-mode stack trace exposes environment variables including database credentials.
- [ ] **Required.** `APP_ENV` is `production`.
- [ ] `SESSION_SECURE_COOKIE=true` (the installer's default — verify it survived).
- [ ] Consider `SESSION_ENCRYPT=true` if anyone other than you can read the database.
- [ ] Consider an idle timeout: `PLANVIO_IDLE_TIMEOUT=30`.
- [ ] `LOG_LEVEL=error` in production. `debug` writes far more than you want retained.
- [ ] Delete `docs/` from the deployed tree if you do not want it there. It ships with an
      `.htaccess` denying access, but the safest file is the one that is absent.

### Accounts and access

- [ ] **Required.** The platform admin account uses a unique password held in a password
      manager, not one reused from anywhere else.
- [ ] **Required.** Two-factor authentication is enabled on the platform admin account.
- [ ] Set `PLANVIO_2FA_REQUIRED_ADMINS=true` so it stays that way and applies to any admin you
      add later.
- [ ] Consider requiring 2FA for workspace owners and admins. Per workspace, tick the roles in
      **Settings → Security** — the screen tells you how many members it would hold at
      enrolment before you save. Installation-wide, set
      `security.two_factor.required_for_roles` in `config/planvio.php` to `['owner', 'admin']`;
      roles set there cannot be unticked from a workspace.
- [ ] Turn on the breach check if your host allows outbound HTTPS:
      `PLANVIO_CHECK_PWNED=true`.
- [ ] Raise the minimum password length if 10 is not enough for you:
      `PLANVIO_PASSWORD_MIN=14`.
- [ ] Review **Admin → Users** for accounts that should be deactivated (`is_active = false`)
      rather than left dormant.
- [ ] Review every workspace's member list. Confirm nobody holds `owner` or `admin` who does
      not need it, and that guests are guests.
- [ ] Confirm no platform admin has joined a workspace they have no business being in — that
      membership is visible in the member list precisely so you can audit it.

### Uploads

- [ ] Confirm `fileinfo` is enabled in **Select PHP Version → Extensions**. Without it the
      MIME sniff cannot run.
- [ ] Set `PLANVIO_MAX_UPLOAD_KB` to the smallest value your users actually need. The default
      is 20 MB.
- [ ] If you do not need SVG, remove `'svg'` from `uploads.allowed_extensions` and
      `'image/svg+xml'` from `uploads.allowed_mimes` in `config/planvio.php`.
- [ ] If you do not need archives or executable-adjacent formats, trim `zip`, `gz`, `tar`,
      `rar`, `7z` from the allow-list. Planvio cannot scan inside an archive.
- [ ] If your server already runs ClamAV, point Planvio at it:
      `PLANVIO_UPLOAD_SCANNER=clamav` plus `PLANVIO_CLAMAV_SOCKET` or
      `PLANVIO_CLAMAV_HOST`/`PLANVIO_CLAMAV_PORT` (§6.7). Then upload a file and confirm it
      still works — the scanner **fails closed**, so a wrong socket path stops uploads rather
      than silently doing nothing.
- [ ] If you turn scanning on, add clamd to whatever you already monitor. Planvio will refuse
      uploads while it is down, which is the correct behaviour and a bad thing to discover
      from a user.
- [ ] Upload a harmless test file, then confirm that fetching it by a guessed path under
      `https://yourdomain.com/storage/` fails. Attachments should only ever be reachable
      through the in-app download link.

### AI

- [ ] If you are not using AI, leave `AI_ENABLED=false`. Nothing else in Planvio depends on
      it. No route, job, tool or provider call runs while it is off.
- [ ] If you are using AI, keep `AI_STORE_PROMPTS=false` in production.
- [ ] Start in **Assistant** mode. Move to **Copilot** once you trust the output. Do not start
      in **Autonomous**.
- [ ] Review **Allowed tools** and **Approval required** in **Admin → AI** before enabling
      Copilot or Autonomous.
- [ ] Confirm `ai.use` is not held by roles that should not have it — by default guests do
      not, and `ai.autonomous` is limited to workspace owners and admins.
- [ ] Know where the kill switch is: **Admin → AI → Kill switch**. Engaging it stops new runs
      and aborts queued ones at their next step.
- [ ] Set `ai_settings.retention_days` so AI run history does not accumulate indefinitely.

### Database and backups

- [ ] **Required.** The MySQL user Planvio uses has privileges on *its own database only*.
      cPanel's *Add User To Database* does this correctly; a shared or account-wide user does
      not.
- [ ] **Required.** A backup schedule exists — cPanel's own backups, your host's, or
      **phpMyAdmin → Export** on a calendar reminder. Back up `storage/app/private` as well as
      the database; attachments are not in the database.
- [ ] Test a restore at least once. An untested backup is a hypothesis.
- [ ] Keep `.env` out of your backups' publicly reachable locations. It holds `APP_KEY`, and
      `APP_KEY` decrypts your 2FA secrets and API keys.

### Ongoing

- [ ] Keep PHP on a supported version. **Select PHP Version** — 8.3 and 8.4 are supported.
- [ ] Apply Planvio releases when they are published. See [UPGRADING.md](UPGRADING.md).
- [ ] Check **Admin → System Health** monthly. It reports the scheduler's last run, the queue
      backlog and the `.env` protection check.
- [ ] Review `audit_logs` after any staff change — **Admin → Audit log**. Look for sign-ins
      from unexpected addresses, 2FA resets and role changes.
- [ ] Rotate AI provider API keys on the schedule your provider recommends. Replacing the key
      in **Admin → AI** is enough; nothing caches it elsewhere.

---

## 9. Limitations

Things Planvio does not do. Read this before assuming a control exists.

**No virus scanner ships with Planvio.** The seam does: point `uploads.scanner.driver` at a
ClamAV daemon you already run and every upload is scanned before it is stored, failing closed
if the daemon cannot be reached (§6.7). With no scanner configured — the default — Planvio
validates type, extension and size and prevents execution on the server, but does not inspect
contents. A malicious `.docx` uploaded by one member and downloaded by another is a threat
Planvio does not address on its own.

**The Content-Security-Policy keeps `'unsafe-inline'` and `'unsafe-eval'`.** One is sent on
every page, `/admin` included (§5.1), and it is worth having — it confines injected script to
this origin and blocks exfiltration, `<base>` rewrites, plugins and third-party framing. It
does not prevent XSS, and Planvio does not claim it does. A policy that would requires
removing every inline script and every Alpine expression from the product, which is a
front-end rewrite rather than a header. `security.csp.extra_directives` is there for
administrators who have audited their own install and want to go further, and
`security.csp.panel` is the same three settings for the administration panel alone.

**HSTS is shipped commented out.** Enabling it before HTTPS works on every hostname you serve
will make the site unreachable in browsers that have already cached the policy. It is
deliberately opt-in, and it belongs at the end of your hardening pass, not the start.

**No SSO, SAML, OAuth or LDAP.** Authentication is local: email, password, optional TOTP.
There is no identity-provider integration and no directory sync.

**No WebAuthn, passkeys or hardware security keys.** The second factor is TOTP, or a recovery
code. There is no U2F/FIDO2 support.

**No per-IP allow-listing or geographic restriction.** If you need to restrict who can reach
Planvio at the network level, do it in cPanel's IP Blocker or at your host's firewall.

**No full database encryption at rest.** Specific columns are encrypted — 2FA secrets, 2FA
recovery codes, AI provider API keys, and settings rows flagged `is_encrypted`. Project data,
task content, comments and wiki pages are stored in plaintext in MySQL, as they must be for
the application to search and sort them. If you need encryption at rest for everything, that
is a MySQL or filesystem-level control on your host, not an application one.

**Attachment contents are not encrypted on disk.** They are outside the web root and gated by
policy, but a person with filesystem access to `storage/app/private` can read them.

**Requiring 2FA of platform administrators is still config-only.**
`PLANVIO_2FA_REQUIRED_ADMINS` has no screen, because a platform admin is not a member of any
workspace and no workspace's settings should be able to reach them. The per-workspace roles
*do* have a screen now — **Settings → Security** (§3) — and the config array remains the
installation-wide floor beneath it.

**`check_compromised` is off by default**, so a user can currently choose a password that
meets the length and character rules and still appears in a public breach corpus. Turn it on
if your host permits outbound HTTPS.

**The idle timeout is off by default** (`PLANVIO_IDLE_TIMEOUT=0`). Only `SESSION_LIFETIME`
(120 minutes) applies until you set one.

**Rate limiting is per account and in-process, not a WAF.** Every surface is throttled now —
sign-in, password reset, the API, ordinary page requests, search, AI run creation, exports and
status reports (§5.2) — but the limits are counted in Planvio's own cache and keyed on the
signed-in account. That bounds a stuck client, a scraper with a token and a retry loop. It
does not stop a distributed flood of unauthenticated requests, which is a network-layer
problem: put Cloudflare, your host's WAF or `mod_evasive` in front of Planvio if you are
exposed to one.

**The `.htaccess` protections assume Apache or LiteSpeed with `AllowOverride` enabled.** On
nginx, or on a host that ignores `.htaccess`, none of the per-directory guards apply. That is
the whole reason [CPANEL.md Step 3](CPANEL.md) puts the application above the web root: with
the document root pointing at `planvio/public`, the guards are defence in depth rather than
the only thing standing between the web and your `.env`. If you must use the `public_html`
layout, `.htaccess` *is* the protection — verify it by requesting `/.env` and confirming a
403.

**Security regression tests are the contract, not a certificate.** `tests/Feature/Security/`
asserts that cross-workspace access is denied. Passing tests are evidence, not proof, and
Planvio has not been through an independent third-party penetration test.

**Composer dependencies are pinned at release time.** Because production runs without
Composer, you cannot patch a vendored library in place — a dependency security fix reaches you
as a new Planvio release. Apply releases promptly.

---

## 10. Reporting a vulnerability

If you have found a security problem in Planvio, report it privately through GitHub:

**[github.com/hatemsweileh/planvio/security/advisories/new](https://github.com/hatemsweileh/planvio/security/advisories/new)**
— the *Security* tab, then *Report a vulnerability*.

There is deliberately no security mailbox. A published address is an address that has to be
watched forever, and a report that arrives at one nobody is reading is worse than a report
that was never sent. A GitHub advisory is private until it is published, keeps the whole
exchange in one place, and lets you be credited by name when the fix ships.

If you cannot use GitHub advisories, open a normal issue saying only *that* you have a
security report and how to reach you — **no detail** — and you will be contacted privately.

Please do **not** open a public issue with the detail in it, post it to a forum, or describe
it on social media before it is fixed.

### What to include

1. A description of the issue and what an attacker gains from it.
2. The Planvio version — **Admin → System Information**, or `config/planvio.php` → `version`.
3. PHP version, database server and version, and web server (Apache / LiteSpeed / nginx).
4. Reproduction steps, precise enough to follow. A short screen recording is welcome.
5. Whether you have disclosed it anywhere else.

Please do not include real customer data, credentials, or a live URL with a working session.
Redact what you can and still make the report reproducible.

### What happens next

| When | What |
|---|---|
| Within 3 working days | Acknowledgement that the report arrived and is being read |
| Within 10 working days | An assessment: whether it is confirmed, its severity, and the intended fix |
| Thereafter | Progress updates at least every 14 days until it is resolved |

Fixes for confirmed high-severity issues ship as a patch release with an entry in
[CHANGELOG.md](CHANGELOG.md) describing the class of problem and the affected versions,
without the detail needed to exploit unpatched installations.

### Scope

**In scope:** the Planvio application — authentication, authorization, the tenancy layers, the
AI tool pipeline, file handling, the installer, the API, and the shipped `.htaccess` hardening.

**Out of scope:** vulnerabilities in Laravel, Filament, or another upstream dependency —
report those to their maintainers, and tell us so we can ship an updated release. Also out of
scope: findings that require an already-compromised server, physical access, or a
misconfiguration this document tells you to avoid, such as running with `APP_DEBUG=true`.

We ask for coordinated disclosure: give us a reasonable window to ship a fix before publishing.
We will credit you in the release notes unless you would rather we did not.

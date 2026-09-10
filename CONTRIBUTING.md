# Contributing to Planvio

Thank you for considering it. This document tells you how the codebase is put together and
what a change has to satisfy to be merged, so you can spend your time on the work rather
than on guessing house style.

---

## Before you start

**Read [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).** It is normative, not descriptive:
every table, column, enum case and namespace in it is fixed, and a pull request that renames
or reshapes one is a change to the contract, not an implementation detail. If you think the
contract is wrong, open an issue about the contract first.

For anything beyond a typo or an obvious bug, **open an issue before you write code.** It
costs you five minutes and can save you a weekend spent on an approach that will not be
merged.

---

## Setting up

```bash
git clone https://github.com/hatemsweileh/planvio.git
cd planvio

composer install
npm install
npm run build

cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan planvio:demo        # a realistic sample workspace
php artisan serve
```

The default `.env` uses SQLite, which is enough for everything except verifying a migration.
**Test schema changes against MySQL or MariaDB as well** — the two disagree about index name
length, nullable uniques and a handful of column types, and the test suite alone will not
catch it.

Requirements: PHP 8.3 or 8.4, Composer, Node 20+, and MySQL 5.7+ / MariaDB 10.6+ if you are
touching the schema.

---

## The rules that are not negotiable

These exist because Planvio is a multi-tenant application that people self-host and that an
AI agent can act inside. Each of them has a test asserting it.

### 1. Every tenant-scoped query is workspace-scoped **and** policy-checked

Not one or the other. Three independent layers protect a workspace boundary — the
`workspace_id` column, the `WorkspaceScope` global scope, and the policy — and they are
arranged so the scope fails *open* and the policy fails *closed*. A bug in one must not
create a leak.

```php
// Wrong: relies on the global scope alone.
$task = Task::findOrFail($id);

// Right: the policy independently re-resolves membership.
$task = Task::findOrFail($id);
$this->authorize('view', $task);
```

Any new tenant-scoped resource needs a test in `tests/Feature/Security/` proving a member of
workspace A cannot reach it in workspace B.

### 2. Actions do not authorize; callers do

`App\Actions\*` classes are invokable and transactional. They validate *domain invariants* —
a dependency that would close a loop, the last owner being demoted — and nothing else. The
Livewire component, controller or AI tool that calls them is responsible for the `Gate`
check. Keeping it that way is what lets the same action serve a human click and an AI tool
call with identical rules.

### 3. The AI reaches the database only through an action, behind a gate

No raw SQL, no query builder assembled from model output, no `eval`, no dynamic class name
from a tool string, no filesystem, no shell. A new AI tool validates its arguments against
its own JSON schema, asserts workspace scope, checks the permission **as the acting user**,
and then calls exactly one `App\Actions\*` class. See
[`docs/AI_SECURITY.md`](docs/AI_SECURITY.md).

### 4. Never log or persist a secret

API keys, passwords, SMTP credentials and tokens must not reach a log line, an exception
message, an activity row, an audit row or an HTTP response. `App\Support\SecretScrubber`
handles free text that arrived from outside; the redactor handles structured data.

### 5. Mass assignment stays a security control

Every model declares an explicit `$fillable`. `$guarded = []` and `Model::unguard()` are
never used.

### 6. Every user-visible string goes through `__()`

A literal English key (`__('Create project')`) belongs in `lang/en.json`, which is
**generated** — run `php artisan lang:scan`, never hand-edit it. A key assembled at runtime
(`__('enums.priority.'.$case->value)`) cannot be found by a scanner and must live in a
`lang/en/*.php` group instead.

If you add a string, add its Arabic too, or the integrity test will fail. See
[`docs/LOCALISATION.md`](docs/LOCALISATION.md).

### 7. Right-to-left is not optional

Use logical properties — `ms-`/`me-`, `ps-`/`pe-`, `start-`/`end-`, `border-s`/`border-e` —
never `ml-`, `pr-`, `left-`, `text-right`. Wrap identifiers, timestamps and code in
`<x-ui.bidi>` so `WEB-142` does not render as `142-WEB` inside an Arabic sentence.

Never build a Tailwind class by interpolation. Tailwind scans source text and never
evaluates it, so `"ms-{{ $n }}"` compiles to nothing at all. Put the full class names in a
PHP array and select from it.

---

## Conventions

- `declare(strict_types=1);` on the first line after `<?php`, in every file.
- `final class` unless the class is designed for extension.
- Constructor property promotion; typed properties and return types everywhere.
- `protected function casts(): array`, not a `$casts` property.
- Livewire components live at `App\Livewire\App\<Area>\<Name>` with their view at
  `resources/views/livewire/app/<area>/<name>.blade.php`.
- Services are read-side and free of side effects. Computation lives there, not in models.
- Comment *intent*, not mechanism. A comment that restates the code is noise; one that
  explains why the obvious approach was rejected is worth keeping.

### What not to add

Planvio targets shared hosting with no daemon. A dependency on Redis, a queue worker that
must stay running, a websocket server, a Node process in production, or Docker is out of
scope by design, not by oversight. If a feature seems to require one, say so in the issue
and we will find the version that does not.

Check `docs/ARCHITECTURE.md` before adding any Composer or npm package.

---

## Testing

```bash
php artisan test                    # everything
php artisan test --testsuite=Security
php vendor/bin/pint                 # formatting; run before you push
```

The suite is PHPUnit 12, not Pest. It is organised as:

| Suite | Holds |
|---|---|
| `tests/Unit` | Actions, services, calculators, pure logic |
| `tests/Feature` | HTTP, Livewire, jobs, notifications, the API |
| `tests/Feature/Security` | Isolation, authorization, uploads, AI containment |
| `tests/Feature/Installer` | The browser installer, in its own suite |

A security test asserts that something is **not** possible. Write it so its failure message
says what breach it found, not just that an assertion failed.

Both must be green before a pull request is reviewed.

---

## Pull requests

- Branch from `main`.
- One concern per pull request. A refactor bundled with a feature is two reviews wearing a
  trench coat.
- Write a description that says what changed and *why the obvious alternative was not
  chosen*. That is the part a reviewer cannot reconstruct.
- Update the docs in the same pull request. A `docs/LIMITATIONS.md` entry that your change
  made untrue is a bug in the change.
- If you found the bug by running something, say what you ran. "Verified by loading the
  board at 375px in Arabic" is worth more than "tested".

By contributing you agree that your contribution is licensed under the
[GNU AGPL v3.0](LICENSE), the same licence as the project.

---

## Reporting a security vulnerability

**Do not open a public issue.** Use GitHub's private vulnerability reporting on this
repository — the *Security* tab, then *Report a vulnerability*. Details and scope are in
[SECURITY.md](SECURITY.md).

---

## Code of conduct

Participation is governed by the [Code of Conduct](CODE_OF_CONDUCT.md). It is short, and it
amounts to: be the kind of person other people want to build software with.

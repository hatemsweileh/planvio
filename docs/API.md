# Planvio REST API

Version 1 · base path `/api/v1` · Sanctum bearer tokens

Everything the product does to your data, Planvio's own screens do through the same Actions
and the same policies this API calls. There is no privileged path: a token acts as the person
who created it, and it can do exactly what that person could do by hand — no more, and never
in a workspace they do not belong to.

Every JSON body in this document is a real response, captured from the test suite against a
workspace called `acme`. Only the host has been changed, from the test runner's
`http://localhost:8000` to `https://planvio.example.com`; absolute URLs in responses are built
from your installation's `APP_URL`.

---

## Contents

- [Authentication](#authentication)
- [Workspace scoping](#workspace-scoping)
- [The envelope](#the-envelope)
- [Errors](#errors)
- [Pagination](#pagination)
- [Rate limits](#rate-limits)
- [Endpoints](#endpoints)
  - [Identity](#identity)
  - [Workspaces](#workspaces)
  - [Projects](#projects)
  - [Tasks](#tasks)
  - [Comments](#comments)
  - [Milestones](#milestones)
  - [Time entries](#time-entries)
  - [Tags](#tags)
  - [People](#people)
  - [Activity](#activity)
  - [Search](#search)
  - [AI runs](#ai-runs)
- [Webhooks](#webhooks)
- [Versioning](#versioning)

---

## Authentication

Every request carries a personal access token as a bearer credential:

```
Authorization: Bearer 7|kR2nQvXe8sT1mB4jL0pW6yZ3aC5dF9gH2iJ4kL6m
Accept: application/json
```

There is no other way in. Session cookies are not accepted, there are no API keys, and there
are no service accounts. A token *is* a person.

### Creating a token

Tokens are minted in the product, on **Profile → Security → API tokens**:

1. Sign in and open `https://planvio.example.com/profile/security`.
2. Under **API tokens**, choose **New token** and give it a name that says what will use it —
   `CI pipeline`, `Zapier`, `warehouse sync`. The name is the only thing you will see later.
3. Save. **The token is shown exactly once, on the screen that mints it.**

There is no screen and no endpoint that reveals a token again. Sanctum stores a hash; the
plaintext exists only in that one response. If you lose it, revoke it and make another.

Revoking is immediate and is done on the same screen. A revoked token's next request is a
`401` — nothing is cached.

### What a token can do

A token carries no scope list of its own. Its authority is its owner's workspace role,
evaluated per record, on every request, by the same policies the UI runs
(`docs/ARCHITECTURE.md` §4). Two authorization systems disagreeing about one request is worse
than one that is a little coarse, so there is only one.

Two consequences worth stating:

- A token belonging to a **guest** sees only the projects that guest was explicitly added to.
- Deactivating an account revokes every token it holds, immediately, everywhere — a
  deactivated user's token answers `403` on every endpoint.

### Testing your token

```bash
curl -s https://planvio.example.com/api/v1/me \
  -H "Authorization: Bearer $PLANVIO_TOKEN" \
  -H "Accept: application/json"
```

---

## Workspace scoping

A person can belong to several workspaces, so every request that touches workspace data has to
say which one it means. Planvio uses a header:

```
X-Planvio-Workspace: acme
```

The value is a workspace **slug** or a numeric **id** — the same two forms
`GET /api/v1/workspaces` returns.

**The header is mandatory on scoped endpoints, and its absence is an error rather than a
guess.** Defaulting to "the workspace you used last" would let one unchanged script write into
different tenants on different days, which is exactly the failure this refuses to have.

```json
{
  "error": {
    "status": 400,
    "code": "bad_request",
    "message": "This endpoint is workspace-scoped. Send the X-Planvio-Workspace header with a workspace slug or id."
  }
}
```

### Why a header and not a path segment

A resource has exactly one URL. `tasks/42` identifies the same record whatever route reached
it, so a stored link, a log line and a bug report all refer to one address. With the tenant in
the path a record acquires a second identity and the two can disagree —
`/w/acme/tasks/42` where task 42 belongs to `northwind` is a request that has to be *caught*
rather than one that cannot be written. It also keeps the surface flat, which is what makes
`GET /api/v1/tasks` the same shape as `GET /api/v1/projects`.

### Three endpoints are not scoped

`GET /me`, `GET /workspaces` and `GET /workspaces/{workspace}` answer without the header,
because they are how a client discovers what to put in it.

### Naming a workspace you are not in

`404`, not `403`. A `403` would confirm that a workspace with that slug is registered on the
installation, which is most of what somebody enumerating slugs wants to know. The same rule
holds for every record: a task, project, comment or milestone belonging to another workspace
is *not found*, never *forbidden*.

---

## The envelope

Success is always `data`, optionally with `meta`:

```json
{ "data": { "id": 1, "title": "…" } }
```

```json
{
  "data": [ { "id": 1 }, { "id": 2 } ],
  "meta": { "page": 1, "per_page": 50, "total": 137, "last_page": 3 }
}
```

Timestamps are ISO-8601 with an offset (`2026-09-08T21:25:29+00:00`). Calendar dates — due
dates, `spent_on` — are bare `YYYY-MM-DD`, because a due date is the same day in every
timezone. Durations are always **minutes**, as integers. Money is a decimal *string*
(`"48000.00"`), never a float.

Enum fields carry the stored value (`"high"`, `"in_progress"`), never the translated label:
a label changes with the caller's locale and is not something to branch on.

A `DELETE` answers `204` with no body.

---

## Errors

Every failure — validation, authorization, a missing record, a throttled token, an unhandled
fault — arrives in one shape:

```json
{
  "error": {
    "status": 404,
    "code": "not_found",
    "message": "No such record."
  }
}
```

`status` repeats the HTTP status so a client that kept only the body can still tell what
happened. **`code` is the stable machine value and the only field worth branching on** —
`message` is written for a person and is translated.

| `code` | Status | When |
|---|---|---|
| `unauthenticated` | 401 | No token, an unknown token, or a revoked one |
| `bad_request` | 400 | Malformed request — most often a missing `X-Planvio-Workspace` |
| `forbidden` | 403 | Your role does not permit this, or the account is deactivated |
| `not_found` | 404 | No such record *in this workspace* — including records in another one |
| `method_not_allowed` | 405 | Wrong verb for the path |
| `validation_failed` | 422 | The request body is the wrong shape; carries `fields` |
| `rule_violated` | 422 | The body is well-formed but the domain refuses it; may carry `context` |
| `ai_unavailable` | 409 | The AI layer is off, over budget, or its kill switch is engaged |
| `rate_limited` | 429 | Over the per-token budget; see `Retry-After` |
| `unavailable` | 503 | Maintenance mode, or the installation is unconfigured |
| `server_error` | 500 | A fault. The detail is in the server log, never in the response |

### Validation failures carry `fields`

```bash
curl -s -X POST https://planvio.example.com/api/v1/tasks \
  -H "Authorization: Bearer $PLANVIO_TOKEN" \
  -H "X-Planvio-Workspace: acme" \
  -H "Content-Type: application/json" \
  -d '{"project_id": 1}'
```

```json
{
  "error": {
    "status": 422,
    "code": "validation_failed",
    "message": "The title field is required.",
    "fields": {
      "title": [
        "The title field is required."
      ]
    }
  }
}
```

### Domain refusals carry `context`

A `rule_violated` is a well-formed request the model cannot represent — a duplicate project
key, a due date before the start date, a dependency that would close a loop, a board column
from another project. `context` is machine-readable detail and never contains a secret.

```bash
curl -s -X POST https://planvio.example.com/api/v1/projects \
  -H "Authorization: Bearer $PLANVIO_TOKEN" \
  -H "X-Planvio-Workspace: acme" \
  -H "Content-Type: application/json" \
  -d '{"name": "Another site", "key": "WEB"}'
```

```json
{
  "error": {
    "status": 422,
    "code": "rule_violated",
    "message": "The project key \"WEB\" is already used in this workspace.",
    "context": {
      "workspace_id": 1,
      "key": "WEB"
    }
  }
}
```

An explicit `key` that is taken is refused rather than renumbered: the key prefixes every task
number in the project, and handing back `WEB2` to somebody who asked for `WEB` would rename
several hundred tasks they had not thought about. Omit `key` and Planvio derives one and steps
around collisions itself.

### What an error body never contains

No stack traces, no SQL, no file paths, no configuration and no credentials. An unmapped fault
is a flat sentence; the real message goes to the server log.

---

## Pagination

Every list is paginated.

| Parameter | Default | Ceiling |
|---|---|---|
| `page` | 1 | — |
| `per_page` | 50 (`planvio.pagination.api`) | 200 (`planvio.pagination.api_max`) |

`per_page` above the ceiling is **silently clamped**, not refused: a caller asking for 50000
wants "as many as possible", and failing the whole request teaches them nothing. The `meta`
block always reports the size that was actually used, so a client can detect the clamp.

```bash
curl -s "https://planvio.example.com/api/v1/tasks?per_page=2&page=1" \
  -H "Authorization: Bearer $PLANVIO_TOKEN" \
  -H "X-Planvio-Workspace: acme"
```

```json
{
  "meta": { "page": 1, "per_page": 2, "total": 137, "last_page": 69 }
}
```

There are no `next`/`prev` URLs. A page number and a page size never go stale behind a proxy
that rewrites the host; a server-generated URL does.

Both ceilings are installation settings in `config/planvio.php`. Raising `api_max` on a shared
host is raising the amount of memory one request may spend.

---

## Rate limits

Requests are counted **per token**, not per user:

| Caller | Budget |
|---|---|
| A valid API token | 120 requests / minute |
| Authenticated without a token (a signed-in browser) | 120 requests / minute, per user |
| Unauthenticated | 30 requests / minute, per IP address |

Keying on the token means a runaway integration cannot spend the allowance its author needs
for the one they are debugging, and revoking a token revokes its budget with it.

Every response carries the standard headers:

```
X-RateLimit-Limit: 120
X-RateLimit-Remaining: 118
```

Over budget:

```
HTTP/1.1 429 Too Many Requests
Retry-After: 41
```

```json
{
  "error": {
    "status": 429,
    "code": "rate_limited",
    "message": "Too many requests. Slow down and try again shortly."
  }
}
```

Honour `Retry-After`. Retrying immediately extends the window rather than shortening it.

### Two endpoints cost more than the rest

Two of them carry a second, much lower budget on top of the token's own, because each call
is worth several hundred ordinary reads:

| Endpoint | Budget | Why |
|---|---|---|
| `GET /search` | 60 / minute, per user | A `LIKE '%term%'` scan across several tables that no index can serve |
| `POST /ai/runs` | 10 / minute, per user | Queues a job that will call a paid model provider and may loop for minutes |

Exceeding either answers the same 429 envelope. `POST /ai/runs` is also subject to the
provider budget — runs per user per hour and per workspace per day, from `config/ai.php` and
the workspace's AI settings — which is a separate control and answers with an AI-specific
message rather than a 429.

Polling a run with `GET /ai/runs/{uuid}` is not subject to the second budget; only starting
one is.

The API numbers live in `App\Providers\AppServiceProvider::limitTheApi()`. The two above are
shared with the product UI and live in `config/planvio.php` under `security.rate_limits`,
each with the reasoning for its value. Both can be changed by an installation that knows its
own traffic.

---

## Endpoints

Every endpoint below is authenticated. Every one but the first three also requires
`X-Planvio-Workspace`.

| Method | Path | Notes |
|---|---|---|
| `GET` | `/api/v1/me` | not scoped |
| `GET` | `/api/v1/workspaces` | not scoped |
| `GET` | `/api/v1/workspaces/{slug or id}` | not scoped |
| `GET` `POST` | `/api/v1/projects` | |
| `GET` `PATCH` `DELETE` | `/api/v1/projects/{id}` | |
| `GET` `POST` | `/api/v1/tasks` | |
| `GET` `PATCH` `DELETE` | `/api/v1/tasks/{id}` | |
| `POST` | `/api/v1/tasks/{id}/assign` | |
| `POST` | `/api/v1/tasks/{id}/status` | |
| `GET` `POST` | `/api/v1/tasks/{id}/comments` | |
| `GET` `PATCH` `DELETE` | `/api/v1/comments/{id}` | |
| `GET` `POST` | `/api/v1/milestones` | |
| `GET` `PATCH` `DELETE` | `/api/v1/milestones/{id}` | |
| `GET` `POST` | `/api/v1/time-entries` | |
| `GET` `PATCH` `DELETE` | `/api/v1/time-entries/{id}` | |
| `GET` `POST` | `/api/v1/tags` | |
| `GET` `PATCH` `DELETE` | `/api/v1/tags/{id}` | |
| `GET` | `/api/v1/users` · `/api/v1/users/{user id}` | read-only |
| `GET` | `/api/v1/activity` | read-only |
| `GET` | `/api/v1/search` | |
| `POST` | `/api/v1/ai/runs` | |
| `GET` | `/api/v1/ai/runs/{uuid}` | |

Workspaces are read-only, and so are people and activity. Creating a tenant, inviting or
removing somebody, and editing the activity feed are each decisions with consequences an API
call cannot show — and the feed is append-only by design, because the AI layer's
accountability rests on it.

---

### Identity

#### `GET /api/v1/me`

Who this token is, and which workspaces it may name in the header. The first call any client
should make.

```bash
curl -s https://planvio.example.com/api/v1/me \
  -H "Authorization: Bearer $PLANVIO_TOKEN" \
  -H "Accept: application/json"
```

```json
{
  "data": {
    "user": {
      "id": 2,
      "name": "Dana Whitfield",
      "email": "dana@acme.test",
      "job_title": "Head of Delivery",
      "avatar_url": null,
      "timezone": "Europe/London",
      "locale": "en",
      "is_active": true,
      "created_at": "2026-09-08T21:25:29+00:00"
    },
    "workspaces": [
      {
        "id": 1,
        "name": "Acme",
        "slug": "acme",
        "description": "Everything Acme is building.",
        "accent_color": "#3F66B0",
        "timezone": "UTC",
        "locale": "en",
        "currency": "GBP",
        "date_format": "Y-m-d",
        "week_starts_on": 1,
        "owner_id": 1,
        "is_suspended": false,
        "role": "owner",
        "created_at": "2026-09-08T21:25:29+00:00",
        "updated_at": "2026-09-08T21:25:29+00:00"
      }
    ],
    "token": {
      "id": 1,
      "name": "CI pipeline",
      "abilities": ["*"],
      "last_used_at": "2026-09-08T21:25:29+00:00",
      "expires_at": null,
      "created_at": "2026-09-08T21:25:29+00:00"
    }
  }
}
```

`workspaces[].role` is *your* role there, which is what decides which of the writes below will
succeed. `token` describes the credential without revealing it.

---

### Workspaces

#### `GET /api/v1/workspaces`

```bash
curl -s https://planvio.example.com/api/v1/workspaces \
  -H "Authorization: Bearer $PLANVIO_TOKEN"
```

```json
{
  "data": [
    {
      "id": 1,
      "name": "Acme",
      "slug": "acme",
      "description": "Everything Acme is building.",
      "accent_color": "#3F66B0",
      "timezone": "UTC",
      "locale": "en",
      "currency": "GBP",
      "date_format": "Y-m-d",
      "week_starts_on": 1,
      "owner_id": 1,
      "is_suspended": false,
      "role": "owner",
      "created_at": "2026-09-08T21:25:29+00:00",
      "updated_at": "2026-09-08T21:25:29+00:00"
    }
  ],
  "meta": { "total": 1 }
}
```

#### `GET /api/v1/workspaces/{slug or id}`

Same object, one record. `404` for a workspace you are not a member of.

---

### Projects

#### `GET /api/v1/projects`

| Filter | Values |
|---|---|
| `archived` | `0` (default) or `1` |
| `status_id` | integer |
| `type` | `general`, `software`, `marketing`, `operations`, `construction`, `event`, `product_launch`, `hr`, `sales`, `finance`, `research`, `creative`, `client` |
| `health` | `on_track`, `at_risk`, `off_track` |
| `priority` | `none`, `low`, `medium`, `high`, `urgent` |
| `q` | free text over name and key |
| `sort` | `name`, `key`, `created_at`, `updated_at` (default), `target_date`, `progress` |
| `direction` | `asc`, `desc` (default) |

```bash
curl -s "https://planvio.example.com/api/v1/projects?health=at_risk&sort=target_date&direction=asc" \
  -H "Authorization: Bearer $PLANVIO_TOKEN" \
  -H "X-Planvio-Workspace: acme"
```

#### `GET /api/v1/projects/{id}`

```bash
curl -s https://planvio.example.com/api/v1/projects/1 \
  -H "Authorization: Bearer $PLANVIO_TOKEN" \
  -H "X-Planvio-Workspace: acme"
```

```json
{
  "data": {
    "id": 1,
    "workspace_id": 1,
    "key": "WEB",
    "name": "Website relaunch",
    "slug": "website-relaunch",
    "description": "New marketing site and CMS migration.",
    "icon": null,
    "color": "#3F66B0",
    "type": "general",
    "status_id": null,
    "health": "on_track",
    "health_note": null,
    "priority": "medium",
    "owner_id": 4,
    "manager_id": null,
    "client_name": null,
    "department": null,
    "start_date": "2026-08-19",
    "target_date": "2026-12-27",
    "completed_at": null,
    "progress": 38,
    "is_archived": false,
    "archived_at": null,
    "created_at": "2026-09-08T21:25:29+00:00",
    "updated_at": "2026-09-08T21:25:29+00:00",
    "url": "https://planvio.example.com/w/acme/projects/website-relaunch",
    "status": null,
    "owner": {
      "id": 4,
      "name": "Norma Reilly",
      "email": "norma@acme.test",
      "job_title": "Programme Director",
      "avatar_url": null,
      "timezone": "UTC",
      "locale": "en",
      "is_active": true,
      "created_at": "2026-09-08T21:25:29+00:00"
    },
    "manager": null,
    "budget": "48000.00",
    "currency": "GBP"
  }
}
```

> **`budget` and `currency` are conditional.** They appear only for a caller who holds
> `budget.view` on that project — owners and admins outright, a manager only inside projects
> they manage. For everyone else the keys are **absent**, not null: a null would say "this
> project has no budget", and absence says "this is not yours to see".

#### `POST /api/v1/projects`

| Field | Required | Notes |
|---|---|---|
| `name` | yes | max 255 |
| `key` | no | max 12, letters then alphanumerics. Refused if taken; omit it and Planvio derives one |
| `slug` | no | derived from the name, deduplicated silently |
| `description`, `icon`, `client_name`, `department` | no | |
| `color` | no | `#RRGGBB` |
| `type`, `priority`, `health` | no | enum values as above |
| `manager_id` | no | must be a member of this workspace |
| `start_date`, `target_date` | no | `YYYY-MM-DD` |
| `budget`, `currency` | no | written only if you hold `budget.manage`; otherwise dropped |

```bash
curl -s -X POST https://planvio.example.com/api/v1/projects \
  -H "Authorization: Bearer $PLANVIO_TOKEN" \
  -H "X-Planvio-Workspace: acme" \
  -H "Content-Type: application/json" \
  -d '{"name": "Mobile app", "key": "APP", "type": "software"}'
```

Answers `201` with the project. It is created through the same action the product uses, so it
arrives with its board columns already seeded and the caller added as a project manager.

#### `PATCH /api/v1/projects/{id}`

Only the fields you send are touched. `key` and `slug` are not editable: task keys are built
from one and every URL and bookmark in the workspace from the other.

#### `DELETE /api/v1/projects/{id}`

`204`. Soft-deleted, with its tasks. Restoring is a product operation.

---

### Tasks

#### `GET /api/v1/tasks`

| Filter | Values |
|---|---|
| `project_id`, `assignee_id`, `status_id`, `milestone_id` | integer |
| `priority` | `none`, `low`, `medium`, `high`, `urgent` |
| `completed` | `0` open only, `1` completed only |
| `overdue` | `1` |
| `due_before`, `due_after` | `YYYY-MM-DD` |
| `q` | free text over the title |
| `sort` | `created_at`, `updated_at` (default), `due_date`, `priority`, `title`, `number` |
| `direction` | `asc`, `desc` (default) |

`%` and `_` in `q` are matched literally, not as wildcards.

```bash
curl -s "https://planvio.example.com/api/v1/tasks?assignee_id=3&completed=0&sort=due_date&direction=asc" \
  -H "Authorization: Bearer $PLANVIO_TOKEN" \
  -H "X-Planvio-Workspace: acme"
```

```json
{
  "data": [
    {
      "id": 1,
      "workspace_id": 1,
      "project_id": 1,
      "number": 1,
      "key": "WEB-1",
      "title": "Migrate the blog to the new CMS",
      "description": "Redirects for every old URL.",
      "status_id": 2,
      "priority": "high",
      "assignee_id": null,
      "reporter_id": 2,
      "created_by": 2,
      "parent_id": null,
      "milestone_id": null,
      "start_date": null,
      "due_date": "2026-10-15",
      "completed_at": null,
      "estimate_minutes": 480,
      "progress": 0,
      "is_completed": false,
      "is_overdue": false,
      "ai_generated": false,
      "created_at": "2026-09-08T21:25:29+00:00",
      "updated_at": "2026-09-08T21:25:29+00:00",
      "url": "https://planvio.example.com/w/acme/tasks/1",
      "status": {
        "id": 2,
        "project_id": 1,
        "name": "To Do",
        "color": "blue",
        "category": "todo",
        "position": 1,
        "is_default": true,
        "is_completed": false
      },
      "assignee": null,
      "project": {
        "id": 1,
        "key": "WEB",
        "name": "Website relaunch",
        "slug": "website-relaunch"
      }
    }
  ],
  "meta": { "page": 1, "per_page": 2, "total": 1, "last_page": 1 }
}
```

`status.category` is the field to branch on — `backlog`, `todo`, `in_progress`, `review`,
`blocked`, `done`, `cancelled`. `status.name` is the workspace's own wording and can be
anything. `is_completed` and `is_overdue` are computed for you, correctly: "completed" is a
property of the *status*, not of a date column, and "overdue" excludes finished work.

`project` is a reference, not the record — a page of fifty tasks from one project would
otherwise carry fifty copies of it.

#### `POST /api/v1/tasks`

| Field | Required | Notes |
|---|---|---|
| `project_id` | yes | must be in this workspace |
| `title` | yes | max 255 |
| `description` | no | |
| `status_id` | no | a column of *that* project; defaults to the board's default |
| `priority` | no | defaults to `medium` |
| `assignee_id` | no | must be a member of this workspace |
| `milestone_id`, `parent_id` | no | must be in the same project |
| `start_date`, `due_date` | no | `YYYY-MM-DD` |
| `estimate_minutes` | no | integer minutes |

The reporter is always the token's owner.

```bash
curl -s -X POST https://planvio.example.com/api/v1/tasks \
  -H "Authorization: Bearer $PLANVIO_TOKEN" \
  -H "X-Planvio-Workspace: acme" \
  -H "Content-Type: application/json" \
  -d '{
        "project_id": 1,
        "title": "Migrate the blog to the new CMS",
        "description": "Redirects for every old URL.",
        "priority": "high",
        "due_date": "2026-10-15",
        "estimate_minutes": 480
      }'
```

```json
{
  "data": {
    "id": 1,
    "workspace_id": 1,
    "project_id": 1,
    "number": 1,
    "key": "WEB-1",
    "title": "Migrate the blog to the new CMS",
    "description": "Redirects for every old URL.",
    "status_id": 2,
    "priority": "high",
    "assignee_id": null,
    "reporter_id": 2,
    "created_by": 2,
    "parent_id": null,
    "milestone_id": null,
    "start_date": null,
    "due_date": "2026-10-15",
    "completed_at": null,
    "estimate_minutes": 480,
    "progress": 0,
    "is_completed": false,
    "is_overdue": false,
    "ai_generated": false,
    "created_at": "2026-09-08T21:25:29+00:00",
    "updated_at": "2026-09-08T21:25:29+00:00",
    "url": "https://planvio.example.com/w/acme/tasks/1",
    "status": {
      "id": 2,
      "project_id": 1,
      "name": "To Do",
      "color": "blue",
      "category": "todo",
      "position": 1,
      "is_default": true,
      "is_completed": false
    },
    "assignee": null,
    "project": {
      "id": 1,
      "key": "WEB",
      "name": "Website relaunch",
      "slug": "website-relaunch"
    }
  }
}
```

#### `PATCH /api/v1/tasks/{id}`

Accepts `title`, `description`, `priority`, `assignee_id`, `milestone_id`, `start_date`,
`due_date`, `estimate_minutes`, `progress`.

A key sent as `null` clears the column; a key you do not send is left alone. `{"due_date":
null}` clears the date, `{}` does nothing.

`status_id` is **not** accepted here — see below. Changing `assignee_id` through the PATCH
requires `task.assign` as well as `task.update`.

#### `POST /api/v1/tasks/{id}/assign`

```bash
curl -s -X POST https://planvio.example.com/api/v1/tasks/1/assign \
  -H "Authorization: Bearer $PLANVIO_TOKEN" \
  -H "X-Planvio-Workspace: acme" \
  -H "Content-Type: application/json" \
  -d '{"assignee_id": 3}'
```

```json
{
  "data": {
    "id": 1,
    "key": "WEB-1",
    "title": "Migrate the blog to the new CMS",
    "assignee_id": 3,
    "status_id": 2,
    "priority": "urgent",
    "is_completed": false,
    "url": "https://planvio.example.com/w/acme/tasks/1",
    "assignee": {
      "id": 3,
      "name": "Sam Oyelaran",
      "email": "sam@acme.test",
      "job_title": "Engineer",
      "avatar_url": null,
      "timezone": "UTC",
      "locale": "en",
      "is_active": true,
      "created_at": "2026-09-08T21:25:29+00:00"
    },
    "project": { "id": 1, "key": "WEB", "name": "Website relaunch", "slug": "website-relaunch" }
  }
}
```

*(Abridged: the full task object is returned, exactly as `GET /tasks/{id}`.)*

Send `{"assignee_id": null}` to unassign. An empty body is `422` — "assign this task" with
nothing to assign it to is more likely a bug in the caller than an intention.

Requires `task.assign`, which the capability matrix withholds from plain members even for
their own tasks.

#### `POST /api/v1/tasks/{id}/status`

```bash
curl -s -X POST https://planvio.example.com/api/v1/tasks/1/status \
  -H "Authorization: Bearer $PLANVIO_TOKEN" \
  -H "X-Planvio-Workspace: acme" \
  -H "Content-Type: application/json" \
  -d '{"status_id": 6}'
```

```json
{
  "data": {
    "id": 1,
    "key": "WEB-1",
    "status_id": 6,
    "completed_at": "2026-09-08T21:25:29+00:00",
    "progress": 100,
    "is_completed": true,
    "status": {
      "id": 6,
      "project_id": 1,
      "name": "Completed",
      "color": "green",
      "category": "done",
      "position": 5,
      "is_default": false,
      "is_completed": true
    }
  }
}
```

*(Abridged as above.)*

Moving a task has consequences the PATCH does not own — `completed_at`, watcher
notifications, the `task.status_changed` webhook, the activity entry a person reads. One
endpoint owns all of them, so there is no second path that could forget one. A column from
another project is refused with `rule_violated`.

Status ids are per project. Read them from `GET /tasks/{id}` (`status.id`) or from any task on
the board.

#### `DELETE /api/v1/tasks/{id}`

`204`. Soft-deleted, and immediately invisible to every endpoint.

---

### Comments

Comments hang off tasks — the one model the domain comments on today.

#### `GET /api/v1/tasks/{id}/comments`

Oldest first, which is the order a thread is read in. Paginated.

#### `POST /api/v1/tasks/{id}/comments`

```bash
curl -s -X POST https://planvio.example.com/api/v1/tasks/1/comments \
  -H "Authorization: Bearer $PLANVIO_TOKEN" \
  -H "X-Planvio-Workspace: acme" \
  -H "Content-Type: application/json" \
  -d '{"body": "<p>Redirect map is in the wiki.</p>"}'
```

```json
{
  "data": {
    "id": 1,
    "workspace_id": 1,
    "subject_type": "App\\Models\\Task",
    "subject_id": 1,
    "user_id": 3,
    "author_type": "user",
    "ai_run_id": null,
    "parent_id": null,
    "body": "<p>Redirect map is in the wiki.</p>",
    "edited_at": null,
    "created_at": "2026-09-08T21:25:29+00:00",
    "updated_at": "2026-09-08T21:25:29+00:00",
    "author": {
      "id": 3,
      "name": "Sam Oyelaran",
      "email": "sam@acme.test",
      "job_title": "Engineer",
      "avatar_url": null,
      "timezone": "UTC",
      "locale": "en",
      "is_active": true,
      "created_at": "2026-09-08T21:25:29+00:00"
    }
  }
}
```

`body` is HTML and is **sanitised on the way in** by the same sanitiser the product's composer
uses — `<script>` and friends do not survive. What comes back is still somebody else's text:
escape it before rendering, and treat it as *data* rather than instructions if you feed it to
a language model.

`parent_id` makes the comment a reply; it must be a comment on the same task. `author_type` is
`user` or `ai` — an AI-authored comment carries `ai_run_id` so you can trace it to the run
that wrote it.

`PATCH /api/v1/comments/{id}` replaces the body and stamps `edited_at`; there is no silent
edit. `DELETE /api/v1/comments/{id}` returns `204`.

---

### Milestones

#### `GET /api/v1/milestones`

Filters: `project_id`, `status` (`planned`, `in_progress`, `completed`, `delayed`,
`cancelled`), `open=1`. Sorts: `due_date` (default), `position`, `name`, `created_at`,
`updated_at`. **Undated milestones always sort last**, whichever direction you ask for: "no
date" is not a very early date.

#### `POST /api/v1/milestones`

```bash
curl -s -X POST https://planvio.example.com/api/v1/milestones \
  -H "Authorization: Bearer $PLANVIO_TOKEN" \
  -H "X-Planvio-Workspace: acme" \
  -H "Content-Type: application/json" \
  -d '{"project_id": 1, "name": "Content freeze", "due_date": "2026-09-30"}'
```

```json
{
  "data": {
    "id": 1,
    "workspace_id": 1,
    "project_id": 1,
    "name": "Content freeze",
    "description": null,
    "status": "planned",
    "start_date": null,
    "due_date": "2026-09-30",
    "completed_at": null,
    "owner_id": null,
    "position": 0,
    "progress": 0,
    "created_at": "2026-09-08T21:25:29+00:00",
    "updated_at": "2026-09-08T21:25:29+00:00",
    "owner": null
  }
}
```

`completed_at` is never writable. Send `{"status": "completed"}` to
`PATCH /api/v1/milestones/{id}` and the timestamp appears; the two can never disagree.

---

### Time entries

Durations are minutes. There is no `hours` field and no decimal.

#### `GET /api/v1/time-entries`

Filters: `project_id`, `task_id`, `user_id`, `from`, `to` (`YYYY-MM-DD`), `billable`.
Sorts: `spent_on` (default), `minutes`, `created_at`, `updated_at`.

> **Whose hours you see.** Reading somebody else's time is `time.view_all` — owners and
> admins outright, a manager only inside projects they manage. Without it the list is your own
> entries, and a `user_id` filter naming a colleague returns an empty page rather than a
> refusal.
>
> The question is asked about the project you asked about. A manager filtering
> `?project_id=1` on a project they run sees the whole team's hours; the same manager asking
> workspace-wide has no project for that permission to attach to, and sees only their own. If
> you manage several projects, ask about them one at a time.

#### `POST /api/v1/time-entries`

| Field | Required | Notes |
|---|---|---|
| `project_id` | yes | |
| `task_id` | no | must be in that project |
| `minutes` | yes | 1–1440. A single entry longer than a day is a timer somebody forgot to stop |
| `spent_on` | no | `YYYY-MM-DD`, defaults to today |
| `description` | no | max 255 |
| `is_billable` | no | defaults to `true` |

Time is always logged **as the caller** — there is no `user_id` on the write.

```bash
curl -s -X POST https://planvio.example.com/api/v1/time-entries \
  -H "Authorization: Bearer $PLANVIO_TOKEN" \
  -H "X-Planvio-Workspace: acme" \
  -H "Content-Type: application/json" \
  -d '{"project_id": 1, "task_id": 1, "minutes": 95, "spent_on": "2026-09-08", "description": "Redirect map"}'
```

```json
{
  "data": {
    "id": 1,
    "workspace_id": 1,
    "project_id": 1,
    "task_id": 1,
    "user_id": 3,
    "minutes": 95,
    "description": "Redirect map",
    "spent_on": "2026-09-08",
    "started_at": null,
    "ended_at": null,
    "is_running": false,
    "is_billable": true,
    "created_at": "2026-09-08T21:25:29+00:00",
    "updated_at": "2026-09-08T21:25:29+00:00",
    "user": {
      "id": 3,
      "name": "Sam Oyelaran",
      "email": "sam@acme.test",
      "job_title": "Engineer",
      "avatar_url": null,
      "timezone": "UTC",
      "locale": "en",
      "is_active": true,
      "created_at": "2026-09-08T21:25:29+00:00"
    }
  }
}
```

`PATCH` accepts `minutes`, `spent_on`, `description`, `is_billable`, `task_id`. Moving logged
work between *projects* is not offered: it rewrites two projects' reported time.

---

### Tags

```bash
curl -s -X POST https://planvio.example.com/api/v1/tags \
  -H "Authorization: Bearer $PLANVIO_TOKEN" \
  -H "X-Planvio-Workspace: acme" \
  -H "Content-Type: application/json" \
  -d '{"name": "Migration", "color": "#3F66B0"}'
```

```json
{
  "data": {
    "id": 1,
    "workspace_id": 1,
    "name": "Migration",
    "slug": "migration",
    "color": "#3F66B0",
    "description": null,
    "created_at": "2026-09-08T21:25:29+00:00",
    "updated_at": "2026-09-08T21:25:29+00:00"
  }
}
```

**Creation is idempotent by slug**, which makes an import script safe to re-run: asking for a
tag that already exists returns the existing one. The status says which happened — `201` for a
tag that was created, `200` for one that was already there — so you never have to check first.
A second creation is not an edit: the existing colour is left alone.

`slug` is not editable; it is what saved filters resolve against.

---

### People

`GET /api/v1/users` and `GET /api/v1/users/{user id}` — read-only.

The resource is the **membership**, not the account, because "role" is a fact about the pair:
the same person is an owner in one workspace and a guest in another.

```bash
curl -s "https://planvio.example.com/api/v1/users?role=member" \
  -H "Authorization: Bearer $PLANVIO_TOKEN" \
  -H "X-Planvio-Workspace: acme"
```

```json
{
  "data": [
    {
      "id": 3,
      "workspace_id": 1,
      "user_id": 3,
      "role": "member",
      "title": null,
      "joined_at": "2026-09-08T21:25:29+00:00",
      "last_active_at": null,
      "user": {
        "id": 3,
        "name": "Sam Oyelaran",
        "email": "sam@acme.test",
        "job_title": "Engineer",
        "avatar_url": null,
        "timezone": "UTC",
        "locale": "en",
        "is_active": true,
        "created_at": "2026-09-08T21:25:29+00:00"
      }
    }
  ],
  "meta": { "page": 1, "per_page": 50, "total": 1, "last_page": 1 }
}
```

`{user id}` is the **user** id — the one that appears as `assignee_id`, `reporter_id` and
`owner_id` everywhere else. Filters: `role`, `q` (name or email). Accounts that exist on the
installation but are not in this workspace are unreachable here at all.

---

### Activity

`GET /api/v1/activity` — read-only, newest first.

Filters: `project_id`, `event`, `subject_type`, `subject_id`, `since` (ISO-8601 or a date).

```bash
curl -s "https://planvio.example.com/api/v1/activity?per_page=3" \
  -H "Authorization: Bearer $PLANVIO_TOKEN" \
  -H "X-Planvio-Workspace: acme"
```

```json
{
  "data": [
    {
      "id": 8,
      "workspace_id": 1,
      "project_id": 1,
      "subject_type": "App\\Models\\TimeEntry",
      "subject_id": 1,
      "causer_id": 3,
      "causer_type": "user",
      "ai_run_id": null,
      "event": "time_logged",
      "description": null,
      "properties": {
        "project_id": 1,
        "task_id": 1,
        "minutes": 95,
        "spent_on": "2026-09-08",
        "is_billable": true
      },
      "created_at": "2026-09-08T21:25:29+00:00",
      "causer": {
        "id": 3,
        "name": "Sam Oyelaran",
        "email": "sam@acme.test",
        "job_title": "Engineer",
        "avatar_url": null,
        "timezone": "UTC",
        "locale": "en",
        "is_active": true,
        "created_at": "2026-09-08T21:25:29+00:00"
      }
    }
  ],
  "meta": { "page": 1, "per_page": 3, "total": 9, "last_page": 3 }
}
```

`event` is the stable machine value — `created`, `updated`, `status_changed`, `assigned`,
`commented`, `time_logged` and so on. `causer_type` is `user` or `ai`; an AI-caused entry
carries `ai_run_id`. Guests see only entries from projects they belong to, and none of the
workspace-level ones.

---

### Search

`GET /api/v1/search?q=…` — the product's command palette, as an endpoint. Same service, same
visibility rules: a guest gets exactly the results a guest would get on screen.

| Parameter | Notes |
|---|---|
| `q` | required, 2–128 characters |
| `types[]` | any of `project`, `task`, `milestone`, `wiki_page`, `comment`, `user`, `team` |
| `limit` | results per group, max 100 |
| `include_archived` | `1` to include archived projects |

```bash
curl -s -G https://planvio.example.com/api/v1/search \
  --data-urlencode "q=blog" \
  -H "Authorization: Bearer $PLANVIO_TOKEN" \
  -H "X-Planvio-Workspace: acme"
```

```json
{
  "data": {
    "term": "blog",
    "total": 1,
    "groups": {
      "task": [
        {
          "type": "task",
          "id": 1,
          "title": "Migrate the blog to the new CMS",
          "subtitle": "WEB-1",
          "excerpt": "Redirects for every old URL.",
          "project_id": 1,
          "project_name": "Website relaunch",
          "meta": { "number": 1, "is_completed": true }
        }
      ]
    }
  },
  "meta": {
    "types": ["project", "task", "milestone", "wiki_page", "comment", "user", "team"]
  }
}
```

Results are grouped rather than flattened: a task and a comment that both match "invoice" are
not comparable, and one ranked list would have to pretend they are. `excerpt` is plain text —
tags stripped, entities decoded — so it is safe to render.

---

### AI runs

Two endpoints, and deliberately no third.

> **There is no endpoint that runs a tool.** Every tool call inside a run passes through the
> pipeline in `docs/ARCHITECTURE.md` §7.1 — the registry, input validation, the acting user's
> own Gate check, a workspace assertion, the risk and approval gate, and only then an Action
> inside a transaction. An endpoint that invoked a tool directly would be a way around every
> one of those. The API's whole AI surface is a sentence in and a status out.

#### `POST /api/v1/ai/runs`

Requires `ai.use`. Gated by `AiGate`: the platform switch, the workspace's settings, the kill
switch, and the per-user and per-workspace spend caps.

| Field | Required | Notes |
|---|---|---|
| `objective` | yes | 3–2000 characters, plain language |
| `project_id` | no | scopes the run to one project |
| `mode` | no | `assistant`, `copilot`, `autonomous` |

`mode` is a *request*, not a grant. It is clamped to the workspace's effective mode and then
to your permissions: asking for `autonomous` in an assistant workspace yields `assistant`, and
asking for it without `ai.autonomous` yields `copilot`. The run records the mode it will
actually execute in — read it back from the response.

```bash
curl -s -X POST https://planvio.example.com/api/v1/ai/runs \
  -H "Authorization: Bearer $PLANVIO_TOKEN" \
  -H "X-Planvio-Workspace: acme" \
  -H "Content-Type: application/json" \
  -d '{"objective": "Draft next week'"'"'s status update for the website relaunch.", "project_id": 1}'
```

```
HTTP/1.1 202 Accepted
```

```json
{
  "data": {
    "id": 1,
    "uuid": "5a91904a-de02-4411-9e45-76179a7310db",
    "workspace_id": 1,
    "project_id": 1,
    "user_id": 2,
    "trigger": "api",
    "mode": "assistant",
    "status": "queued",
    "objective": "Draft next week's status update for the website relaunch.",
    "summary": null,
    "error": null,
    "steps": 0,
    "tool_call_count": 0,
    "error_count": 0,
    "tokens_in": 0,
    "tokens_out": 0,
    "model": "gpt-4o-mini",
    "is_finished": false,
    "started_at": null,
    "finished_at": null,
    "duration_ms": null,
    "created_at": "2026-09-08T21:25:29+00:00"
  }
}
```

`202`, not `200`: Planvio runs on hosting with no persistent worker, so the run is recorded and
queued and a cron-driven worker executes it (`docs/QUEUE.md`). A synchronous endpoint would
either block for minutes or lie about being finished.

When the AI layer is unavailable — switched off, kill switch engaged, over the daily cap — the
answer is `409 ai_unavailable` with the reason in `message`. Nothing is written.

#### `GET /api/v1/ai/runs/{uuid}`

Poll until `is_finished` is true. Statuses: `queued`, `running`, `awaiting_approval`,
`succeeded`, `partial`, `failed`, `cancelled`, `limit_reached`.

```bash
curl -s https://planvio.example.com/api/v1/ai/runs/5a91904a-de02-4411-9e45-76179a7310db \
  -H "Authorization: Bearer $PLANVIO_TOKEN" \
  -H "X-Planvio-Workspace: acme"
```

```json
{
  "data": {
    "id": 1,
    "uuid": "5a91904a-de02-4411-9e45-76179a7310db",
    "workspace_id": 1,
    "project_id": 1,
    "user_id": 2,
    "trigger": "api",
    "mode": "assistant",
    "status": "queued",
    "objective": "Draft next week's status update for the website relaunch.",
    "summary": null,
    "error": null,
    "steps": 0,
    "tool_call_count": 0,
    "error_count": 0,
    "tokens_in": 0,
    "tokens_out": 0,
    "model": "gpt-4o-mini",
    "is_finished": false,
    "started_at": null,
    "finished_at": null,
    "duration_ms": null,
    "created_at": "2026-09-08T21:25:29+00:00",
    "tool_calls": []
  }
}
```

A run is addressed by **uuid**, never by the numeric `id`: run ids are sequential, and a
sequential identifier in a polling URL is an invitation to walk the neighbours.

`tool_calls` is the audit spine — one entry per tool invocation, with `sequence`, `tool`,
`risk`, `status`, `approval_required`, `summary`, `error` and `duration_ms`. It is present for
the person who started the run, and for anyone holding `ai.view_logs`. Tool *arguments* are
not part of the contract.

A run parked at `awaiting_approval` is waiting for a human decision in the product. There is no
API endpoint to approve one — an approval is the point at which a person takes responsibility,
and a script cannot do that.

Poll at a sensible interval (two to five seconds). Every poll spends rate-limit budget.

---

## Webhooks

Webhooks push events out to your systems. They are configured per workspace under
**Settings → Webhooks**, by an owner or admin (`webhooks.manage`).

Create an endpoint, choose the events, and copy the **signing secret** — it is shown once, on
the screen that mints it, and no screen reveals it again. Losing it means rotating it, which
is one click and invalidates the old one immediately.

**Send test delivery** posts a real, signed `planvio.test` delivery to your endpoint while you
watch, and shows the status and the response body in the delivery log underneath. Use it
before you write any handling code.

### Events

| Group | Events |
|---|---|
| Task | `task.created`, `task.updated`, `task.status_changed`, `task.assigned`, `task.completed` |
| Project | `project.created`, `project.updated` |
| Milestone | `milestone.completed` |
| Comment | `comment.created` |
| Member | `member.invited`, `member.joined` |
| Test | `planvio.test` — sent only by the test button, never subscribable |

Subscribing to "everything Planvio emits" includes events added in future releases.

### Delivery

```
POST /your/endpoint HTTP/1.1
Content-Type: application/json
Accept: application/json
User-Agent: Planvio/1.0.0
X-Planvio-Event: task.status_changed
X-Planvio-Delivery: 0f2b3d0f-1c2a-4c9e-9b2f-2a41e6c9f5b1
X-Planvio-Timestamp: 1789234567
X-Planvio-Signature: t=1789234567,v1=6f3a…c21
X-Planvio-Attempt: 1
```

```json
{
  "event": "task.status_changed",
  "occurred_at": "2026-09-08T21:25:29+00:00",
  "workspace_id": 1,
  "actor": { "id": 2, "name": "Dana Whitfield", "email": "dana@acme.test" },
  "data": {
    "task": {
      "id": 1,
      "key": "WEB-1",
      "number": 1,
      "title": "Migrate the blog to the new CMS",
      "project_id": 1,
      "project_key": "WEB",
      "status_id": 6,
      "status": "Completed",
      "priority": "urgent",
      "assignee_id": 3,
      "reporter_id": 2,
      "milestone_id": null,
      "parent_id": null,
      "start_date": null,
      "due_date": "2026-10-15",
      "completed_at": "2026-09-08T21:25:29+00:00",
      "progress": 100,
      "ai_generated": false,
      "url": "https://planvio.example.com/w/acme/tasks/1"
    },
    "from": { "id": 2, "name": "To Do", "category": "todo" },
    "to": { "id": 6, "name": "Completed", "category": "done" },
    "completed": true
  }
}
```

The payload never carries a credential. `member.invited` sends the address and the role but
not the invitation token, and nothing in a payload is read from a column that is encrypted at
rest.

**Retries.** Any response outside `2xx`, and any connection failure, is retried — four attempts
by default, backing off 60s, 5m, 15m. Respond `2xx` quickly and do your work afterwards; a
receiver that blocks for thirty seconds will be retried while it is still thinking. Deliveries
are recorded either way, with the status and the (truncated, credential-scrubbed) response
body, and you can read them in the delivery log.

**Deduplicate on `X-Planvio-Delivery`.** It is a uuid, unique per delivery, and stable across
retries of that delivery.

An endpoint that fails 15 consecutive deliveries is switched off. That is visible and
reversible on the settings screen; silently dropping deliveries would be neither.

### Verifying the signature

```
X-Planvio-Signature: t=<unix timestamp>,v1=<hex hmac>
```

`v1` is `HMAC-SHA256` over the string `"<timestamp>.<raw request body>"`, keyed with your
endpoint's signing secret.

The timestamp is *inside* the MAC, and that is the whole point. Signing the body alone would
let anyone who captured a request replay it forever — the signature would still verify, and
you would have nothing to check freshness against. Binding the timestamp in means it cannot be
moved forward without breaking the signature, so rejecting anything older than your tolerance
is genuinely replay-proof.

To verify:

1. Read `X-Planvio-Signature` and split it into `t` and `v1`.
2. Reject if `t` is more than **300 seconds** from your own clock
   (`planvio.webhooks.tolerance_seconds`).
3. Compute `HMAC-SHA256(secret, t + "." + raw_body)` over the **raw** body bytes.
4. Compare with `v1` in **constant time**.
5. Only then parse the JSON.

Signing and verifying the *raw* body matters: JSON encoding is not canonical, so re-encoding a
parsed body produces a different string and a signature that fails for no visible reason. Read
the bytes before any middleware parses them.

#### PHP

```php
<?php

declare(strict_types=1);

/**
 * @param string $payload the raw request body, before any JSON parsing
 * @param string $header  the X-Planvio-Signature header
 * @param string $secret  the endpoint's signing secret (whsec_…)
 */
function planvio_verify(string $payload, string $header, string $secret, int $tolerance = 300): bool
{
    $parts = [];

    foreach (explode(',', $header) as $piece) {
        $pair = explode('=', trim($piece), 2);

        if (count($pair) === 2) {
            $parts[$pair[0]] = $pair[1];
        }
    }

    if (! isset($parts['t'], $parts['v1']) || ! ctype_digit($parts['t'])) {
        return false;
    }

    // Freshness first: a valid signature on an hour-old request is a replay.
    if (abs(time() - (int) $parts['t']) > $tolerance) {
        return false;
    }

    $expected = hash_hmac('sha256', $parts['t'].'.'.$payload, $secret);

    // Constant time: a normal string comparison leaks the correct prefix through timing.
    return hash_equals($expected, $parts['v1']);
}

// --- Worked example -------------------------------------------------------

$secret = 'whsec_example_signing_key';
$payload = '{"event":"task.created","workspace_id":1}';
$timestamp = 1789234567;

$signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
$header = 't='.$timestamp.',v1='.$signature;

// With a tolerance wide enough to ignore the fixed timestamp above, this is true.
var_dump(planvio_verify($payload, $header, $secret, PHP_INT_MAX));   // bool(true)
var_dump(planvio_verify($payload, $header, 'wrong-secret', PHP_INT_MAX));  // bool(false)
var_dump(planvio_verify($payload.' ', $header, $secret, PHP_INT_MAX));     // bool(false)
```

In a Laravel receiver, read the raw body with `$request->getContent()` — never
`json_encode($request->all())`.

#### Node

```js
'use strict';

const crypto = require('node:crypto');

/**
 * @param {Buffer|string} payload raw request body, before JSON.parse
 * @param {string} header         the X-Planvio-Signature header
 * @param {string} secret         the endpoint's signing secret (whsec_…)
 */
function planvioVerify(payload, header, secret, toleranceSeconds = 300) {
  const parts = Object.fromEntries(
    String(header)
      .split(',')
      .map((piece) => piece.trim().split('='))
      .filter((pair) => pair.length === 2),
  );

  if (!parts.t || !parts.v1 || !/^\d+$/.test(parts.t)) return false;

  // Freshness first: a valid signature on an hour-old request is a replay.
  const skew = Math.abs(Math.floor(Date.now() / 1000) - Number(parts.t));
  if (skew > toleranceSeconds) return false;

  const body = Buffer.isBuffer(payload) ? payload : Buffer.from(payload, 'utf8');

  const expected = crypto
    .createHmac('sha256', secret)
    .update(Buffer.concat([Buffer.from(`${parts.t}.`, 'utf8'), body]))
    .digest();

  let given;
  try {
    given = Buffer.from(parts.v1, 'hex');
  } catch {
    return false;
  }

  // timingSafeEqual throws on a length mismatch, so check that first.
  return given.length === expected.length && crypto.timingSafeEqual(given, expected);
}

// --- Worked example -------------------------------------------------------

const secret = 'whsec_example_signing_key';
const payload = '{"event":"task.created","workspace_id":1}';
const timestamp = 1789234567;

const signature = crypto
  .createHmac('sha256', secret)
  .update(`${timestamp}.${payload}`)
  .digest('hex');

const header = `t=${timestamp},v1=${signature}`;

console.log(planvioVerify(payload, header, secret, Number.MAX_SAFE_INTEGER));        // true
console.log(planvioVerify(payload, header, 'wrong-secret', Number.MAX_SAFE_INTEGER)); // false
console.log(planvioVerify(`${payload} `, header, secret, Number.MAX_SAFE_INTEGER));   // false
```

With Express, keep the raw bytes:

```js
app.post(
  '/hooks/planvio',
  express.raw({ type: 'application/json' }),
  (req, res) => {
    if (!planvioVerify(req.body, req.get('X-Planvio-Signature') || '', process.env.PLANVIO_SECRET)) {
      return res.status(400).send('bad signature');
    }

    const event = JSON.parse(req.body.toString('utf8'));

    // Acknowledge first, work afterwards: Planvio retries a slow endpoint.
    res.status(204).end();
    queue.push(event);
  },
);
```

Both examples produce the same hex digest for the same input, because both hash the same
bytes. If your implementation disagrees with Planvio, the cause is almost always that the body
was parsed and re-encoded before it was signed.

---

## Versioning

The version is in the path: `/api/v1`. That is a routing decision and nothing else.

**What v1 promises.** Every field in every response is written out by hand in
`app/Http/Resources` — no resource serialises a model. So a migration that adds, renames or
drops a column cannot change a v1 response: what v1 promises is what those classes say, not
what the schema happens to hold.

**What is a breaking change.** Removing a field, renaming one, or changing its type. Adding a
field, adding an endpoint, adding an enum case, or adding an optional filter is not — write
clients that ignore keys they do not recognise.

**How v2 would coexist.** A `v2` group declared alongside `v1` in `routes/api.php`, with its
own controller namespace (`App\Http\Controllers\Api\V2\…`) and its own resource classes. `v1`
keeps pointing at the classes it points at today and keeps answering exactly as it does today.
Nothing is shared between the two but the domain underneath — the Actions, the policies and
the models — which is the layer that is allowed to change, because it is the layer no client
sees. Both versions would be served for as long as the release notes say, and neither would
have to know about the other.

---

## See also

- `docs/ARCHITECTURE.md` — the normative contract: tenancy, the capability matrix, the AI pipeline
- `docs/AI_SECURITY.md` — what the AI layer may and may not do, and why
- `docs/QUEUE.md` — how queued work is drained on hosting with no persistent worker
- `docs/SECURITY.md` — reporting a vulnerability

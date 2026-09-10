# Planvio wiki

This wiki is the **living** half of Planvio's documentation. The other half ships with the
code, in [`docs/`](https://github.com/hatemsweileh/planvio/tree/main/docs), and the split
between them is deliberate:

| | |
|---|---|
| **`docs/` in the repository** | Versioned with the code. If it says Planvio does something, that is true of *that release*, and a pull request that changes behaviour changes the doc in the same commit. |
| **This wiki** | Not versioned. Things learned after a release: a host with an unusual PHP path, a symptom nobody predicted, an answer that came out of a discussion. Anyone can edit it. |

The rule of thumb: **if it would have to change when the code changes, it belongs in
`docs/`.** Everything here should still be true a year from now, or be obviously dated.

---

## Start here

New to Planvio and want it running?

1. **[docs/INSTALLATION.md](https://github.com/hatemsweileh/planvio/blob/main/docs/INSTALLATION.md)** — installing it anywhere
2. **[docs/CPANEL.md](https://github.com/hatemsweileh/planvio/blob/main/docs/CPANEL.md)** — the no-SSH shared hosting walkthrough, which is the path most people take
3. **[docs/CRON.md](https://github.com/hatemsweileh/planvio/blob/main/docs/CRON.md)** — the two cron entries, and what stops working without them

Evaluating rather than installing? Read
**[docs/LIMITATIONS.md](https://github.com/hatemsweileh/planvio/blob/main/docs/LIMITATIONS.md)**
first. It says plainly what Planvio does not do and why, which is a better use of an
afternoon than finding out after you have moved your team onto it.

---

## In this wiki

- **[[FAQ]]** — the questions that come up more than once
- **[[Troubleshooting]]** — symptom, cause, fix
- **[[Hosting Providers]]** — what people have found on specific hosts

---

## Where to ask

| | |
|---|---|
| A question | [Discussions → Q&A](https://github.com/hatemsweileh/planvio/discussions) |
| Something is broken | [Open an issue](https://github.com/hatemsweileh/planvio/issues/new?template=bug_report.yml) |
| A rough idea | [Discussions → Ideas](https://github.com/hatemsweileh/planvio/discussions) |
| A concrete proposal | [Feature request](https://github.com/hatemsweileh/planvio/issues/new?template=feature_request.yml) |
| A security problem | **Privately** — [Security tab](https://github.com/hatemsweileh/planvio/security), never a public issue |

---

## What Planvio needs to run

Worth having in one place, because most installation problems are one of these:

| | |
|---|---|
| PHP | 8.3 or 8.4 |
| Extensions | `bcmath ctype curl fileinfo gd json mbstring openssl pdo pdo_mysql tokenizer xml` |
| Database | MySQL 5.7+ or MariaDB 10.6+ |
| Web server | Apache or LiteSpeed; Nginx works, see DEPLOYMENT.md |
| Cron | **Two** entries — the scheduler and the queue worker |
| Disk | ~350 MB plus your attachments |
| Document root | Must point at `planvio/public`, not `planvio` |

And what it deliberately does **not** need: Docker, Kubernetes, Redis, Elasticsearch,
Supervisor, systemd, Node in production, Composer in production, root, or SSH.

---

*Planvio is free software under the [AGPL-3.0](https://github.com/hatemsweileh/planvio/blob/main/LICENSE).
Copyright © 2026 Hatem Sweileh.*

# FAQ

Questions that come up more than once. Anything version-specific belongs in
[`docs/`](https://github.com/hatemsweileh/planvio/tree/main/docs) instead.

---

## Licensing and use

**Can I use Planvio commercially?**

Yes. The AGPL puts no restriction on commercial use, in-house or otherwise. Run it for your
company, your clients' projects, whatever you like.

**Can I offer Planvio to my customers as a hosted service?**

Yes — and this is the clause worth understanding before you build a business on it. The
AGPL's distinguishing feature is that making modified software available *over a network*
counts as distribution. Run Planvio unmodified as a service and you owe nothing beyond the
licence notice. Modify it and offer that modified version to others over a network, and you
must offer them the source of your modified version too.

Running it unmodified for your own team triggers none of this.

**Can I remove the Planvio branding?**

The application has branding settings — name, logo, favicon, accent colour — that let an
installation present itself however you like without touching a file. That is supported and
intended.

What the licence does not cover is the *name and the mark* themselves, which are
trademarks. Fork it freely; give your fork its own identity so nobody is misled about who
published what.

**Do I need a licence key?**

No. There is nothing to activate, and Planvio never contacts a server to check anything —
not on install, not on start, not ever. If you see it making an outbound request that you
did not configure, that is a bug worth reporting.

---

## Running it

**Does it really work on shared hosting?**

That is what it was built for. No daemon, no Redis, no Docker, no root and no SSH — the
installer runs in a browser and the two background jobs are cron entries. The release
archive ships `vendor/` and compiled assets precisely so that a host without Composer or
Node can run it.

**Why two cron jobs instead of one?**

They fail differently and it is useful to see which one has stopped. The scheduler handles
time-based work — recurring tasks, reminders, pruning. The queue worker drains jobs that
requests enqueued, mostly notifications. A scheduler that has stopped and a queue that has
stopped produce different symptoms, and one cron would hide that.

**Can I use SQLite instead of MySQL?**

The test suite runs on SQLite and the application works on it, so for a small local
instance it is fine. MySQL or MariaDB is what production is tested and supported on, and
what the installer expects. SQLite's single-writer locking is the thing that will bite you
first if more than a couple of people use it at once.

**How do I move an installation to another host?**

Copy the files, copy the database, and keep `.env` — specifically `APP_KEY`. Encrypted
values, including AI provider keys and two-factor secrets, cannot be read back without it.
Then recreate `public/storage` and update `APP_URL` and the cron paths for the new host.

**How do I upgrade?**

[docs/UPGRADING.md](https://github.com/hatemsweileh/planvio/blob/main/docs/UPGRADING.md).
The short version: back up first, replace the files but keep `.env` and `storage/`, then
open the site — Planvio runs pending migrations through the browser, so there is still no
command line involved.

---

## The AI layer

**Do I have to use the AI at all?**

No. It ships switched off and an installation that never configures a provider is a
perfectly ordinary project-management tool. Nothing else changes.

**Which models does it work with?**

OpenAI, Anthropic, anything speaking the OpenAI-compatible API — Azure, OpenRouter, Groq,
Together, Mistral — and local runtimes including Ollama, LM Studio and vLLM. A local model
means no data leaves your server at all.

**Is my project data sent to the model provider?**

Only what a request needs, and only to the provider you configured. There is no telemetry
and no Planvio-operated service in the path. If you would rather nothing left the building,
point it at a local runtime.

**Can the AI do something I could not do myself?**

No, and this is the design rather than a promise. Every tool call runs through the same
`Gate`, policy and workspace scope a person goes through, acting as a specific human user.
There is no service account and no elevated mode. A permission denial is reported back, not
routed around.

**What stops it acting on instructions hidden inside a task description?**

Workspace content reaches the model wrapped as untrusted data, so instruction-shaped text
inside your own records is treated as data rather than as a command. Beyond that, the tool
registry is fixed — there is no raw SQL, no shell, no filesystem access — and destructive
tools always require a human approval.
[docs/AI_SECURITY.md](https://github.com/hatemsweileh/planvio/blob/main/docs/AI_SECURITY.md)
is the full account.

**Something went wrong and I need it to stop now.**

The kill switch halts autonomous execution immediately across the installation, without
disabling the rest of Planvio.

---

## Languages

**Which languages ship?**

English and Arabic, with full right-to-left layout, Arabic typography and a vendored Arabic
typeface — no webfont is fetched from anywhere.

**Can I add another language?**

Yes, from inside the application: Admin → Platform → Languages. You can translate in-app
with placeholder validation, export and import JSON for an outside translator, or have a
configured AI model draft a first pass for a human to review. Nothing requires a file to be
edited or a release to be rebuilt.

**Some of the interface is still English after translating.**

Three categories are left as data on purpose, and
[docs/LOCALISATION.md §7](https://github.com/hatemsweileh/planvio/blob/main/docs/LOCALISATION.md)
says which: system project templates, the default statuses and tags a workspace was created
with, and activity or notification rows that were rendered once at the time of the event.
They are editable rows, not missing translations.

---

## Contributing

**I found a security problem.**

Do not open a public issue. Report it through the
[Security tab](https://github.com/hatemsweileh/planvio/security) → *Report a vulnerability*.
See [SECURITY.md](https://github.com/hatemsweileh/planvio/blob/main/SECURITY.md).

**I want to contribute code.**

[CONTRIBUTING.md](https://github.com/hatemsweileh/planvio/blob/main/CONTRIBUTING.md) has
the six non-negotiable rules and the conventions. The one to read before writing anything
is [docs/ARCHITECTURE.md](https://github.com/hatemsweileh/planvio/blob/main/docs/ARCHITECTURE.md) —
it is normative, not descriptive.

**Will you accept a feature that needs a background daemon?**

Almost certainly not as such, because the premise is shared hosting. But the underlying
need usually has a cron-shaped version, and that conversation is worth having — open a
discussion rather than assuming the answer.

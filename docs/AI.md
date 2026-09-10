# Planvio AI

Planvio's AI is optional, off by default, and can be switched off again at any moment
without affecting anything else in the application.

When you do turn it on, it is not a chat box in a sidebar. It is an operating layer that
can read your workspace, reason about it, and change real records — using exactly the
permissions of the person it is acting for, and leaving an audit record of everything it
did.

This document covers setting it up and using it. For how it is contained, read
[AI_SECURITY.md](AI_SECURITY.md). For running it unattended, read
[AI_AUTONOMOUS_MODE.md](AI_AUTONOMOUS_MODE.md).

---

## Contents

- [The three modes](#the-three-modes)
- [Setting up a provider](#setting-up-a-provider)
- [Choosing a model](#choosing-a-model)
- [Turning AI on for a workspace](#turning-ai-on-for-a-workspace)
- [Where you meet the AI](#where-you-meet-the-ai)
- [What it can actually do](#what-it-can-actually-do)
- [Approvals](#approvals)
- [Automations](#automations)
- [Memory](#memory)
- [Usage and cost](#usage-and-cost)
- [The kill switch](#the-kill-switch)
- [Troubleshooting](#troubleshooting)
- [Limitations](#limitations)

---

## The three modes

Mode is set per workspace, and can be overridden per project. It decides how much the AI
is allowed to do without asking.

| Mode | Reads your data | Proposes changes | Makes changes |
|---|---|---|---|
| **Assistant** | Yes | Yes, as suggestions | No — never |
| **Copilot** | Yes | Yes, as a previewed action | Only after you approve each one |
| **Autonomous** | Yes | Yes | Yes, within the limits you configure |

**Assistant** is the safe default. It answers questions, explains project status, drafts
text and recommends actions, but it holds no write tools at all. There is no configuration
mistake that lets Assistant mode change a record.

**Copilot** is where most teams settle. The AI does the thinking and the assembly work,
then shows you exactly what it intends to do:

```
I found 7 overdue tasks in Marketing Campaign.

I will:
  • Create a follow-up task "Recover campaign timeline"
  • Reassign 4 tasks from Daniel (on leave) to the Marketing team
  • Add the "Urgent" tag to all 7
  • Notify Sarah, the project manager

                                            [ Approve ]  [ Cancel ]
```

Nothing happens until you press Approve.

**Autonomous** lets the AI carry out multi-step work on its own — but only tools you have
allowed, only up to the risk level you set, only within its execution limits, and never
outside the permissions of the user it is acting for. Destructive actions still require
approval unless you explicitly allow them, and some never can be.

---

## Setting up a provider

Go to **Admin → AI → Providers → Add provider**.

Planvio ships four drivers:

| Driver | Use it for |
|---|---|
| **OpenAI** | api.openai.com |
| **Anthropic** | api.anthropic.com |
| **OpenAI-compatible** | Anything exposing `/chat/completions` — Azure OpenAI, OpenRouter, Groq, Together, Mistral, DeepSeek, and local runtimes such as Ollama, LM Studio or vLLM |
| **Custom HTTP** | A bespoke endpoint. No tool-calling; assistant-style responses only |

### Fields

| Field | Notes |
|---|---|
| Name | Your label for it, e.g. "Production — Anthropic" |
| Driver | One of the above |
| Base URL | Required for OpenAI-compatible and Custom HTTP. Leave blank for OpenAI and Anthropic to use their defaults |
| API key | Encrypted before it is stored. Never shown again, never sent to the browser, never written to a log |
| Model | See below |
| Fallback model | Optional. Used if the primary model returns a model-not-found error |
| Temperature | Optional. Lower is more predictable; 0.2–0.4 suits operational work |
| Max tokens | Response ceiling |
| Timeout | Seconds. 60 is a sensible default; raise it on slow shared hosting |

Press **Test connection**. Planvio makes one minimal request and reports exactly what came
back, including the provider's own error text if it failed.

### Self-hosted and local models

To keep everything inside your own network, point the OpenAI-compatible driver at a local
runtime:

```
Driver     OpenAI-compatible
Base URL   http://127.0.0.1:11434/v1        (Ollama)
API key    ollama                            (any non-empty value)
Model      qwen2.5:14b-instruct
```

This works, but read [Choosing a model](#choosing-a-model) first — Planvio leans heavily on
reliable tool calling, and small models are frequently not good at it.

---

## Choosing a model

Planvio's AI works by calling tools. A model that cannot reliably emit well-formed tool
calls will feel broken no matter how good its prose is.

| Use | Recommendation |
|---|---|
| Copilot and Autonomous | A current frontier model with strong tool use — for example Claude Sonnet 4.5, GPT-4.1, or equivalent |
| Assistant only | A mid-tier model is fine; there are no write tools to get wrong |
| Local / self-hosted | Use the largest instruct model your hardware allows, and keep it in Assistant mode until you have watched it handle tool calls correctly |

Planvio does not require any particular vendor and stores nothing vendor-specific in your
data. Switching providers is a settings change.

---

## Turning AI on for a workspace

**Admin → AI → Settings** controls the global defaults. **Workspace settings → AI**
controls a single workspace.

| Setting | What it does |
|---|---|
| Enabled | Master switch for this workspace |
| Provider | Which configured provider to use |
| Default mode | Assistant, Copilot or Autonomous |
| Autonomous enabled | A separate switch. Autonomous cannot be selected unless this is on |
| System instructions | Extra standing guidance — house style, naming conventions, what to prioritise |
| Communication style | Concise, standard or detailed |
| Language | The language the AI replies in |
| Max tool calls per run | Ceiling on a single agent run. Default 25 |
| Max run seconds | Wall-clock ceiling. Default 180 |
| Max runs per day | Spend guard |
| Notify on action | Whether AI changes generate notifications |

There is a strict hierarchy: **global config → workspace settings → project policy**. A
workspace can only ever *narrow* what the global configuration permits, and a project can
only narrow the workspace. Nothing lower in the chain can widen what is above it.

---

## Where you meet the AI

| Surface | Scope |
|---|---|
| **Global AI button** in the top bar | The whole workspace |
| **Command palette** (`Ctrl`/`Cmd` + `K`) | Type a question instead of a command |
| **Project → AI tab** | That project, with its tasks, milestones and members in context |
| **Ask AI about this task** on a task | That task and its immediate relations |
| **AI workspace** (full page) | Long conversations, plans and reports |

Conversations are private to you and scoped to one workspace. They never cross workspaces,
and the AI cannot see a project you cannot see.

---

## What it can actually do

The AI has no general database access. It can only call the tools it has been given, each
of which validates its input, checks your permissions, asserts workspace scope, and runs
the same application code a person's click would run.

### Reading

`search_projects` `search_tasks` `get_project` `get_task` `get_workspace_overview`
`get_project_health` `get_team_workload` `get_time_report` `get_budget_summary`
`list_project_members` `search_wiki` `get_activity`

### Writing — low risk

`create_comment` `create_checklist` `create_saved_view` `create_document`
`update_document` `generate_project_report` `send_notification` `create_memory`

### Writing — medium risk

`create_task` `update_task` `assign_task` `change_task_status` `create_subtask`
`create_milestone` `update_milestone` `create_dependency` `add_tag` `remove_tag`
`create_project` `update_project` `bulk_update_tasks`

### Writing — high risk

`archive_project` `update_project_settings` `manage_project_member`

### Destructive

`delete_task` `delete_project` `remove_workspace_member`

You control which of these are available, per workspace and per project, in
**AI → Policies**.

### Things it will ask you

Give it a real request and it will do the work:

> "Create a task to prepare the campaign landing page, assign it to Sarah, make it high
> priority, and set the due date for Friday."

> "Move all overdue marketing tasks to the Marketing team and tell Sarah."

> "What is putting the Website Redesign project at risk?"

> "Break this task into subtasks."

> "Create a product launch project for September with milestones and a launch checklist."

> "Who has the largest workload this week?"

> "Summarise this week's progress as an executive update."

> "Here are my meeting notes — pull out the action items and create tasks."

Relative dates are resolved in your **workspace timezone**, and the AI states the date it
settled on: *"due Friday 12 September"*. When a date is genuinely ambiguous and getting it
wrong would matter, it asks.

---

## Approvals

Any action that needs your sign-off appears in **AI → Approvals**, and — if you are the
person who asked — inline in the conversation.

An approval card states the tool, the exact arguments, the records affected and the
consequences:

```
Planvio AI wants to:

  ARCHIVE PROJECT
  Website Redesign

  This will also archive:
    47 tasks
    6 milestones
    123 activity records

  Requested by   Hatem (via AI)
  Risk           High

                                          [ Approve ]  [ Reject ]
```

Approvals expire after 24 hours by default. A rejected action is recorded with your reason
and is never retried automatically.

Four actions **always** require approval and cannot be waived by any policy:
`delete_project`, `delete_task`, `remove_workspace_member`, `archive_project`.

---

## Automations

**AI → Automations** lets you give the AI a standing objective, either on a schedule or in
response to an event.

| Field | Meaning |
|---|---|
| Trigger | Schedule (cron expression) or Event |
| Objective | What you want, in plain language |
| Mode | Usually Copilot; Autonomous if you have enabled it |
| Project | Optional — scope the automation to one project |

Useful ones:

```
Every weekday at 08:00
  "Review active projects and flag anything that needs attention today."

Every Friday at 16:00
  "Generate a status summary for each active project and post it as a project comment."

When a milestone becomes at risk
  "Explain why, and notify the project manager."

When a project has more than five overdue tasks
  "Analyse the cause and propose corrective actions."
```

Automations depend on cron being configured — see [CRON.md](CRON.md). Each takes a database
lock before running, so an overlapping cron tick is a no-op rather than a duplicate run.
An automation that fails repeatedly backs off, and disables itself after ten consecutive
failures rather than burning your provider budget silently.

---

## Memory

The AI keeps a small, bounded memory so it does not have to relearn your workspace every
conversation: workspace and project context, your stated preferences, plans it has made,
and what it did previously.

Memory is scoped the same way everything else is — to a workspace, and where relevant to a
project or a user. It never crosses a workspace boundary.

**AI → Memory** lists everything stored, and you can delete any entry. Set a retention
period in workspace AI settings to have entries expire automatically.

---

## Usage and cost

**Admin → AI → Usage** shows what has actually been consumed:

- Runs, tool calls and errors, by day
- Token counts in and out, where the provider reports them
- Breakdown by workspace, user, model and provider
- Failed runs with their reasons

Planvio reports what the provider tells it. **It does not display a currency cost**, because
providers do not return per-request pricing and any figure Planvio invented would be a
guess presented as a fact. Use the token counts against your provider's published rates.

---

## The kill switch

**Admin → AI → Kill switch**, or the same control in workspace AI settings.

Engaging it immediately:

- refuses every new AI run,
- aborts in-flight runs at their next step,
- shows a banner to everyone in the workspace.

Everything else in Planvio keeps working exactly as before.

There is also a narrower control: turning off **Autonomous enabled** downgrades the
workspace to Copilot, so people keep their assistant while unattended execution stops.

---

## Troubleshooting

**Test connection times out**
Outbound HTTPS is blocked, which is common on shared hosting. Ask your host to allow
outbound port 443 to your provider's domain.

**"AI is not configured for this workspace"**
Either `AI_ENABLED` is false in `.env`, the workspace has AI disabled, no provider is
selected, or the kill switch is engaged. **Admin → AI → Settings** shows which.

**The AI answers questions but will not change anything**
You are in Assistant mode. That is what Assistant mode means.

**It says it does not have permission**
It is correct. The AI holds your permissions, not more. Check your workspace role and, for
project-scoped work, your project role. This is the security model doing its job.

**Runs stop partway with "limit reached"**
The run hit `max tool calls` or `max run seconds`. The AI reports how far it got and what
remains. Raise the limits in workspace AI settings, or split the objective.

**Responses are slow**
AI calls are queued and processed by the queue worker cron. If it runs every five minutes,
a request can wait that long. For a more responsive experience, run the queue cron every
minute — see [QUEUE.md](QUEUE.md).

**It made a mistake**
Every AI action is in the activity log, attributed to the AI and to the user whose
authority it used, with the tool and arguments. Tasks and projects are soft-deleted and can
be restored. Consider moving that workspace back to Copilot mode.

---

## Limitations

Stated plainly, so you are not surprised:

- **No streaming responses.** Runs are queued and the UI polls for the result. This is a
  deliberate consequence of supporting shared hosting with no persistent worker.
- **No vector search or RAG.** Context retrieval is structured and keyword-based against
  MySQL. It is bounded and predictable, but it will not find a task by conceptual
  similarity the way an embedding index would. The context layer is built so a vector
  backend can be added later without changing the tools.
- **No image, audio or file understanding.** The AI reads text and structured records. It
  does not open your attachments.
- **Token counts depend on the provider.** Some OpenAI-compatible endpoints do not return
  usage data; those runs show as zero tokens rather than an estimate.
- **Custom HTTP driver has no tool calling.** It can answer, not act.
- **One provider active at a time per workspace.** Fallback is limited to the fallback
  model on the same provider.

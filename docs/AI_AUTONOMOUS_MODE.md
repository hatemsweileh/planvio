# Autonomous mode

Autonomous mode lets Planvio's AI carry out multi-step work without asking permission for
every step. It is the most capable thing Planvio does and the one most worth being
deliberate about.

This document is written for the administrator who has to decide whether to turn it on.

Read [AI_SECURITY.md](AI_SECURITY.md) first if you have not. This document assumes you
understand that the AI acts with a specific user's permissions and cannot exceed them.

---

## What it actually means

In **Copilot** mode the AI assembles an action and waits. In **Autonomous** mode it
executes — within a fence you define.

It does *not* mean:

- the AI can do anything it likes,
- the AI gains permissions,
- destructive actions happen silently,
- you lose visibility.

It means: for tools you allowed, at or below the risk level you set, inside the execution
limits you configured, acting as a user whose permissions already cover it, the AI proceeds
without stopping to ask.

Everything else still stops and asks.

---

## Before you turn it on

Work through this honestly. Autonomous mode is not a setting to flip on the first day.

- [ ] The workspace has run in **Assistant** mode long enough that you have seen how the AI
      reads your data — a week of real use, not an afternoon.
- [ ] It has then run in **Copilot** mode long enough that you have approved and rejected
      real actions, and you have a feel for its judgment.
- [ ] You have read the tool-run log and the actions look like what you would have done.
- [ ] You have decided which tools it may use, rather than accepting the default set.
- [ ] You know which user it will act as, and you are comfortable with that user's
      permissions being exercised without a human in the loop.
- [ ] You have a database backup you could restore from.
- [ ] You know where the kill switch is.

If any of those is a "not yet", stay in Copilot. Copilot mode gets you most of the value
with none of the exposure.

---

## Turning it on

Two switches, deliberately separate.

**1. Enable the capability.**
Workspace settings → AI → **Autonomous enabled**. Requires `ai.manage`. Until this is on,
Autonomous cannot be selected anywhere.

**2. Select the mode.**
Set **Default mode** to Autonomous for the workspace, or set it on a single project's AI
policy. Scoping it to one project first is the sensible move.

The separation exists so you can revoke autonomy in one click without reconfiguring
anything: turn off *Autonomous enabled* and every project falls back to Copilot
immediately.

---

## Defining the fence

**AI → Policies.** A policy applies to a workspace, or to a single project, and the most
specific active policy wins. A policy can only narrow what the level above allows.

### Risk ceiling

`max_risk` is the highest risk level the AI may execute unattended. Everything above it
requires approval.

| Risk | Contains | Recommended ceiling |
|---|---|---|
| `read` | All read tools | — |
| `low` | Comments, checklists, documents, reports, notifications | A cautious start |
| `medium` | Create and update tasks, assign, change status, milestones, dependencies, tags, bulk updates | **The sensible default** |
| `high` | Archive project, project settings, project membership | Only with a specific reason |
| `destructive` | Delete task, delete project, remove member | Never — and Planvio will not let you |

`medium` is the default and is where most of the useful work lives: the AI can run a
project's day-to-day without being able to restructure or dismantle it.

### Tool allow and deny lists

- `denied_tools` always wins.
- If `allowed_tools` is set, anything not in it is denied.
- If `allowed_tools` is empty, everything not denied and within the risk ceiling is allowed.

A good starting policy for an operational workspace:

```
Allowed
  search_projects  search_tasks  get_project  get_task
  get_project_health  get_team_workload  get_activity
  create_task  update_task  assign_task  change_task_status
  create_subtask  create_checklist  create_comment
  create_milestone  update_milestone  add_tag
  generate_project_report  send_notification

Approval required
  create_project  update_project  bulk_update_tasks
  manage_project_member  update_project_settings

Denied
  delete_task  delete_project  remove_workspace_member
  archive_project
```

### Actions that always require approval

These four cannot be made autonomous by any policy, in any mode:

```
delete_project
delete_task
remove_workspace_member
archive_project
```

This is enforced in `config/ai.php → approvals.always_require_approval` and checked in the
execution path, not just the UI.

---

## Execution limits

An autonomous run is bounded on every axis. Workspaces may lower these; nothing can raise
them past the global config.

| Limit | Default | What it prevents |
|---|---|---|
| Max tool calls per run | 25 | An unbounded loop |
| Max run seconds | 180 | Exhausting the PHP time limit |
| Max repeats of one tool | 5 | A stuck agent hammering one call |
| Max errors per run | 3 | Thrashing against a failing tool |
| Max runs per day | 500 | Runaway provider spend |
| Max context tokens | 24 000 | Oversized prompts |

When a run hits a limit it stops cleanly, marks itself `limit_reached`, and reports exactly
how far it got and what remains. It does not silently truncate the work and present it as
complete.

**Duplicate protection.** Every mutating call is keyed by
`sha1(run_id | tool | canonical_args)`. A repeat inside the same run returns the earlier
result rather than executing again — so an agent that retries cannot create the same task
twice.

---

## What an autonomous run looks like

Objective: *"Prepare the marketing project for launch."*

```
run 8f3ca91  ·  autonomous  ·  acting for Hatem  ·  Marketing Campaign

  1  get_project              Marketing Campaign                        ok    120ms
  2  search_tasks             status != done, project = 41              ok     84ms
  3  get_project_health       project 41                                ok     61ms
  4  search_tasks             overdue, project = 41                     ok     72ms
  5  create_task              "Finalise launch checklist"               ok    140ms
  6  create_checklist         task 918, 6 items                         ok     96ms
  7  assign_task              task 918 → Sarah                          ok     88ms
  8  update_task              task 884 due_date → 2026-09-18            ok     91ms
  9  assign_task              task 902 → Daniel                       FAILED   55ms
        Daniel is not a member of this project
 10  create_comment           project 41, launch readiness summary      ok    110ms
 11  send_notification        Sarah, project manager                    ok     70ms
 12  get_project              verification read                         ok     58ms

  status  partial      11 succeeded, 1 failed      4.2s      2 480 / 610 tokens
```

And the summary it reports:

```
Completed
  ✓ Created "Finalise launch checklist" with a 6-item checklist, assigned to Sarah
  ✓ Moved task 884 to 18 September
  ✓ Posted a launch-readiness summary to the project
  ✓ Notified Sarah

Not completed
  ✗ Could not assign task 902 to Daniel — he is not a member of this project.
    Add him to Marketing Campaign, or pick a different assignee.
```

Note what it did not do: it did not claim success for step 9, and it did not quietly pick a
different person.

---

## Watching it

| Where | What you see |
|---|---|
| **AI → Runs** | Every run: trigger, mode, acting user, status, duration, tokens |
| **AI → Tool calls** | The full sequence for any run, with arguments and results |
| **AI → Approvals** | Anything waiting on a human |
| **Project → Activity** | AI changes inline with human ones, visually distinct |
| **Admin → Audit log** | Every change to AI settings and policies |
| **AI → Usage** | Runs, tool calls, errors and tokens by day, workspace, user and model |

Review the tool-call log weekly for the first month. It is the fastest way to notice the AI
doing something reasonable-looking but wrong.

---

## Automations

Autonomous mode becomes genuinely useful combined with automations — standing objectives
that run on a schedule or in response to an event.

```
Every weekday 08:00 · autonomous · all active projects
  "Review active projects. Where a task is overdue and its assignee is on leave,
   reassign it to another member of the same team and add a comment explaining why.
   Post a summary of anything you could not resolve."
```

Writing a good objective is most of the work:

**Be specific about scope.** "All active projects" and "the Marketing Campaign project" lead
to very different runs.

**State the boundary.** "Do not change due dates" is honoured, and is safer than hoping.

**Ask for a summary.** An automation that reports what it did is one you can supervise.

**Prefer narrow and frequent over broad and rare.** A daily automation touching one project
is easier to trust than a weekly one touching everything.

Automations depend on cron — see [CRON.md](CRON.md). Each takes a database lock before
running, so overlapping cron ticks are no-ops. Repeated failures back off, and ten
consecutive failures disable the automation rather than burning provider budget silently.

---

## Stopping it

**Immediate, everything:** Admin → AI → **Kill switch**. New runs refused, in-flight runs
abort at the next step, banner shown to the workspace. Nothing else in Planvio is affected.

**Immediate, autonomy only:** turn off **Autonomous enabled**. The workspace drops to
Copilot; people keep their assistant, unattended execution stops.

**Narrower:** deactivate a single automation, or tighten one policy's `max_risk`.

All four are ordinary settings changes, recorded in the audit log.

---

## Undoing what it did

- **Tasks and projects are soft-deleted.** They can be restored from Admin.
- **Every change is in the activity log** with the previous and new value, so a manual
  revert is a matter of reading and reversing.
- **Comments and documents authored by the AI** are marked as such and can be deleted.
- **Notifications already sent cannot be recalled.** Email that has left the server is gone;
  this is why `send_notification` sits at low risk but is worth thinking about in a policy.

There is no one-click "undo this run". Restoring from a database backup remains the
guaranteed path if a run does real damage, which is why the pre-flight checklist asks
whether you have one.

---

## Honest limitations

- **The AI can be wrong in ways that are permitted.** Reassigning work to the wrong
  qualified person, moving a date that should not have moved, writing a summary that misses
  the point — none of these are security failures, and none are blocked. Supervision is
  the control.
- **Objectives are natural language.** An ambiguous objective produces ambiguous work. The
  AI asks when ambiguity would change what it does, but it cannot ask during an unattended
  automation — it reports instead and stops.
- **Model quality matters.** Autonomous mode on a weak model is not a good experience. Use a
  current frontier model with strong tool calling.
- **No streaming.** Runs are queued; you see the result when the queue worker processes
  them. On a five-minute queue cron, that is the latency.
- **A partial run is a normal outcome.** Permission errors, missing members and validation
  failures happen. Planvio reports them accurately rather than pretending; expect to see
  `partial` in the run log and treat it as information, not a fault.

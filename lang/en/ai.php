<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Provider transport
    |--------------------------------------------------------------------------
    |
    | Shown when a model endpoint fails: in the run report, in the chat surface,
    | and under "Test connection" in the admin panel. Each one names the driver,
    | the status and the limit that was hit — and nothing else. A provider error
    | must never carry the base URL (which can itself contain credentials), the
    | request body, a stack trace or a file path (CLAUDE.md rule 4).
    |
    */

    'provider' => [
        'connection_failed' => 'Could not reach the :provider endpoint. Check that the server is running and reachable from this host.',
        'timeout' => 'The :provider endpoint did not respond within :seconds seconds.',
        'rate_limited' => 'The :provider endpoint is rate limiting requests. Try again shortly.',
        'unauthorized' => 'The :provider endpoint rejected the credentials for this provider. Check the API key in AI settings.',
        'not_found' => 'The :provider endpoint returned 404. Check the base URL and the model name.',
        'server_error' => 'The :provider endpoint returned an error (:status). This is a fault at their end, not in your workspace.',
        'http_error' => 'The :provider endpoint rejected the request (:status).',
        'malformed_response' => 'The :provider endpoint returned a response Planvio could not read.',
        'unexpected' => 'The call to :provider failed unexpectedly.',
        'unknown_driver' => 'This provider is configured with a driver Planvio does not support.',
        'misconfigured' => 'The :provider provider is not configured correctly. Check its base URL and model in AI settings.',
        'health_ok' => 'Connected successfully.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Prompt assembly
    |--------------------------------------------------------------------------
    |
    | The operating instructions carry the standing rule that separates data from
    | instructions, so a run cannot start without them.
    |
    */

    'prompt' => [
        'system_prompt_missing' => 'The AI operating instructions are missing from this installation, so no AI run can start. Restore resources/ai/system.md and try again.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tool outcomes
    |--------------------------------------------------------------------------
    |
    | Every string here is read twice: once by the model, which has to report it
    | back honestly, and once by a human reading the tool-run log. So each one
    | states a fact — what was asked for, what came back, what was withheld and
    | why — and never apologises, never speculates and never names a record the
    | caller may not see. A refusal says the boundary held; it does not hint at
    | what lies on the other side.
    |
    | Tool NAMES, DESCRIPTIONS and JSON-Schema text are deliberately absent:
    | those are addressed to the model, and their meaning must not shift with the
    | workspace's UI locale (the same reason PromptBuilder's scaffolding is not
    | translated).
    |
    */

    'tools' => [

        'invalid_arguments' => 'The arguments for :tool were rejected: :reason.',
        'not_found' => 'No :subject matching ":reference" exists in this workspace, or you cannot see it.',
        'omitted' => ':count more were not returned; narrow the filters to see them.',
        'text_search_capped' => 'The text search examined only the first :limit matches, so counts here may understate the total. Use a narrower term.',
        'contradictory_filters' => 'The :first and :second filters contradict each other.',
        'unreadable_date' => 'The :field value ":value" could not be read as a date.',

        'denied' => [
            'projects' => 'You cannot view projects in this workspace.',
            'project' => 'You cannot view this project.',
            'tasks' => 'You cannot view tasks in this workspace.',
            'task' => 'You cannot view this task.',
            'workspace' => 'You cannot view this workspace.',
            'reports' => 'You cannot view reports for this scope.',
            'budget' => 'You cannot view the budget for this project.',
            'wiki' => 'You cannot view wiki pages in this scope.',
            'activity' => 'You cannot view the activity feed for this scope.',
            'members' => 'You cannot view the members of this project.',
            'milestones' => 'You cannot view milestones in this project.',
            'time_by_user' => 'You cannot view other people\'s time entries in this scope, so this report cannot be grouped by person. Group by project, task or day instead.',
        ],

        'notes' => [
            'assessment' => 'The health assessment is derived by Planvio from the signals listed beside it. It is an assessment, not a recorded fact — the value the system stores is `stored_health`.',
            'own_time_only' => 'You cannot view other people\'s time entries in this scope, so this report covers only your own.',
            'no_explicit_members' => 'This project has no explicit members. Every workspace member above guest can already see it; only guests need to be added.',
            'unconverted_costs' => 'Some expenses were booked in another currency. Planvio ships no exchange rates, so they are reported separately rather than added to the total.',
            'labour_not_costed' => 'Logged time is reported as minutes, not money: Planvio stores no rates, so labour is never priced.',
            'due_window' => 'Only tasks with a due date inside the window are counted. Tasks with no due date are excluded from a windowed report.',
            'milestones_withheld' => 'Milestones were not read because you cannot view them in this project.',
            'comments_withheld' => 'Comments were not read because you cannot view them on this task.',
            'budget_withheld' => 'Budget figures were not read because you cannot view them for this project.',
        ],

        'summary' => [
            'projects' => 'Returned :returned of :total projects matching the filters.',
            'tasks' => 'Returned :returned of :total tasks matching the filters.',
            'wiki' => 'Returned :returned of :total wiki pages matching ":query".',
            'activity' => 'Returned the :returned most recent of :total activity entries for :scope.',
            'members' => 'Returned :returned of :total explicit members of :project.',
            'workload' => 'Workload for :scope: :returned of :total people or buckets, :open open tasks, :overdue overdue.',
            'time' => 'Logged time for :scope, :from to :to, grouped by :group: :hours hours across :rows rows.',
            'budget' => 'Budget for :project: :actual of :planned :currency spent (:utilisation%).',
            'budget_unset' => 'No budget is set for :project. :actual :currency has been spent so far.',
            'project' => ':key :name — :status, recorded health :health, :open of :total tasks open, :overdue overdue.',
            'task' => ':key :title — :status, :assignee, :due.',
            'overview' => ':workspace: :active active projects, :at_risk not on track, :open open tasks assigned to you, :overdue of them overdue.',
            'health' => ':key :name — recorded health :stored, calculated assessment :computed from :signals signal(s).',
        ],

        'labels' => [
            'unassigned' => 'unassigned',
            'no_due_date' => 'no due date',
            'workspace_scope' => 'the whole workspace',
        ],

        /*
        |------------------------------------------------------------------
        | The tool catalogue, as a person reads it
        |------------------------------------------------------------------
        |
        | `AiTool::description()` is written for the model and is what the
        | prompt carries. Settings -> AI shows the same list to an
        | administrator deciding what the agent may reach for, and that
        | reader is not always reading English — so the screen goes through
        | these lines and falls back to the tool's own text for anything not
        | catalogued yet. The English here is that text verbatim, so nothing
        | on an English installation changes.
        |
        | Names of JSON fields and of tools stay in Latin script in every
        | language: they are identifiers a policy allow-list and an audit row
        | are addressed by, not prose.
        |
        */
        'names' => [
            'add_tag' => 'Add tag',
            'archive_project' => 'Archive project',
            'assign_task' => 'Assign task',
            'bulk_update_tasks' => 'Bulk update tasks',
            'change_task_status' => 'Change task status',
            'create_checklist' => 'Create checklist',
            'create_comment' => 'Create comment',
            'create_dependency' => 'Create dependency',
            'create_document' => 'Create document',
            'create_memory' => 'Create memory',
            'create_milestone' => 'Create milestone',
            'create_project' => 'Create project',
            'create_saved_view' => 'Create saved view',
            'create_subtask' => 'Create subtask',
            'create_task' => 'Create task',
            'delete_project' => 'Delete project',
            'delete_task' => 'Delete task',
            'generate_project_report' => 'Generate project report',
            'get_activity' => 'Get activity',
            'get_budget_summary' => 'Get budget summary',
            'get_project' => 'Get project',
            'get_project_health' => 'Get project health',
            'get_task' => 'Get task',
            'get_team_workload' => 'Get team workload',
            'get_time_report' => 'Get time report',
            'get_workspace_overview' => 'Get workspace overview',
            'list_project_members' => 'List project members',
            'manage_project_member' => 'Manage project member',
            'remove_tag' => 'Remove tag',
            'remove_workspace_member' => 'Remove workspace member',
            'search_projects' => 'Search projects',
            'search_tasks' => 'Search tasks',
            'search_wiki' => 'Search wiki',
            'send_notification' => 'Send notification',
            'update_document' => 'Update document',
            'update_milestone' => 'Update milestone',
            'update_project' => 'Update project',
            'update_project_settings' => 'Update project settings',
            'update_task' => 'Update task',
        ],

        'groups' => [
            'comments' => 'comments',
            'dependencies' => 'dependencies',
            'members' => 'members',
            'memory' => 'memory',
            'milestones' => 'milestones',
            'notifications' => 'notifications',
            'projects' => 'projects',
            'read' => 'read',
            'reports' => 'reports',
            'tags' => 'tags',
            'tasks' => 'tasks',
            'views' => 'views',
            'wiki' => 'wiki',
        ],

        'about' => [
            'add_tag' => 'Put an existing workspace tag on a task or a project, by tag name or id. Does not create tags: an unknown name is refused and the available tags are listed. Tagging something twice is harmless and changes nothing.',
            'archive_project' => 'Archive one project, removing it from active boards, lists and reports without deleting anything. Reversible. Always requires a human approval, in every mode and under every policy — propose it, state what it affects, and wait. One project per call.',
            'assign_task' => 'Assign a task to a workspace member, or pass null for assignee_id to unassign it. Fails if the person is not a member of the workspace — it will never substitute somebody else.',
            'bulk_update_tasks' => 'Apply the same change to several tasks at once — priority, assignee, milestone, dates, or a board column. At most 100 tasks per call; anything beyond that is reported back, not silently skipped. Tasks the acting user may not edit are listed and left alone.',
            'change_task_status' => 'Move a task into another column on its own project board, by column name (e.g. "In Progress"). Completion dates follow the column automatically. Refuses a column name the project does not have and lists the ones it does.',
            'create_checklist' => 'Add checklist items to a task, in the order given. At most 50 per call; anything beyond that is reported back rather than silently dropped. Items are appended after any that already exist.',
            'create_comment' => 'Post a comment on a task, project, milestone or wiki page. The comment is always shown as written by the assistant and can never be attributed to a person. Mentions only reach people who are already members of the project.',
            'create_dependency' => 'Record that one task depends on another. Refuses any link that would create a cycle and names the chain it would close. Dependencies may cross projects but never workspaces.',
            'create_document' => 'Write a new wiki page, in a project or at workspace level. The page is marked as AI-generated and shown that way to readers. Content is sanitised on the way in. A parent page must be in the same project.',
            'create_memory' => 'Remember a durable fact for later runs, under a short key. Scope it to the workspace, to one project, or to the person you are acting for. Writing the same key again replaces what it held. Only record things that will still be true next week.',
            'create_milestone' => 'Create a milestone in a project. Dates may be written plainly and are read in the workspace timezone; an ambiguous phrase is refused rather than guessed. An owner must already be a member of the workspace.',
            'create_project' => 'Create a project in this workspace, owned by the acting user, with the workspace default board columns. The project key is generated from the name. Budgets are not set here — they need the budget permission.',
            'create_saved_view' => 'Save a filtered list, board, calendar or timeline so it can be opened again. Personal by default; sharing it with the workspace needs the template permission and is refused rather than downgraded if the acting user lacks it.',
            'create_subtask' => 'Create a task underneath an existing one. The subtask is created in the parent task\'s project; you cannot put it somewhere else. Dates may be written plainly and are read in the workspace timezone.',
            'create_task' => 'Create a task in a project. Dates may be written plainly ("tomorrow", "end of month", "2026-09-30") and are read in the workspace timezone; a phrase with more than one possible meaning is refused rather than guessed. Refuses an assignee who is not in the workspace, a board column belonging to another project, and any project the acting user cannot create tasks in.',
            'delete_project' => 'Delete one project. It is archived and moved to the trash, and its tasks, milestones, wiki pages and time entries stop being reachable. Always requires a human approval, in every mode and under every policy. One project per call — state what it would take with it before proposing it.',
            'delete_task' => 'Delete one task and every subtask beneath it. They move to the trash and can be restored. Always requires a human approval, in every mode and under every policy. Deleting a parent deletes its whole subtree, so state the subtask count when you propose it.',
            'generate_project_report' => 'Build a structured status report for one project. The result separates `recorded` (numbers Planvio actually stores) from `analysis` (derived judgement, with the facts behind it). Keep that separation when you write it up. Sections the acting user may not see are listed under `omitted` rather than left out silently.',
            'get_activity' => 'Read the most recent activity entries, newest first, for one project or for the whole workspace. Each entry names the event, the record it happened to, who caused it and whether that was a person or the AI acting for them.',
            'get_budget_summary' => 'Read one project\'s budget: planned amount, actual spend, variance and utilisation in the project currency, plus logged and billable minutes. Costs booked in other currencies are listed separately, never converted.',
            'get_project' => 'Read one project in full: status, health, dates, progress, task counts, milestones, members and — where the acting user may see it — budget. Accepts a project id or a project key such as WEB.',
            'get_project_health' => 'Assess one project\'s health and return the facts behind it: overdue task count and oldest overdue due date, delayed milestones, and how much of the open work sits with one person. The assessment is labelled as an assessment; stored_health is the value recorded on the project.',
            'get_task' => 'Read one task in full: status, assignee, dates, estimate, progress, its subtasks, checklist items, dependencies in both directions and its most recent comments. Accepts a task id or a display key such as WEB-42.',
            'get_team_workload' => 'Report open, completed and overdue task counts per person, for one project or for the whole workspace, optionally narrowed to tasks due within a window. Includes an unassigned bucket. People holding nothing are listed too.',
            'get_time_report' => 'Report logged time over a date range for one project or the whole workspace, grouped by project, person, task or day. Returns minutes and billable minutes, never money. Without permission to see other people\'s time, the report covers only the acting user and says so.',
            'get_workspace_overview' => 'Summarise the bound workspace: project counts, the most recently active projects, the projects whose recorded health is not on track, and the acting user\'s own open and overdue tasks. Counts cover only what the acting user may see.',
            'list_project_members' => 'List the people explicitly added to a project, with their project role and their workspace role. Projects with no explicit members are still visible to every workspace member above guest.',
            'manage_project_member' => 'Add a workspace member to a project, change their project role, or remove them from it. The project "manager" role grants permission to update and archive that project and to delete its tasks, so use it deliberately. Removing a workspace guest removes their access to the project and hands their open tasks to reassign_to_user_id or leaves them unassigned.',
            'remove_tag' => 'Take a tag off a task or a project, by tag name or id. The tag itself is not deleted and other records keep it. Removing a tag that was not there changes nothing.',
            'remove_workspace_member' => 'Remove one person from this workspace. They lose access to every project and team in it, and their open tasks go to reassign_to_user_id or are left unassigned; their comments, time entries and history stay. Always requires a human approval, in every mode and under every policy. The last owner of a workspace cannot be removed.',
            'search_projects' => 'Find projects in this workspace by free text and/or by status category, health and archived state. Returns only projects the acting user may see, newest activity first, with a count of how many matches were not returned.',
            'search_tasks' => 'Find tasks in this workspace by free text and/or by project, status category, assignee, priority, milestone, overdue state and due-date window. Returns only tasks in projects the acting user may see, and reports how many matches were not returned.',
            'search_wiki' => 'Search wiki page titles, excerpts and content in this workspace. Returns only pages the acting user may read, with a short excerpt of each. Optionally restricted to one project.',
            'send_notification' => 'Notify workspace members by user id, through their own notification preferences. Cannot reach anyone outside the workspace and takes no email address. At most 25 people per call. People who have muted this kind of notification are not reached, and the result says who was.',
            'update_document' => 'Edit a wiki page. Fields you do not send keep their current value — omitting content leaves the page body alone; send an empty string to genuinely clear it. The page URL does not change unless rename_url is true.',
            'update_milestone' => 'Change a milestone\'s name, description, status, dates, owner or progress. Only the fields you send are touched; dates cannot be cleared through this tool. Completion dates follow the status automatically.',
            'update_project' => 'Change a project\'s name, description, type, priority, health, client, department or dates. The project key and slug never change. Budgets, members, ownership and project settings are out of scope for this tool. Setting health pins it: the automatic health calculation stops overriding it, so say so when you use it.',
            'update_project_settings' => 'Change a project\'s governance settings: owner, manager, type, budget, currency, client name and department. It cannot rename a project, change its key, or edit anything else — use update_project for those. Changing the owner or manager grants project-manager permissions and changing the budget needs budget permissions, so each is authorised separately.',
            'update_task' => 'Change fields on an existing task. Only the fields you send are touched; sending null for assignee_id, milestone_id, start_date or due_date clears them. Dates may be written plainly and are read in the workspace timezone; an ambiguous phrase is refused rather than guessed.',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | The run gate
    |--------------------------------------------------------------------------
    |
    | Why a run could not start, from App\Ai\AiGate. Each of these is read three
    | ways — shown to the person who asked, written to ai_runs.error, and written
    | to a log — so every one names a setting or a limit and nothing else. No
    | endpoint, no credential, no other tenant's data.
    |
    */

    'gate' => [
        'disabled_globally' => 'AI is switched off for this installation.',
        'workspace_suspended' => 'This workspace is suspended, so nothing can run in it.',
        'not_configured' => 'The AI layer has not been configured yet.',
        'disabled_for_workspace' => 'AI is not enabled for this workspace.',
        'kill_switch' => 'The AI kill switch is engaged for this workspace. No new run can start until it is released.',
        'no_provider' => 'No AI provider is attached to this workspace.',
        'provider_inactive' => 'The AI provider for this workspace is switched off.',
        'not_permitted' => 'You do not have permission to use AI in this workspace.',
        'rate_limited' => 'You have started :limit AI runs in the past hour, which is the limit. Try again shortly.',
        'daily_cap' => 'This workspace has used its :limit AI runs for today.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Approvals
    |--------------------------------------------------------------------------
    |
    | An approval request that nobody answered is closed rather than left open,
    | and the reason is recorded on the tool run so the log says what happened.
    |
    */

    'approvals' => [
        'expired' => 'The approval request was not answered within :minutes minutes, so it expired and the action was not carried out.',
        'run_abandoned' => 'Stopped: the approval this run was waiting for expired before anyone answered it.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage
    |--------------------------------------------------------------------------
    |
    | Labels for rollup rows that belong to nobody in particular. Planvio never
    | prints a currency figure beside these: providers do not return a price with
    | a completion, and a guessed cost presented as a fact is worse than none.
    |
    */

    'usage' => [
        'platform_bucket' => 'Platform (no workspace)',
        'unattributed' => 'Account removed',
        'unknown_model' => 'Model not recorded',
        'unknown_provider' => 'Provider not recorded',
    ],

    /*
    |--------------------------------------------------------------------------
    | Console
    |--------------------------------------------------------------------------
    |
    | Output of the ai:* commands. These reach cron logs and support tickets, so
    | they carry counts and settings — never a key, never a base URL.
    |
    */

    'console' => [
        'ai_disabled' => 'AI is switched off for this installation, so there is nothing to do.',
        'ai_disabled_test' => 'AI is switched off for this installation. Testing the connection anyway.',
        'automations_disabled' => 'AI automations are switched off, so nothing was started.',
        'automations_started' => 'Started :count automation run(s).',
        'nothing_due' => 'No automation is due.',
        'unreadable_moment' => 'That --at value could not be read as a date and time.',
        'unreadable_minutes' => 'The --minutes value must be a whole number of minutes greater than zero.',
        'no_approvals_expired' => 'No approval request has passed its time-to-live.',
        'approvals_would_expire' => ':count approval request(s) have gone unanswered for more than :minutes minutes.',
        'approvals_expired' => 'Expired :count approval request(s) and closed :runs run(s) that were waiting on them.',
        'nothing_to_prune' => 'Nothing to prune: every workspace is set to keep its AI history.',
        'key_configured' => 'configured',
        'key_absent' => 'not configured',
        'provider_required' => 'Name the provider to test, by id or by name.',
        'provider_not_found' => 'No AI provider called ":name" is configured.',
        'provider_ambiguous' => 'More than one provider is called ":name". Use its id instead.',
        'no_providers' => 'No AI provider is configured on this installation.',
    ],

];

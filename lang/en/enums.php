<?php

declare(strict_types=1);

return [

    'workspace_role' => [
        'owner' => 'Owner',
        'admin' => 'Administrator',
        'manager' => 'Manager',
        'member' => 'Member',
        'guest' => 'Guest',
    ],

    'project_role' => [
        'manager' => 'Project manager',
        'member' => 'Member',
        'guest' => 'Guest',
    ],

    'permission' => [
        'workspace' => [
            'view' => 'View workspace',
            'manage' => 'Manage workspace',
            'delete' => 'Delete workspace',
        ],
        'project' => [
            'view' => 'View projects',
            'create' => 'Create projects',
            'update' => 'Edit projects',
            'delete' => 'Delete projects',
            'archive' => 'Archive projects',
            'manage_members' => 'Manage project members',
        ],
        'task' => [
            'view' => 'View tasks',
            'create' => 'Create tasks',
            'update' => 'Edit tasks',
            'delete' => 'Delete tasks',
            'assign' => 'Assign tasks',
            'comment' => 'Comment on tasks',
        ],
        'milestone' => [
            'view' => 'View milestones',
            'manage' => 'Manage milestones',
        ],
        'time' => [
            'log' => 'Log time',
            'view_all' => 'View time logged by everyone',
        ],
        'budget' => [
            'view' => 'View budgets and expenses',
            'manage' => 'Manage budgets and expenses',
        ],
        'wiki' => [
            'view' => 'View wiki pages',
            'manage' => 'Manage wiki pages',
        ],
        'attachment' => [
            'upload' => 'Upload attachments',
            'delete' => 'Delete attachments',
        ],
        'reports' => [
            'view' => 'View reports',
        ],
        'settings' => [
            'manage' => 'Manage workspace settings',
        ],
        'users' => [
            'manage' => 'Manage members and invitations',
        ],
        'webhooks' => [
            'manage' => 'Manage webhooks',
        ],
        'templates' => [
            'manage' => 'Manage project templates',
        ],
        'ai' => [
            'use' => 'Use the AI assistant',
            'manage' => 'Manage AI configuration',
            'autonomous' => 'Run the AI in autonomous mode',
            'manage_policies' => 'Manage AI policies',
            'view_logs' => 'View AI activity logs',
            'approve' => 'Approve AI actions',
        ],
    ],

    'permission_group' => [
        'workspace' => 'Workspace',
        'project' => 'Projects',
        'task' => 'Tasks',
        'milestone' => 'Milestones',
        'time' => 'Time tracking',
        'budget' => 'Budget',
        'wiki' => 'Wiki',
        'attachment' => 'Attachments',
        'reports' => 'Reports',
        'settings' => 'Settings',
        'users' => 'Users',
        'webhooks' => 'Webhooks',
        'templates' => 'Templates',
        'ai' => 'AI',
    ],

    'priority' => [
        'none' => 'No priority',
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'urgent' => 'Urgent',
    ],

    'status_category' => [
        'backlog' => 'Backlog',
        'todo' => 'To do',
        'in_progress' => 'In progress',
        'review' => 'In review',
        'blocked' => 'Blocked',
        'done' => 'Done',
        'cancelled' => 'Cancelled',
    ],

    'project_type' => [
        'general' => 'General',
        'software' => 'Software',
        'marketing' => 'Marketing',
        'operations' => 'Operations',
        'construction' => 'Construction',
        'event' => 'Event',
        'product_launch' => 'Product launch',
        'hr' => 'Human resources',
        'sales' => 'Sales',
        'finance' => 'Finance',
        'research' => 'Research',
        'creative' => 'Creative',
        'client' => 'Client work',
    ],

    'project_health' => [
        'on_track' => 'On track',
        'at_risk' => 'At risk',
        'off_track' => 'Off track',
    ],

    'milestone_status' => [
        'planned' => 'Planned',
        'in_progress' => 'In progress',
        'completed' => 'Completed',
        'delayed' => 'Delayed',
        'cancelled' => 'Cancelled',
    ],

    'dependency_type' => [
        'finish_to_start' => 'Finish to start',
        'blocks' => 'Blocks',
        'relates_to' => 'Relates to',
    ],

    'recurrence_frequency' => [
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
        'yearly' => 'Yearly',
        'custom' => 'Custom',
    ],

    'author_type' => [
        'user' => 'Person',
        'ai' => 'AI',
    ],

    'wiki_visibility' => [
        'project' => 'Project members',
        'workspace' => 'Everyone in the workspace',
        'private' => 'Only me',
    ],

    'view_type' => [
        'list' => 'List',
        'board' => 'Board',
        'calendar' => 'Calendar',
        'timeline' => 'Timeline',
    ],

    'custom_field_type' => [
        'text' => 'Text',
        'number' => 'Number',
        'date' => 'Date',
        'select' => 'Dropdown',
        'multi_select' => 'Multi-select',
        'checkbox' => 'Checkbox',
        'url' => 'Link',
    ],

    'ai_driver' => [
        'openai' => 'OpenAI',
        'anthropic' => 'Anthropic',
        'openai_compatible' => 'OpenAI-compatible endpoint',
        'custom_http' => 'Custom HTTP endpoint',
    ],

    'ai_mode' => [
        'assistant' => 'Assistant',
        'copilot' => 'Copilot',
        'autonomous' => 'Autonomous',
    ],

    'ai_mode_description' => [
        'assistant' => 'Answers questions and drafts content. It never changes your data.',
        'copilot' => 'Proposes changes and carries them out once you approve them.',
        'autonomous' => 'Carries out permitted actions on its own, within the policy limits you set.',
    ],

    'ai_scope' => [
        'workspace' => 'Workspace',
        'project' => 'Project',
        'task' => 'Task',
    ],

    'ai_message_role' => [
        'system' => 'System',
        'user' => 'User',
        'assistant' => 'Assistant',
        'tool' => 'Tool',
    ],

    'ai_trigger' => [
        'chat' => 'Chat',
        'automation' => 'Automation',
        'api' => 'API',
        'system' => 'System',
    ],

    'ai_run_status' => [
        'queued' => 'Queued',
        'running' => 'Running',
        'awaiting_approval' => 'Awaiting approval',
        'succeeded' => 'Succeeded',
        'partial' => 'Partially completed',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
        'limit_reached' => 'Limit reached',
    ],

    'tool_run_status' => [
        'pending_approval' => 'Pending approval',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'succeeded' => 'Succeeded',
        'failed' => 'Failed',
        'skipped' => 'Skipped',
    ],

    'ai_tool_risk' => [
        'read' => 'Read-only',
        'low' => 'Low risk',
        'medium' => 'Medium risk',
        'high' => 'High risk',
        'destructive' => 'Destructive',
    ],

    'ai_tool_risk_description' => [
        'read' => 'Reads data only. Nothing is changed.',
        'low' => 'Adds low-impact content such as comments, checklists or saved views.',
        'medium' => 'Creates or updates projects, tasks and milestones.',
        'high' => 'Changes project settings, membership or archive state.',
        'destructive' => 'Deletes records or removes people from the workspace.',
    ],

    'ai_memory_scope' => [
        'workspace' => 'Workspace',
        'project' => 'Project',
        'user' => 'User',
        'run' => 'Single run',
    ],

    'ai_memory_source' => [
        'ai' => 'Learned by AI',
        'user' => 'Set by a user',
        'system' => 'System',
    ],

    'automation_trigger' => [
        'schedule' => 'On a schedule',
        'event' => 'When an event happens',
    ],

    /*
     * The palette vocabulary. Not an enum, but the same kind of value: it is what an
     * enum's color() returns and what <x-ui.badge> and App\Support\ChartPalette accept,
     * and a colour picker builds its key at runtime, so it can only live in a group file.
     */
    'color' => [
        'brand' => 'Brand',
        'blue' => 'Blue',
        'indigo' => 'Indigo',
        'teal' => 'Teal',
        'green' => 'Green',
        'amber' => 'Amber',
        'orange' => 'Orange',
        'red' => 'Red',
        'purple' => 'Purple',
        'pink' => 'Pink',
        'gray' => 'Grey',
        'muted' => 'Muted',
        'accent' => 'Accent',
    ],

];

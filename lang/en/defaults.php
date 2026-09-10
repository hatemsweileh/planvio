<?php

declare(strict_types=1);

/*
 | The starter board columns, project stages and tags Planvio ships in
 | config('planvio.defaults'). They are translated once, when a workspace is created,
 | and become that workspace's own editable rows from then on.
 |
 | They live in their own group rather than in the shared JSON catalogue because several
 | are ordinary words that already mean something else elsewhere in the product — see
 | App\Support\DefaultNames.
 */
return [
    // Task board columns
    'backlog' => 'Backlog',
    'to_do' => 'To Do',
    'in_progress' => 'In Progress',
    'review' => 'Review',
    'blocked' => 'Blocked',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',

    // Project lifecycle stages
    'planning' => 'Planning',
    'active' => 'Active',
    'on_hold' => 'On Hold',

    // Starter tags
    'urgent' => 'Urgent',
    'client' => 'Client',
    'internal' => 'Internal',
    'design' => 'Design',
    'marketing' => 'Marketing',
    'finance' => 'Finance',
    'operations' => 'Operations',
];

<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Global search
    |--------------------------------------------------------------------------
    |
    | Group headings for App\Services\SearchType, used by the command palette and
    | by any search result list. Plural, because they name a group of hits rather
    | than a single record.
    |
    */

    'types' => [
        'project' => 'Projects',
        'task' => 'Tasks',
        'milestone' => 'Milestones',
        'wiki_page' => 'Pages',
        'comment' => 'Comments',
        'user' => 'People',
        'team' => 'Teams',
    ],

];

<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Domain invariant failures
    |--------------------------------------------------------------------------
    |
    | Actions never authorize — they only refuse work that would leave the data
    | in a state the product cannot represent. These are the messages that come
    | back when one of those invariants is broken, so they are written for the
    | person who tripped it rather than for a log file.
    |
    */

    'tasks' => [
        'status_not_in_project' => 'That status belongs to a different project.',
        'no_status_available' => 'The project :project has no board column to place the task in.',
        'assignee_not_in_workspace' => ':name is not a member of this workspace.',
        'watcher_not_in_workspace' => ':name is not a member of this workspace.',
        'milestone_not_in_project' => 'That milestone belongs to a different project.',
        'parent_not_in_project' => 'A subtask must live in the same project as its parent.',
        'subtask_cycle' => 'A task cannot become a subtask of one of its own subtasks.',
        'checklist_item_not_on_task' => 'That checklist item belongs to a different task.',
        'due_before_start' => 'The due date cannot fall before the start date.',
        'progress_out_of_range' => 'Progress must be a whole number between 0 and 100.',
        'estimate_negative' => 'An estimate cannot be negative.',
        'copy_of' => 'Copy of :title',
    ],

    'dependencies' => [
        'self_dependency' => 'A task cannot depend on itself.',
        'across_workspaces' => 'Both tasks must belong to the same workspace.',
        'cycle' => 'That dependency would create a loop: :path.',
    ],

    'tags' => [
        'not_in_workspace' => 'That tag belongs to a different workspace.',
        'slug_taken' => 'A tag called ":name" already exists in this workspace.',
        'taggable_not_in_workspace' => 'That record belongs to a different workspace.',
    ],

    'recurring' => [
        'interval_too_small' => 'The repeat interval must be at least 1.',
        'ends_before_start' => 'The end date cannot fall before the start date.',
        'invalid_weekday' => 'Weekdays are numbered 1 (Monday) to 7 (Sunday).',
        'invalid_monthday' => 'Days of the month must be between 1 and 31.',
        'max_occurrences_too_small' => 'The occurrence limit must be at least 1.',
        'no_occurrence' => 'That schedule never produces an occurrence.',
        'title_required' => 'A recurring task needs a title.',
    ],

    'comments' => [
        'empty_body' => 'A comment needs something in it.',
        'not_commentable' => 'That record cannot be commented on.',
        'parent_on_another_subject' => 'A reply has to stay on the discussion it answers.',
        'parent_is_a_reply' => 'Replies go on the original comment, not on another reply.',
        'invalid_reaction' => 'That is not a usable reaction.',
    ],

    'attachments' => [
        'upload_failed' => 'That upload did not arrive intact. Try again.',
        'unreadable' => 'That file could not be read.',
        'no_extension' => 'That file has no extension, so its type cannot be checked.',
        'blocked_extension' => 'Files ending in .:extension are never accepted.',
        'extension_not_allowed' => 'Files ending in .:extension are not allowed here.',
        'mime_not_allowed' => 'That file is a :mime, which is not an accepted type.',
        'mime_mismatch' => 'That file holds :mime data, which does not match its .:extension name.',
        'too_large' => 'That file is :size, which is over the :limit limit.',
        'empty_file' => 'That file is empty.',
        'store_failed' => 'That file could not be saved. Check the storage directory.',
        'not_attachable' => 'That record cannot carry attachments.',
        'infected' => 'That file was refused by this server’s malware scanner.',
        'scanner_unavailable' => 'That file could not be checked for malware, so it was not stored. Tell an administrator the upload scanner is not answering.',
    ],

    'time' => [
        'minutes_out_of_range' => 'Logged time has to be between 1 minute and 24 hours.',
        'not_running' => 'That timer is not running.',
        'is_running' => 'Stop the timer before editing the entry.',
        'task_in_another_project' => 'That task belongs to a different project.',
        'future_date' => 'Time cannot be logged against a future date.',
    ],

    'expenses' => [
        'amount_not_positive' => 'An expense has to be greater than zero.',
        'amount_too_large' => 'That amount is larger than Planvio can store.',
        'invalid_currency' => 'A currency is a three-letter code such as USD.',
        'future_date' => 'An expense cannot be dated in the future.',
    ],

    'wiki' => [
        'title_required' => 'A page needs a title.',
        'self_parent' => 'A page cannot be filed under itself.',
        'parent_cycle' => 'A page cannot be filed under one of its own child pages.',
        'parent_in_another_project' => 'A page and its parent have to belong to the same project.',
        'reorder_mixed_parents' => 'Those pages are not all filed under the same parent.',
    ],

    'views' => [
        'name_required' => 'A view needs a name.',
        'project_in_another_workspace' => 'That project belongs to a different workspace.',
        'shared_view_has_no_owner' => 'A shared view belongs to the workspace, not to one person.',
    ],

    'custom_fields' => [
        'name_required' => 'A field needs a name.',
        'key_required' => 'A field needs a key made of letters, numbers or underscores.',
        'key_taken' => 'A field with the key ":key" already exists here.',
        'invalid_entity' => 'Custom fields can be put on tasks and projects only.',
        'options_required' => 'A :type field needs at least one option.',
        'type_change_with_values' => 'This field already holds answers, so its type cannot be changed.',
        'project_in_another_workspace' => 'That project belongs to a different workspace.',
        'field_inactive' => ':field is no longer in use.',
        'entity_mismatch' => ':field does not apply to that record.',
        'entity_out_of_scope' => ':field is not available on that record.',
        'value_required' => ':field is required.',
        'value_not_an_option' => '":value" is not one of the options for :field.',
        'value_not_text' => ':field expects text.',
        'value_not_a_number' => ':field expects a number.',
        'value_not_a_date' => ':field expects a date.',
        'value_not_a_url' => ':field expects a web address starting with http:// or https://.',
        'value_too_long' => 'That answer for :field is too long.',
    ],

];

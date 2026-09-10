{{--
    The small counts that tell you there is more inside a task than its title: subtasks,
    a checklist, files, conversation. Each one is only drawn when it has something to say,
    so a plain task stays visually plain.
--}}
@php
    $checklistTotal = (int) ($task->checklist_items_count ?? 0);
    $checklistDone = (int) ($task->checklist_items_done_count ?? 0);
    $subtaskCount = (int) ($task->subtasks_count ?? 0);
    $commentCount = (int) ($task->comments_count ?? 0);
    $attachmentCount = (int) ($task->attachments_count ?? 0);
@endphp

@if ($subtaskCount || $checklistTotal || $commentCount || $attachmentCount)
    <span class="inline-flex items-center gap-2 text-2xs tabular-nums text-[var(--text-subtle)]">
        @if ($checklistTotal)
            <span class="inline-flex items-center gap-1"
                  title="{{ __(':done of :total checklist items done', ['done' => $checklistDone, 'total' => $checklistTotal]) }}">
                <x-icon.check-circle class="size-3.5" />
                {{ $checklistDone }}/{{ $checklistTotal }}
            </span>
        @endif

        @if ($subtaskCount)
            <span class="inline-flex items-center gap-1"
                  title="{{ trans_choice('{1}:count subtask|[2,*]:count subtasks', $subtaskCount, ['count' => $subtaskCount]) }}">
                <x-icon.list class="size-3.5" />
                {{ $subtaskCount }}
            </span>
        @endif

        @if ($commentCount)
            <span class="inline-flex items-center gap-1"
                  title="{{ trans_choice('{1}:count comment|[2,*]:count comments', $commentCount, ['count' => $commentCount]) }}">
                <x-icon.chat class="size-3.5" />
                {{ $commentCount }}
            </span>
        @endif

        @if ($attachmentCount)
            <span class="inline-flex items-center gap-1"
                  title="{{ trans_choice('{1}:count file|[2,*]:count files', $attachmentCount, ['count' => $attachmentCount]) }}">
                <x-icon.paperclip class="size-3.5" />
                {{ $attachmentCount }}
            </span>
        @endif
    </span>
@endif

@php
    $progress = $this->progress;
@endphp

{{--
    The project's own AI tab.

    The same conversation machinery as the AI workspace, with the scope decided by the screen
    rather than by a control. Every run started here carries `ai_runs.project_id`, which is
    what makes this project's policy overrides, its run history and its approvals apply to
    what happens on this page.
--}}
<div class="flex h-full min-h-0 flex-col">

    <x-app.project-shell :project="$project" :workspace="$workspace" current="ai" />

    <div class="flex min-h-0 flex-1 flex-col bg-[var(--surface-panel)]">

        <div class="scrollbar-thin min-h-0 flex-1 overflow-y-auto"
             @if ($progress?->live) wire:poll.visible.{{ $progress->interval }}="tick" @endif>
            @include('livewire.app.ai._thread', ['compact' => false])
        </div>

        @include('livewire.app.ai._composer', ['compact' => false, 'lockScope' => true])
    </div>
</div>

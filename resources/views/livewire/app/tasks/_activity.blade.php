@php
    use App\Support\Formats;
@endphp

{{--
    The activity trail.

    Every line is written by an Action, never by a view, so what is shown here is what
    actually happened rather than what a form thought it submitted. An entry caused by the
    agent is marked as such and stays traceable to its run.
--}}
@php
    $activities = $this->activities;

    // The events an Action can record against a task. Anything unmapped falls back to the
    // raw event name rather than disappearing: a feed that silently drops entries is worse
    // than one that occasionally reads awkwardly.
    $sentences = [
        'created' => __('created this task'),
        'updated' => __('updated this task'),
        'status_changed' => __('changed the status'),
        'assigned' => __('assigned this task'),
        'unassigned' => __('removed the assignee'),
        'moved' => __('moved this task on the board'),
        'commented' => __('commented'),
        'checklist_item_added' => __('added a checklist item'),
        'checklist_item_updated' => __('renamed a checklist item'),
        'checklist_item_removed' => __('removed a checklist item'),
        'checklist_item_completed' => __('ticked a checklist item'),
        'checklist_item_reopened' => __('unticked a checklist item'),
        'checklist_reordered' => __('reordered the checklist'),
        'subtask_created' => __('added a subtask'),
        'dependency_added' => __('added a dependency'),
        'dependency_updated' => __('changed a dependency'),
        'dependency_removed' => __('removed a dependency'),
        'tag_added' => __('added a tag'),
        'tag_removed' => __('removed a tag'),
        'tags_synced' => __('changed the tags'),
        'attachment_added' => __('attached a file'),
        'attachment_removed' => __('removed a file'),
        'watched' => __('started watching'),
        'unwatched' => __('stopped watching'),
        'duplicated' => __('duplicated this task'),
        'restored' => __('restored this task'),
        'deleted' => __('deleted this task'),
    ];
@endphp

@if ($activities->isEmpty())
    <x-ui.empty-state compact icon="icon.clock"
                      :title="__('Nothing recorded yet')"
                      :description="__('Every change to this task lands here — who made it, and when.')" />
@else
    <ol class="space-y-2.5" role="list">
        @foreach ($activities as $activity)
            @php
                $isAi = $activity->causer_type === \App\Enums\AuthorType::Ai;
                $properties = $activity->properties ?? [];
                $detail = null;

                if ($activity->event === 'status_changed') {
                    $detail = __(':from → :to', [
                        'from' => $properties['old_status'] ?? __('none'),
                        'to' => $properties['new_status'] ?? __('none'),
                    ]);
                } elseif ($activity->event === 'assigned' && filled($properties['assignee'] ?? null)) {
                    $detail = $properties['assignee'];
                } elseif (in_array($activity->event, ['updated', 'created'], true) && is_array($properties['changes'] ?? null)) {
                    $detail = collect(array_keys($properties['changes']))
                        ->map(fn (string $column): string => str_replace('_', ' ', $column))
                        ->take(4)
                        ->join(', ');
                } elseif (filled($properties['title'] ?? null) && $activity->event !== 'created') {
                    $detail = $properties['title'];
                } elseif (filled($properties['original_name'] ?? null)) {
                    $detail = $properties['original_name'];
                }
            @endphp

            <li wire:key="activity-{{ $activity->getKey() }}" class="flex items-start gap-2.5">
                @if ($isAi)
                    <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-accent-100
                                 text-accent-600 dark:bg-accent-500/20 dark:text-accent-100">
                        <x-icon.sparkles class="size-3" />
                    </span>
                @else
                    <x-ui.avatar :user="$activity->causer" :name="$activity->causer?->name ?? __('System')"
                                 size="xs" class="mt-0.5" />
                @endif

                <p class="min-w-0 flex-1 text-xs leading-relaxed text-[var(--text-muted)]">
                    <span class="font-medium text-[var(--text-DEFAULT)]">
                        {{ $isAi ? __('Planvio AI') : ($activity->causer?->name ?? __('System')) }}
                    </span>
                    {{ $sentences[$activity->event] ?? str_replace('_', ' ', $activity->event) }}
                    @if ($detail)
                        <span class="text-[var(--text-DEFAULT)]">— {{ $detail }}</span>
                    @endif
                    <span class="whitespace-nowrap text-[var(--text-subtle)]"
                          title="{{ Formats::using($activity->created_at, 'j M Y, H:i', '') }}">
                        · {{ $activity->created_at?->diffForHumans() }}
                    </span>
                </p>
            </li>
        @endforeach
    </ol>
@endif

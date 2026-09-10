{{--
    The task detail body.

    Rendered identically by the drawer and by the full page at `app.tasks.show`, so the two
    cannot drift: one partial, one trait (App\Livewire\App\Concerns\EditsTask), two frames
    around them.

    Everything here edits in place. There is no form and no save button, because a task is
    read far more often than it is filled in, and a field that becomes editable only after
    you find an "Edit" button is a field nobody corrects.
--}}
@php
    $ws = $task->workspace;
    $canUpdate = auth()->user()?->can('update', $task) ?? false;
    $canAssign = auth()->user()?->can('assign', $task) ?? false;
    $canComment = auth()->user()?->can('comment', $task) ?? false;
    $canAttach = auth()->user()?->can('attach', $task) ?? false;
    $canDelete = auth()->user()?->can('delete', $task) ?? false;
    $blockers = $this->blockers();
    $checklist = $task->checklistItems;
    $checklistDone = $checklist->where('is_done', true)->count();
    $subtasks = $task->subtasks;
    $dependencies = $task->dependencies;
    $dependents = $task->dependents;
@endphp

<div class="space-y-5 px-4 py-4 sm:px-5">

    {{-- Blocked warning ---------------------------------------------------- --}}
    @if ($blockers->isNotEmpty())
        <div class="flex items-start gap-2.5 rounded-lg border border-critical-500/40 bg-critical-50 px-3 py-2.5
                    dark:bg-critical-950/60" role="status">
            <x-icon.warning class="mt-0.5 size-4 shrink-0 text-critical-600" />
            <div class="min-w-0 text-xs leading-relaxed">
                <p class="font-semibold text-critical-700 dark:text-critical-100">
                    {{ trans_choice('{1}Blocked by 1 open task|[2,*]Blocked by :count open tasks', $blockers->count(), ['count' => $blockers->count()]) }}
                </p>
                <p class="mt-0.5 text-[var(--text-muted)]">
                    @foreach ($blockers as $blocker)
                        <a href="{{ route('app.tasks.show', [$ws, $blocker]) }}"
                           x-on:click.prevent="$dispatch('open-task', { taskId: {{ $blocker->getKey() }} })"
                           class="underline decoration-dotted underline-offset-2 hover:text-[var(--text-DEFAULT)]"><x-ui.bidi>{{ $blocker->key }}</x-ui.bidi></a>{{ ! $loop->last ? ', ' : '' }}
                    @endforeach
                </p>
            </div>
        </div>
    @endif

    {{-- Title -------------------------------------------------------------- --}}
    <div>
        <div class="flex flex-wrap items-center gap-2 text-xs text-[var(--text-muted)]">
            <span class="font-mono tabular-nums text-[var(--text-subtle)]"><x-ui.bidi>{{ $task->key }}</x-ui.bidi></span>
            <span aria-hidden="true">·</span>
            <a href="{{ route('app.projects.tasks', [$ws, $task->project]) }}"
               class="hidden truncate transition-colors hover:text-[var(--text-DEFAULT)] sm:inline">{{ $task->project->name }}</a>

            @if ($task->parent)
                <span aria-hidden="true">·</span>
                <a href="{{ route('app.tasks.show', [$ws, $task->parent]) }}"
                   x-on:click.prevent="$dispatch('open-task', { taskId: {{ $task->parent->getKey() }} })"
                   class="inline-flex items-center gap-1 truncate transition-colors hover:text-[var(--text-DEFAULT)]">
                    <x-icon.list class="size-3.5" />
                    {{ __('Subtask of :key', ['key' => $task->parent->key]) }}
                </a>
            @endif

            @if ($task->ai_generated)
                <x-ui.badge color="purple" size="sm" icon="icon.sparkles">{{ __('AI drafted') }}</x-ui.badge>
            @endif
        </div>

        @if ($canUpdate)
            <label for="task-title-{{ $task->getKey() }}" class="sr-only">{{ __('Task title') }}</label>
            {{-- A title is the person's own words, so it takes its direction from them. --}}
            <textarea id="task-title-{{ $task->getKey() }}"
                      dir="auto"
                      wire:model="title"
                      wire:blur="saveTitle"
                      wire:keydown.enter.prevent="saveTitle"
                      rows="1"
                      maxlength="255"
                      x-data="{ grow() { $el.style.height = 'auto'; $el.style.height = $el.scrollHeight + 'px' } }"
                      x-init="grow()" x-on:input="grow()"
                      class="mt-1 block w-full resize-none rounded-md border border-transparent bg-transparent px-2 py-1
                             text-lg font-semibold leading-snug tracking-tight text-[var(--text-strong)]
                             transition-colors hover:border-[var(--line-subtle)] focus:border-[var(--accent)]
                             focus:bg-[var(--surface-panel)] focus:outline-none focus:ring-2
                             focus:ring-[var(--accent-ring)]">{{ $title }}</textarea>
        @else
            <h2 class="mt-1 px-2 py-1 text-lg font-semibold leading-snug tracking-tight text-[var(--text-strong)]">
                {{ $task->title }}
            </h2>
        @endif
    </div>

    {{-- Property grid ------------------------------------------------------ --}}
    <dl class="grid grid-cols-1 gap-x-4 gap-y-1 rounded-lg border border-[var(--line-subtle)]
               bg-[var(--surface-sunken)] p-2 sm:grid-cols-2">

        {{-- Status --}}
        <div class="flex items-center gap-2 px-1 py-1">
            <dt class="w-20 shrink-0 text-xs text-[var(--text-muted)]">{{ __('Status') }}</dt>
            <dd class="min-w-0 flex-1">
                @if ($canUpdate)
                    <x-ui.dropdown width="w-56">
                        <x-slot:trigger>
                            <button type="button"
                                    class="inline-flex h-7 max-w-full items-center gap-1.5 rounded px-1.5 text-sm
                                           text-[var(--text-DEFAULT)] transition-colors hover:bg-[var(--surface-hover)]">
                                <x-ui.status-dot :color="$task->status->color ?? 'gray'" />
                                <span dir="auto" class="truncate">{{ $task->status?->name }}</span>
                                <x-icon.chevron-down class="size-3.5 shrink-0 text-[var(--text-subtle)]" />
                            </button>
                        </x-slot:trigger>

                        @foreach ($this->taskStatuses as $status)
                            <x-ui.dropdown-item wire:click="setStatus({{ $status->getKey() }})"
                                                :active="(int) $task->status_id === (int) $status->getKey()">
                                <span class="flex items-center gap-2 overflow-hidden">
                                    <x-ui.tick :on="(int) $task->status_id === (int) $status->getKey()" />
                                    <x-ui.status-dot :color="$status->color ?? 'gray'" />
                                    <span dir="auto" class="truncate">{{ $status->name }}</span>
                                </span>
                            </x-ui.dropdown-item>
                        @endforeach
                    </x-ui.dropdown>
                @else
                    <x-ui.badge :color="$task->status->color ?? 'gray'" dot>{{ $task->status?->name }}</x-ui.badge>
                @endif
            </dd>
        </div>

        {{-- Priority --}}
        <div class="flex items-center gap-2 px-1 py-1">
            <dt class="w-20 shrink-0 text-xs text-[var(--text-muted)]">{{ __('Priority') }}</dt>
            <dd class="min-w-0 flex-1">
                @if ($canUpdate)
                    <x-ui.dropdown width="w-44">
                        <x-slot:trigger>
                            <button type="button"
                                    class="inline-flex h-7 items-center gap-1.5 rounded px-1.5 text-sm
                                           text-[var(--text-DEFAULT)] transition-colors hover:bg-[var(--surface-hover)]">
                                <x-ui.status-dot :color="$task->priority->color()" />
                                {{ $task->priority->label() }}
                                <x-icon.chevron-down class="size-3.5 shrink-0 text-[var(--text-subtle)]" />
                            </button>
                        </x-slot:trigger>

                        @foreach (array_reverse(\App\Enums\Priority::cases()) as $priority)
                            <x-ui.dropdown-item wire:click="setPriority('{{ $priority->value }}')"
                                                :active="$task->priority === $priority">
                                <span class="flex items-center gap-2 overflow-hidden">
                                    <x-ui.tick :on="$task->priority === $priority" />
                                    <x-ui.status-dot :color="$priority->color()" />
                                    <span class="truncate">{{ $priority->label() }}</span>
                                </span>
                            </x-ui.dropdown-item>
                        @endforeach
                    </x-ui.dropdown>
                @else
                    <x-ui.badge :color="$task->priority->color()">{{ $task->priority->label() }}</x-ui.badge>
                @endif
            </dd>
        </div>

        {{-- Assignee --}}
        <div class="flex items-center gap-2 px-1 py-1">
            <dt class="w-20 shrink-0 text-xs text-[var(--text-muted)]">{{ __('Assignee') }}</dt>
            <dd class="min-w-0 flex-1">
                @if ($canAssign)
                    <x-ui.dropdown width="w-60">
                        <x-slot:trigger>
                            <button type="button"
                                    class="inline-flex h-7 max-w-full items-center gap-1.5 rounded px-1.5 text-sm
                                           text-[var(--text-DEFAULT)] transition-colors hover:bg-[var(--surface-hover)]">
                                @if ($task->assignee)
                                    <x-ui.avatar :user="$task->assignee" size="xs" />
                                    <span dir="auto" class="truncate">{{ $task->assignee->name }}</span>
                                @else
                                    <span class="text-[var(--text-subtle)]">{{ __('Unassigned') }}</span>
                                @endif
                                <x-icon.chevron-down class="size-3.5 shrink-0 text-[var(--text-subtle)]" />
                            </button>
                        </x-slot:trigger>

                        <x-ui.dropdown-item wire:click="setAssignee(null)" :active="$task->assignee_id === null">
                            <span class="flex items-center gap-2 overflow-hidden">
                                <x-ui.tick :on="$task->assignee_id === null" />
                                <span class="truncate">{{ __('Unassigned') }}</span>
                            </span>
                        </x-ui.dropdown-item>

                        <x-ui.dropdown-separator />

                        <div class="max-h-64 overflow-y-auto scrollbar-thin">
                            @foreach ($this->taskMembers as $member)
                                <x-ui.dropdown-item wire:click="setAssignee({{ $member->getKey() }})"
                                                    :active="(int) $task->assignee_id === (int) $member->getKey()">
                                    <span class="flex items-center gap-2 overflow-hidden">
                                        <x-ui.tick :on="(int) $task->assignee_id === (int) $member->getKey()" />
                                        <x-ui.avatar :user="$member" size="xs" />
                                        <span dir="auto" class="truncate">{{ $member->name }}</span>
                                    </span>
                                </x-ui.dropdown-item>
                            @endforeach
                        </div>
                    </x-ui.dropdown>
                @elseif ($task->assignee)
                    <span class="inline-flex items-center gap-1.5 text-sm">
                        <x-ui.avatar :user="$task->assignee" size="xs" />
                        <span dir="auto" class="truncate">{{ $task->assignee->name }}</span>
                    </span>
                @else
                    <span class="text-sm text-[var(--text-subtle)]">{{ __('Unassigned') }}</span>
                @endif
            </dd>
        </div>

        {{-- Milestone --}}
        <div class="flex items-center gap-2 px-1 py-1">
            <dt class="w-20 shrink-0 text-xs text-[var(--text-muted)]">{{ __('Milestone') }}</dt>
            <dd class="min-w-0 flex-1">
                @if ($canUpdate && $this->taskMilestones->isNotEmpty())
                    <x-ui.dropdown width="w-64">
                        <x-slot:trigger>
                            <button type="button"
                                    class="inline-flex h-7 max-w-full items-center gap-1.5 rounded px-1.5 text-sm
                                           text-[var(--text-DEFAULT)] transition-colors hover:bg-[var(--surface-hover)]">
                                <x-icon.flag class="size-3.5 shrink-0 text-[var(--text-subtle)]" />
                                <span class="truncate {{ $task->milestone ? '' : 'text-[var(--text-subtle)]' }}">
                                    {{ $task->milestone?->name ?? __('None') }}
                                </span>
                                <x-icon.chevron-down class="size-3.5 shrink-0 text-[var(--text-subtle)]" />
                            </button>
                        </x-slot:trigger>

                        <x-ui.dropdown-item wire:click="setMilestone(null)" :active="$task->milestone_id === null">
                            <span class="flex items-center gap-2 overflow-hidden">
                                <x-ui.tick :on="$task->milestone_id === null" />
                                <span class="truncate">{{ __('None') }}</span>
                            </span>
                        </x-ui.dropdown-item>

                        <x-ui.dropdown-separator />

                        @foreach ($this->taskMilestones as $milestone)
                            <x-ui.dropdown-item wire:click="setMilestone({{ $milestone->getKey() }})"
                                                :active="(int) $task->milestone_id === (int) $milestone->getKey()">
                                <span class="flex items-center gap-2 overflow-hidden">
                                    <x-ui.tick :on="(int) $task->milestone_id === (int) $milestone->getKey()" />
                                    <span dir="auto" class="truncate">{{ $milestone->name }}</span>
                                </span>
                            </x-ui.dropdown-item>
                        @endforeach
                    </x-ui.dropdown>
                @else
                    <span class="text-sm {{ $task->milestone ? 'text-[var(--text-DEFAULT)]' : 'text-[var(--text-subtle)]' }}">
                        {{ $task->milestone?->name ?? __('None') }}
                    </span>
                @endif
            </dd>
        </div>

        {{-- Dates --}}
        <div class="flex items-center gap-2 px-1 py-1">
            <dt class="w-20 shrink-0 text-xs text-[var(--text-muted)]">
                <label for="task-start-{{ $task->getKey() }}">{{ __('Start') }}</label>
            </dt>
            <dd class="min-w-0 flex-1">
                @if ($canUpdate)
                    <input id="task-start-{{ $task->getKey() }}" type="date" wire:model.blur="startDate"
                           value="{{ $startDate }}"
                           class="h-7 w-full rounded border border-transparent bg-transparent px-1.5 text-sm
                                  tabular-nums text-[var(--text-DEFAULT)] transition-colors
                                  hover:border-[var(--line-subtle)] focus:border-[var(--accent)]
                                  focus:bg-[var(--surface-panel)] focus:outline-none focus:ring-2
                                  focus:ring-[var(--accent-ring)]">
                @else
                    <span class="px-1.5 text-sm tabular-nums">{{ $task->start_date?->isoFormat('D MMM YYYY') ?? '—' }}</span>
                @endif
            </dd>
        </div>

        <div class="flex items-center gap-2 px-1 py-1">
            <dt class="w-20 shrink-0 text-xs text-[var(--text-muted)]">
                <label for="task-due-{{ $task->getKey() }}">{{ __('Due') }}</label>
            </dt>
            <dd class="min-w-0 flex-1">
                @if ($canUpdate)
                    <input id="task-due-{{ $task->getKey() }}" type="date" wire:model.blur="dueDate"
                           value="{{ $dueDate }}"
                           class="h-7 w-full rounded border border-transparent bg-transparent px-1.5 text-sm
                                  tabular-nums transition-colors hover:border-[var(--line-subtle)]
                                  focus:border-[var(--accent)] focus:bg-[var(--surface-panel)] focus:outline-none
                                  focus:ring-2 focus:ring-[var(--accent-ring)]
                                  {{ $task->is_overdue ? 'font-medium text-critical-600' : 'text-[var(--text-DEFAULT)]' }}">
                @else
                    <span class="px-1.5 text-sm tabular-nums {{ $task->is_overdue ? 'font-medium text-critical-600' : '' }}">
                        {{ $task->due_date?->isoFormat('D MMM YYYY') ?? '—' }}
                    </span>
                @endif
            </dd>
        </div>

        {{-- Estimate --}}
        <div class="flex items-center gap-2 px-1 py-1">
            <dt class="w-20 shrink-0 text-xs text-[var(--text-muted)]">
                <label for="task-estimate-{{ $task->getKey() }}">{{ __('Estimate') }}</label>
            </dt>
            <dd class="min-w-0 flex-1">
                @if ($canUpdate)
                    <div class="flex items-center gap-1.5">
                        <input id="task-estimate-{{ $task->getKey() }}" type="number" min="0" step="0.25"
                               wire:model.blur="estimateHours" value="{{ $estimateHours }}"
                               class="h-7 w-20 rounded border border-transparent bg-transparent px-1.5 text-sm
                                      tabular-nums text-[var(--text-DEFAULT)] transition-colors
                                      hover:border-[var(--line-subtle)] focus:border-[var(--accent)]
                                      focus:bg-[var(--surface-panel)] focus:outline-none focus:ring-2
                                      focus:ring-[var(--accent-ring)]"
                               placeholder="—">
                        <span class="text-xs text-[var(--text-subtle)]">{{ __('hours') }}</span>
                    </div>
                @else
                    <span class="px-1.5 text-sm tabular-nums">
                        {{ $task->estimate_minutes === null ? '—' : __(':hours h', ['hours' => round($task->estimate_minutes / 60, 2)]) }}
                    </span>
                @endif
            </dd>
        </div>

        {{-- Reporter --}}
        <div class="flex items-center gap-2 px-1 py-1">
            <dt class="w-20 shrink-0 text-xs text-[var(--text-muted)]">{{ __('Reporter') }}</dt>
            <dd class="min-w-0 flex-1 px-1.5">
                @if ($task->reporter)
                    <span class="inline-flex items-center gap-1.5 text-sm">
                        <x-ui.avatar :user="$task->reporter" size="xs" />
                        <span dir="auto" class="truncate">{{ $task->reporter->name }}</span>
                    </span>
                @else
                    <span class="text-sm text-[var(--text-subtle)]">{{ __('Unknown') }}</span>
                @endif
            </dd>
        </div>
    </dl>

    {{-- Tags --------------------------------------------------------------- --}}
    <section aria-label="{{ __('Tags') }}">
        <div class="flex flex-wrap items-center gap-1.5">
            @forelse ($task->tags as $tag)
                <span class="inline-flex items-center gap-1">
                    <x-ui.badge :color="$tag->color ?? 'gray'" size="md">{{ $tag->name }}</x-ui.badge>
                    @if ($canUpdate)
                        <button type="button" wire:click="toggleTag({{ $tag->getKey() }})"
                                class="grid size-4 place-items-center rounded text-[var(--text-subtle)]
                                       transition-colors hover:bg-[var(--surface-hover)] hover:text-critical-600"
                                aria-label="{{ __('Remove tag :tag', ['tag' => $tag->name]) }}">
                            <svg class="size-3" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="m5 5 10 10M15 5 5 15" stroke-linecap="round"/>
                            </svg>
                        </button>
                    @endif
                </span>
            @empty
                <span class="text-xs text-[var(--text-subtle)]">{{ __('No tags') }}</span>
            @endforelse

            @if ($canUpdate && $this->taskTags->isNotEmpty())
                <x-ui.dropdown width="w-56">
                    <x-slot:trigger>
                        <x-ui.button size="xs" variant="ghost" icon="icon.tag">{{ __('Add tag') }}</x-ui.button>
                    </x-slot:trigger>

                    <div class="max-h-64 overflow-y-auto scrollbar-thin">
                        @foreach ($this->taskTags as $tag)
                            @php $on = $task->tags->contains('id', $tag->getKey()); @endphp
                            <x-ui.dropdown-item wire:click="toggleTag({{ $tag->getKey() }})" :active="$on">
                                <span class="flex items-center gap-2 overflow-hidden">
                                    <x-ui.tick :on="$on" />
                                    <x-ui.status-dot :color="$tag->color ?? 'gray'" />
                                    <span dir="auto" class="truncate">{{ $tag->name }}</span>
                                </span>
                            </x-ui.dropdown-item>
                        @endforeach
                    </div>
                </x-ui.dropdown>
            @endif
        </div>
    </section>

    {{-- Description -------------------------------------------------------- --}}
    <section aria-label="{{ __('Description') }}">
        <h3 class="mb-1.5 text-2xs font-semibold uppercase tracking-wider text-[var(--text-subtle)]">
            {{ __('Description') }}
        </h3>

        @if ($editingDescription)
            <div x-data="richEditor(@js($descriptionDraft), @js(__('Write the detail somebody picking this up would need…')))"
                 x-on:editor-input="$wire.set('descriptionDraft', $event.detail.html, false)"
                 wire:ignore
                 class="overflow-hidden rounded-lg border border-[var(--line-DEFAULT)] bg-[var(--surface-panel)]
                        focus-within:border-[var(--accent)] focus-within:ring-2 focus-within:ring-[var(--accent-ring)]">
                <div class="flex flex-wrap items-center gap-0.5 border-b border-[var(--line-subtle)]
                            bg-[var(--surface-sunken)] px-1.5 py-1">
                    @foreach ([
                        ['toggleBold', __('Bold'), 'B', 'font-bold'],
                        ['toggleItalic', __('Italic'), 'I', 'italic'],
                        ['toggleBulletList', __('Bullet list'), '••', ''],
                        ['toggleOrderedList', __('Numbered list'), '1.', ''],
                        ['toggleCodeBlock', __('Code block'), '{ }', 'font-mono'],
                        ['toggleBlockquote', __('Quote'), '❝', ''],
                    ] as [$command, $label, $glyph, $style])
                        <button type="button" x-on:click="run('{{ $command }}')"
                                class="h-6 min-w-6 rounded px-1.5 text-xs text-[var(--text-muted)] transition-colors
                                       hover:bg-[var(--surface-hover)] hover:text-[var(--text-DEFAULT)] {{ $style }}"
                                aria-label="{{ $label }}">{{ $glyph }}</button>
                    @endforeach
                </div>
                <div x-ref="editor" class="max-h-96 overflow-y-auto scrollbar-thin"></div>
            </div>

            <div class="mt-2 flex items-center gap-2">
                <x-ui.button variant="primary" size="sm" wire:click="saveDescription" wire:target="saveDescription">
                    {{ __('Save') }}
                </x-ui.button>
                <x-ui.button variant="ghost" size="sm" wire:click="cancelEditingDescription">{{ __('Cancel') }}</x-ui.button>
                <p class="ms-auto text-2xs text-[var(--text-subtle)]">{{ __('Formatting is re-checked on the server.') }}</p>
            </div>
        @elseif (filled($task->description))
            <div class="group relative rounded-lg border border-transparent px-2 py-1.5 transition-colors
                        {{ $canUpdate ? 'hover:border-[var(--line-subtle)] hover:bg-[var(--surface-sunken)]' : '' }}">
                {{--
                    Stored HTML was sanitised by App\Services\HtmlSanitizer before it was written.

                    `dir="auto"` because this paragraph was typed by a person and its language
                    is not the interface's. An English sentence inside an RTL page has its
                    closing full stop resolved to the paragraph direction and printed at the
                    wrong end — "in the project wiki." becomes ".in the project wiki" — and the
                    same happens to an Arabic sentence on an English install. Letting the
                    browser take the direction from the first strong character fixes both.
                --}}
                <div dir="auto" class="prose-planvio text-sm">{!! $task->description !!}</div>

                @if ($canUpdate)
                    <x-ui.button size="xs" variant="secondary" wire:click="startEditingDescription"
                                 class="absolute end-2 top-2 opacity-0 transition-opacity group-hover:opacity-100
                                        focus-visible:opacity-100">
                        {{ __('Edit') }}
                    </x-ui.button>
                @endif
            </div>
        @elseif ($canUpdate)
            <button type="button" wire:click="startEditingDescription"
                    class="w-full rounded-lg border border-dashed border-[var(--line-DEFAULT)] px-3 py-3 text-start
                           text-xs text-[var(--text-subtle)] transition-colors hover:border-[var(--line-strong)]
                           hover:bg-[var(--surface-sunken)] hover:text-[var(--text-muted)]">
                {{ __('Add a description — what does “done” look like?') }}
            </button>
        @else
            <p class="px-2 text-sm text-[var(--text-subtle)]">{{ __('No description.') }}</p>
        @endif
    </section>

    {{-- Checklist ---------------------------------------------------------- --}}
    <section aria-label="{{ __('Checklist') }}">
        <div class="mb-1.5 flex items-center gap-2">
            <h3 class="text-2xs font-semibold uppercase tracking-wider text-[var(--text-subtle)]">{{ __('Checklist') }}</h3>
            @if ($checklist->isNotEmpty())
                <span class="text-2xs tabular-nums text-[var(--text-subtle)]">{{ $checklistDone }}/{{ $checklist->count() }}</span>
                <div class="min-w-0 max-w-32 flex-1">
                    <x-ui.progress :value="$checklistDone" :max="$checklist->count()" size="xs"
                                   :label="__('Checklist progress')" />
                </div>
            @endif
        </div>

        @if ($checklist->isNotEmpty())
            <ul x-data="sortableList('reorderChecklist')" class="space-y-0.5" role="list">
                @foreach ($checklist as $item)
                    <li wire:key="check-{{ $item->getKey() }}" data-sort-id="{{ $item->getKey() }}"
                        class="group flex items-center gap-2 rounded px-1 py-1 hover:bg-[var(--surface-hover)]">

                        @if ($canUpdate)
                            <span data-drag-handle
                                  class="cursor-grab text-[var(--text-subtle)] opacity-0 transition-opacity
                                         group-hover:opacity-100 active:cursor-grabbing" aria-hidden="true">
                                <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor">
                                    <circle cx="7.5" cy="5" r="1.25"/><circle cx="12.5" cy="5" r="1.25"/>
                                    <circle cx="7.5" cy="10" r="1.25"/><circle cx="12.5" cy="10" r="1.25"/>
                                    <circle cx="7.5" cy="15" r="1.25"/><circle cx="12.5" cy="15" r="1.25"/>
                                </svg>
                            </span>
                        @endif

                        <input type="checkbox" @checked($item->is_done) @disabled(! $canUpdate)
                               wire:click="toggleChecklistItem({{ $item->getKey() }})"
                               class="size-3.5 shrink-0 rounded border-[var(--line-strong)] bg-[var(--surface-panel)]
                                      text-[var(--accent)] checked:border-[var(--accent)] checked:bg-[var(--accent)]
                                      focus:ring-2 focus:ring-[var(--accent-ring)]"
                               aria-label="{{ $item->title }}">

                        @if ($renamingChecklistId === (int) $item->getKey())
                            <input type="text" wire:model="renamingChecklistTitle"
                                   value="{{ $renamingChecklistTitle }}"
                                   wire:blur="saveChecklistItem" wire:keydown.enter="saveChecklistItem"
                                   maxlength="255" autofocus
                                   class="h-6 min-w-0 flex-1 rounded border border-[var(--accent)] bg-[var(--surface-panel)]
                                          px-1.5 text-sm text-[var(--text-strong)] focus:outline-none focus:ring-2
                                          focus:ring-[var(--accent-ring)]"
                                   aria-label="{{ __('Checklist item') }}">
                        @else
                            <button type="button"
                                    @if ($canUpdate) wire:click="startRenamingChecklistItem({{ $item->getKey() }})" @endif
                                    class="min-w-0 flex-1 truncate text-start text-sm
                                           {{ $item->is_done ? 'text-[var(--text-subtle)] line-through' : 'text-[var(--text-DEFAULT)]' }}"
                                    @disabled(! $canUpdate)>
                                {{ $item->title }}
                            </button>
                        @endif

                        @if ($canUpdate)
                            <button type="button" wire:click="deleteChecklistItem({{ $item->getKey() }})"
                                    class="grid size-5 shrink-0 place-items-center rounded text-[var(--text-subtle)]
                                           opacity-0 transition-colors hover:bg-critical-50 hover:text-critical-600
                                           focus-visible:opacity-100 group-hover:opacity-100 dark:hover:bg-critical-950"
                                    aria-label="{{ __('Delete :item', ['item' => $item->title]) }}">
                                <x-icon.trash class="size-3.5" />
                            </button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($canUpdate)
            <form wire:submit="addChecklistItem" class="mt-1.5 flex items-center gap-1.5">
                <div class="min-w-0 flex-1">
                    <x-ui.input wire:model="newChecklistTitle" :value="$newChecklistTitle" size="sm" maxlength="255"
                                :placeholder="__('Add a checklist item…')" :aria-label="__('New checklist item')" />
                </div>
                <x-ui.button type="submit" size="sm" variant="secondary" icon-only icon="icon.plus"
                             :aria-label="__('Add checklist item')" />
            </form>
        @elseif ($checklist->isEmpty())
            <p class="text-xs text-[var(--text-subtle)]">{{ __('No checklist.') }}</p>
        @endif
    </section>

    {{-- Subtasks ------------------------------------------------------------ --}}
    <section aria-label="{{ __('Subtasks') }}">
        <h3 class="mb-1.5 text-2xs font-semibold uppercase tracking-wider text-[var(--text-subtle)]">
            {{ __('Subtasks') }}
            @if ($subtasks->isNotEmpty())
                <span class="tabular-nums">· {{ $subtasks->whereNotNull('completed_at')->count() }}/{{ $subtasks->count() }}</span>
            @endif
        </h3>

        @if ($subtasks->isNotEmpty())
            <ul class="divide-y divide-[var(--line-subtle)] overflow-hidden rounded-lg border border-[var(--line-subtle)]"
                role="list">
                @foreach ($subtasks as $subtask)
                    <li wire:key="sub-{{ $subtask->getKey() }}" class="bg-[var(--surface-panel)]">
                        <a href="{{ route('app.tasks.show', [$ws, $subtask]) }}"
                           x-on:click.prevent="$dispatch('open-task', { taskId: {{ $subtask->getKey() }} })"
                           class="flex items-center gap-2 px-2.5 py-1.5 transition-colors hover:bg-[var(--surface-hover)]">
                            <x-ui.status-dot :color="$subtask->status->color ?? 'gray'" />
                            <span class="font-mono text-2xs tabular-nums text-[var(--text-subtle)]"><x-ui.bidi>{{ $subtask->key }}</x-ui.bidi></span>
                            <span class="min-w-0 flex-1 truncate text-sm
                                         {{ $subtask->completed_at ? 'text-[var(--text-subtle)] line-through' : 'text-[var(--text-DEFAULT)]' }}">
                                {{ $subtask->title }}
                            </span>
                            @if ($subtask->assignee)
                                <x-ui.avatar :user="$subtask->assignee" size="xs" />
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($canUpdate)
            <form wire:submit="addSubtask" class="mt-1.5 flex items-center gap-1.5">
                <div class="min-w-0 flex-1">
                    <x-ui.input wire:model="newSubtaskTitle" :value="$newSubtaskTitle" size="sm" maxlength="255"
                                :placeholder="__('Add a subtask…')" :aria-label="__('New subtask')" />
                </div>
                <x-ui.button type="submit" size="sm" variant="secondary" icon-only icon="icon.plus"
                             :aria-label="__('Add subtask')" />
            </form>
        @elseif ($subtasks->isEmpty())
            <p class="text-xs text-[var(--text-subtle)]">{{ __('No subtasks.') }}</p>
        @endif
    </section>

    {{-- Dependencies --------------------------------------------------------- --}}
    <section aria-label="{{ __('Dependencies') }}">
        <h3 class="mb-1.5 text-2xs font-semibold uppercase tracking-wider text-[var(--text-subtle)]">
            {{ __('Dependencies') }}
        </h3>

        @if ($dependencies->isNotEmpty() || $dependents->isNotEmpty())
            <ul class="space-y-1" role="list">
                @foreach ($dependencies as $dependency)
                    @php $other = $dependency->dependsOnTask; @endphp
                    @continue($other === null)
                    <li wire:key="dep-{{ $dependency->getKey() }}"
                        class="group flex items-center gap-2 rounded-md border border-[var(--line-subtle)]
                               bg-[var(--surface-panel)] px-2.5 py-1.5">
                        <x-ui.badge :color="$dependency->type->color()" size="sm">{{ $dependency->type->label() }}</x-ui.badge>
                        <a href="{{ route('app.tasks.show', [$ws, $other]) }}"
                           x-on:click.prevent="$dispatch('open-task', { taskId: {{ $other->getKey() }} })"
                           class="flex min-w-0 flex-1 items-center gap-2">
                            <x-ui.status-dot :color="$other->status->color ?? 'gray'" />
                            <span class="font-mono text-2xs tabular-nums text-[var(--text-subtle)]"><x-ui.bidi>{{ $other->key }}</x-ui.bidi></span>
                            <span class="truncate text-sm text-[var(--text-DEFAULT)]">{{ $other->title }}</span>
                        </a>
                        @if ($canUpdate)
                            <button type="button" wire:click="removeDependency({{ $dependency->getKey() }})"
                                    class="grid size-5 shrink-0 place-items-center rounded text-[var(--text-subtle)]
                                           opacity-0 transition-colors hover:bg-critical-50 hover:text-critical-600
                                           focus-visible:opacity-100 group-hover:opacity-100 dark:hover:bg-critical-950"
                                    aria-label="{{ __('Remove dependency on :key', ['key' => $other->key]) }}">
                                <x-icon.trash class="size-3.5" />
                            </button>
                        @endif
                    </li>
                @endforeach

                @foreach ($dependents as $dependent)
                    @php $other = $dependent->task; @endphp
                    @continue($other === null)
                    <li wire:key="dependent-{{ $dependent->getKey() }}"
                        class="flex items-center gap-2 rounded-md border border-dashed border-[var(--line-subtle)]
                               px-2.5 py-1.5">
                        <x-ui.badge color="gray" size="sm">{{ __('Blocks') }}</x-ui.badge>
                        <a href="{{ route('app.tasks.show', [$ws, $other]) }}"
                           x-on:click.prevent="$dispatch('open-task', { taskId: {{ $other->getKey() }} })"
                           class="flex min-w-0 flex-1 items-center gap-2">
                            <x-ui.status-dot :color="$other->status->color ?? 'gray'" />
                            <span class="font-mono text-2xs tabular-nums text-[var(--text-subtle)]"><x-ui.bidi>{{ $other->key }}</x-ui.bidi></span>
                            <span class="truncate text-sm text-[var(--text-muted)]">{{ $other->title }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($canUpdate)
            <div class="mt-1.5 space-y-1.5">
                <div class="flex items-center gap-1.5">
                    <select wire:model="dependencyType" aria-label="{{ __('Dependency type') }}"
                            class="h-7 shrink-0 rounded-md border border-[var(--line-DEFAULT)] bg-[var(--surface-panel)]
                                   ps-2 pe-6 text-xs text-[var(--text-strong)] focus:border-[var(--accent)]
                                   focus:outline-none focus:ring-2 focus:ring-[var(--accent-ring)]">
                        @foreach (\App\Enums\DependencyType::cases() as $type)
                            <option value="{{ $type->value }}" @selected($dependencyType === $type->value)>{{ $type->label() }}</option>
                        @endforeach
                    </select>

                    <div class="min-w-0 flex-1">
                        <x-ui.input wire:model.live.debounce.300ms="dependencySearch" :value="$dependencySearch" size="sm"
                                    busy-target="dependencySearch"
                                    :placeholder="__('Find a task in this project…')"
                                    :aria-label="__('Search for a task to depend on')" />
                    </div>
                </div>

                {{--
                    Three states, and the third is the one that used to be missing.

                    Between the keystroke and the answer there is a 300ms debounce and a
                    round trip, during which the markup below still describes the *previous*
                    search — so a box that had found nothing went on saying "Nothing else in
                    this project matches" while it was in fact still looking. That is not a
                    slow interface, it is an interface giving a wrong answer confidently.

                    So the result area is swapped for placeholder rows whenever a
                    `dependencySearch` round trip is in flight. `.delay` keeps them from
                    flickering on a fast reply.
                --}}
                <div wire:loading.delay.flex wire:target="dependencySearch"
                     class="hidden rounded-md border border-[var(--line-subtle)] bg-[var(--surface-panel)] py-1">
                    <x-ui.skeleton :rows="3" class="w-full" />
                </div>

                <div wire:loading.delay.remove wire:target="dependencySearch">
                    @if ($this->dependencyCandidates->isNotEmpty())
                        <ul class="overflow-hidden rounded-md border border-[var(--line-subtle)]" role="list">
                            @foreach ($this->dependencyCandidates as $candidate)
                                <li wire:key="cand-{{ $candidate->getKey() }}">
                                    <button type="button" wire:click="addDependency({{ $candidate->getKey() }})"
                                            wire:loading.attr="disabled" wire:target="addDependency"
                                            class="flex w-full items-center gap-2 bg-[var(--surface-panel)] px-2.5 py-1.5
                                                   text-start transition-colors hover:bg-[var(--surface-hover)]
                                                   disabled:opacity-60">
                                        <x-ui.status-dot :color="$candidate->status->color ?? 'gray'" />
                                        <span class="font-mono text-2xs tabular-nums text-[var(--text-subtle)]">{{ $candidate->key }}</span>
                                        <span dir="auto" class="truncate text-sm">{{ $candidate->title }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @elseif (mb_strlen(trim($dependencySearch)) >= 2)
                        <p class="text-xs text-[var(--text-subtle)]">{{ __('Nothing else in this project matches.') }}</p>
                    @endif
                </div>
            </div>
        @elseif ($dependencies->isEmpty() && $dependents->isEmpty())
            <p class="text-xs text-[var(--text-subtle)]">{{ __('No dependencies.') }}</p>
        @endif
    </section>

    {{-- Attachments ---------------------------------------------------------- --}}
    <section aria-label="{{ __('Files') }}">
        <h3 class="mb-1.5 text-2xs font-semibold uppercase tracking-wider text-[var(--text-subtle)]">{{ __('Files') }}</h3>

        @if ($task->attachments->isNotEmpty())
            <ul class="divide-y divide-[var(--line-subtle)] overflow-hidden rounded-lg border border-[var(--line-subtle)]"
                role="list">
                @foreach ($task->attachments as $attachment)
                    <li wire:key="file-{{ $attachment->getKey() }}"
                        class="group flex items-center gap-2 bg-[var(--surface-panel)] px-2.5 py-1.5">
                        <x-icon.paperclip class="size-3.5 shrink-0 text-[var(--text-subtle)]" />
                        <a href="{{ route('attachments.download', $attachment) }}"
                           class="min-w-0 flex-1 truncate text-sm text-[var(--text-DEFAULT)] transition-colors
                                  hover:text-[var(--accent)]">{{ $attachment->original_name }}</a>
                        <span class="shrink-0 text-2xs tabular-nums text-[var(--text-subtle)]">{{ $attachment->human_size }}</span>

                        @can('delete', $attachment)
                            <button type="button" wire:click="deleteAttachment({{ $attachment->getKey() }})"
                                    wire:confirm="{{ __('Delete “:file”? This cannot be undone.', ['file' => $attachment->original_name]) }}"
                                    class="grid size-5 shrink-0 place-items-center rounded text-[var(--text-subtle)]
                                           opacity-0 transition-colors hover:bg-critical-50 hover:text-critical-600
                                           focus-visible:opacity-100 group-hover:opacity-100 dark:hover:bg-critical-950"
                                    aria-label="{{ __('Delete :file', ['file' => $attachment->original_name]) }}">
                                <x-icon.trash class="size-3.5" />
                            </button>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($canAttach)
            <label class="mt-1.5 flex cursor-pointer items-center justify-center gap-2 rounded-lg border border-dashed
                          border-[var(--line-DEFAULT)] px-3 py-2.5 text-xs text-[var(--text-subtle)]
                          transition-colors hover:border-[var(--line-strong)] hover:bg-[var(--surface-sunken)]
                          hover:text-[var(--text-muted)]">
                <x-icon.paperclip class="size-3.5" />
                <span wire:loading.remove wire:target="newFiles">{{ __('Attach files') }}</span>
                <span wire:loading wire:target="newFiles" class="inline-flex items-center gap-1.5">
                    <x-ui.spinner class="size-3.5" /> {{ __('Uploading…') }}
                </span>
                <input type="file" wire:model="newFiles" multiple class="sr-only">
            </label>
        @elseif ($task->attachments->isEmpty())
            <p class="text-xs text-[var(--text-subtle)]">{{ __('No files.') }}</p>
        @endif
    </section>

    {{-- Watchers -------------------------------------------------------------- --}}
    <section aria-label="{{ __('Watchers') }}">
        <div class="flex items-center gap-2">
            <h3 class="text-2xs font-semibold uppercase tracking-wider text-[var(--text-subtle)]">{{ __('Watchers') }}</h3>

            <div class="flex items-center gap-1.5">
                <x-ui.avatar-stack :users="$task->watchers" :max="6" size="xs" />

                @if ($canUpdate)
                    <x-ui.dropdown width="w-60">
                        <x-slot:trigger>
                            <x-ui.button size="xs" variant="ghost" icon="icon.plus" :aria-label="__('Add a watcher')" />
                        </x-slot:trigger>

                        <div class="max-h-64 overflow-y-auto scrollbar-thin">
                            @foreach ($this->taskMembers as $member)
                                @php $watching = $task->watchers->contains('id', $member->getKey()); @endphp
                                <x-ui.dropdown-item
                                    wire:click="{{ $watching ? 'removeWatcher' : 'addWatcher' }}({{ $member->getKey() }})"
                                    :active="$watching">
                                    <span class="flex items-center gap-2 overflow-hidden">
                                        <x-ui.tick :on="$watching" />
                                        <x-ui.avatar :user="$member" size="xs" />
                                        <span dir="auto" class="truncate">{{ $member->name }}</span>
                                    </span>
                                </x-ui.dropdown-item>
                            @endforeach
                        </div>
                    </x-ui.dropdown>
                @endif
            </div>

            <x-ui.button size="xs" variant="{{ $this->isWatching() ? 'soft' : 'ghost' }}" wire:click="toggleWatch"
                         icon="icon.bell" class="ms-auto">
                {{ $this->isWatching() ? __('Watching') : __('Watch') }}
            </x-ui.button>
        </div>
    </section>

    {{-- Conversation and history ------------------------------------------------ --}}
    <section aria-label="{{ __('Conversation') }}">
        <x-ui.tabs variant="pill" class="mb-2">
            <x-ui.tab variant="pill" :active="$detailTab === 'comments'" wire:click="$set('detailTab', 'comments')"
                      :count="$this->comments->count()">{{ __('Comments') }}</x-ui.tab>
            <x-ui.tab variant="pill" :active="$detailTab === 'activity'" wire:click="$set('detailTab', 'activity')">
                {{ __('Activity') }}
            </x-ui.tab>
        </x-ui.tabs>

        @if ($detailTab === 'comments')
            @include('livewire.app.tasks._comments', ['task' => $task, 'canComment' => $canComment])
        @else
            @include('livewire.app.tasks._activity', ['task' => $task])
        @endif
    </section>

    {{-- Delete confirmation ------------------------------------------------------ --}}
    @if ($canDelete)
        <x-ui.modal wire:model="confirmingTaskDeletion" size="sm" :title="__('Delete this task?')"
                    :description="__('Its subtasks, checklist and comments go with it. An administrator can restore it, but it leaves every board and report immediately.')">
            <p class="text-sm text-[var(--text-DEFAULT)]">
                <span class="font-mono text-xs text-[var(--text-subtle)]">{{ $task->key }}</span>
                {{ $task->title }}
            </p>

            <x-slot:footer>
                <x-ui.button variant="secondary" size="md" wire:click="$set('confirmingTaskDeletion', false)">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="danger" size="md" wire:click="deleteTask" wire:target="deleteTask">
                    {{ __('Delete task') }}
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>

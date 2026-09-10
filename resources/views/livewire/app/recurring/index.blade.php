@php
    use App\Support\Formats;
    $rules = $this->rules;
    $preview = $this->preview;
    $problem = $this->previewProblem();
@endphp

<div class="page py-5">

    {{-- ---------------------------------------------------------------- --}}
    {{-- Header                                                           --}}
    {{-- ---------------------------------------------------------------- --}}
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-2xs font-semibold uppercase tracking-widest text-[var(--text-subtle)]">
                {{ $project->name }}
            </p>
            <h1 class="mt-0.5 text-base font-semibold tracking-tight text-[var(--text-strong)]">
                {{ __('Recurring tasks') }}
            </h1>
            <p class="mt-0.5 max-w-2xl text-xs text-[var(--text-muted)]">
                {{ __('Standing instructions. Each one writes a task on its own schedule, with the project\'s own board, people and milestones.') }}
            </p>
        </div>

        <div class="flex items-center gap-1.5">
            <x-ui.button variant="secondary" size="md"
                         :href="route('app.projects.tasks', [$workspace, $project])">
                {{ __('Back to tasks') }}
            </x-ui.button>

            @can('create', [App\Models\RecurringTask::class, $project])
                <x-ui.button variant="primary" size="md" icon="icon.plus" wire:click="create">
                    {{ __('New recurrence') }}
                </x-ui.button>
            @endcan
        </div>
    </header>

    <div class="mt-4 grid gap-4 {{ $showingForm ? 'lg:grid-cols-[minmax(0,1fr)_24rem]' : '' }}">

        {{-- ============================================================ --}}
        {{-- The list                                                     --}}
        {{-- ============================================================ --}}
        <div class="min-w-0">
            @if ($rules->isEmpty())
                <x-ui.card flush>
                    <x-ui.empty-state icon="icon.clock"
                                      :title="__('Nothing repeats here yet')"
                                      :description="__('A recurrence is for the work that comes back: the Monday stand-up note, the monthly invoice run, the quarterly review. Planvio writes the task on the day, so nobody has to remember to.')">
                        <x-slot:actions>
                            @can('create', [App\Models\RecurringTask::class, $project])
                                <x-ui.button variant="primary" size="sm" icon="icon.plus" wire:click="create">
                                    {{ __('Create the first one') }}
                                </x-ui.button>
                            @endcan
                        </x-slot:actions>
                    </x-ui.empty-state>
                </x-ui.card>
            @else
                <ul class="space-y-2">
                    @foreach ($rules as $rule)
                        @php
                            $template = App\Actions\Recurring\RecurringTaskTemplate::fromArray($rule->template ?? []);
                            $next = $this->upcomingFor($rule, 3);
                            $editing = $editingId === (int) $rule->getKey();
                        @endphp

                        <li wire:key="rule-{{ $rule->getKey() }}">
                            <div class="rounded-lg border bg-[var(--surface-panel)] p-3 shadow-panel transition-colors
                                        {{ $editing ? 'border-[var(--accent)]' : 'border-[var(--line-subtle)]' }}
                                        {{ $rule->is_active ? '' : 'opacity-75' }}">

                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <h2 class="truncate text-sm font-semibold text-[var(--text-strong)]">
                                                {{ $template->title !== '' ? $template->title : __('Untitled recurrence') }}
                                            </h2>
                                            @if ($rule->is_active)
                                                <x-ui.badge color="green" size="sm" dot>{{ __('Active') }}</x-ui.badge>
                                            @else
                                                <x-ui.badge color="gray" size="sm" dot>{{ __('Paused') }}</x-ui.badge>
                                            @endif
                                            <x-ui.badge :color="$template->priority->color()" size="sm">
                                                {{ $template->priority->label() }}
                                            </x-ui.badge>
                                        </div>

                                        <p class="mt-1 text-xs text-[var(--text-muted)]">
                                            {{ $this->summarise($rule) }}
                                            <span class="text-[var(--text-subtle)]">
                                                · {{ __('from :date', ['date' => Formats::date($rule->starts_on, '')]) }}
                                                @if ($rule->ends_on)
                                                    {{ __('until :date', ['date' => Formats::date($rule->ends_on)]) }}
                                                @endif
                                                @if ($rule->max_occurrences)
                                                    · {{ __(':done of :max created', [
                                                        'done' => $rule->occurrences_generated,
                                                        'max' => $rule->max_occurrences,
                                                    ]) }}
                                                @elseif ($rule->occurrences_generated > 0)
                                                    · {{ trans_choice('{1}:count task created|[2,*]:count tasks created', $rule->occurrences_generated, ['count' => $rule->occurrences_generated]) }}
                                                @endif
                                            </span>
                                        </p>
                                    </div>

                                    <div class="flex shrink-0 items-center gap-1">
                                        @can('update', $rule)
                                            <x-ui.button size="sm" variant="ghost" wire:click="edit({{ $rule->getKey() }})">
                                                {{ __('Edit') }}
                                            </x-ui.button>
                                        @endcan
                                        @can('toggle', $rule)
                                            <x-ui.button size="sm" variant="secondary"
                                                         wire:click="toggleActive({{ $rule->getKey() }})">
                                                {{ $rule->is_active ? __('Pause') : __('Resume') }}
                                            </x-ui.button>
                                        @endcan
                                        @can('delete', $rule)
                                            <x-ui.button size="sm" variant="danger-ghost" icon-only icon="icon.trash"
                                                         :aria-label="__('Delete this recurrence')"
                                                         wire:click="confirmDelete({{ $rule->getKey() }})" />
                                        @endcan
                                    </div>
                                </div>

                                <div class="mt-2.5 flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-[var(--line-subtle)] pt-2 text-2xs">
                                    <span class="text-[var(--text-subtle)]">
                                        {{ __('Next') }}
                                        @if (! $rule->is_active)
                                            <span class="text-[var(--text-muted)]">{{ __('paused') }}</span>
                                        @elseif ($next === [])
                                            <span class="text-[var(--text-muted)]">{{ __('never again') }}</span>
                                        @else
                                            @foreach ($next as $date)
                                                <span class="ms-1 rounded bg-[var(--surface-sunken)] px-1.5 py-0.5 tabular-nums text-[var(--text-DEFAULT)]">
                                                    {{ $date->translatedFormat('D j M') }}
                                                </span>
                                            @endforeach
                                        @endif
                                    </span>

                                    @if ($rule->creator)
                                        <span class="text-[var(--text-subtle)]">
                                            {{ __('Set up by :name', ['name' => $rule->creator->name]) }}
                                        </span>
                                    @endif
                                </div>

                                @if ($confirmingDeleteId === (int) $rule->getKey())
                                    <div class="mt-2.5 rounded-md border border-critical-500/40 bg-critical-50 p-2.5
                                                dark:bg-critical-950">
                                        <p class="text-xs text-critical-700 dark:text-critical-100">
                                            {{ __('Delete this recurrence? It will stop writing tasks. The :count it already created stay exactly where they are.', [
                                                'count' => trans_choice('{0}tasks|{1}one task|[2,*]:value tasks', $rule->tasks_count, ['value' => $rule->tasks_count]),
                                            ]) }}
                                        </p>
                                        <div class="mt-2 flex items-center gap-1.5">
                                            <x-ui.button size="sm" variant="danger" wire:click="delete">{{ __('Delete') }}</x-ui.button>
                                            <x-ui.button size="sm" variant="ghost" wire:click="cancelDelete">{{ __('Keep it') }}</x-ui.button>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- ============================================================ --}}
        {{-- The form                                                     --}}
        {{-- ============================================================ --}}
        @if ($showingForm)
            <aside class="min-w-0">
                <div class="rounded-lg border border-[var(--line-subtle)] bg-[var(--surface-panel)] shadow-panel
                            lg:sticky lg:top-4">
                    <div class="flex items-start justify-between gap-2 border-b border-[var(--line-subtle)] px-4 py-3">
                        <h2 class="text-sm font-semibold text-[var(--text-strong)]">
                            {{ $editingId === null ? __('New recurrence') : __('Edit recurrence') }}
                        </h2>
                        <button type="button" wire:click="cancel"
                                class="-me-1 grid size-6 place-items-center rounded text-[var(--text-subtle)]
                                       transition-colors hover:bg-[var(--surface-hover)] hover:text-[var(--text-DEFAULT)]"
                                aria-label="{{ __('Close') }}">
                            <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75">
                                <path d="m5 5 10 10M15 5 5 15" stroke-linecap="round"/>
                            </svg>
                        </button>
                    </div>

                    <form wire:submit="save" class="space-y-3.5 px-4 py-3">

                        {{-- The task it writes ---------------------------------- --}}
                        <fieldset class="space-y-3">
                            <legend class="text-2xs font-semibold uppercase tracking-wide text-[var(--text-subtle)]">
                                {{ __('The task it writes') }}
                            </legend>

                            <x-ui.field :label="__('Title')" for="rec-title" required :error="$errors->first('title')">
                                <x-ui.input id="rec-title" wire:model="title" size="md" maxlength="255"
                                            :placeholder="__('Weekly status note')" />
                            </x-ui.field>

                            <x-ui.field :label="__('Description')" for="rec-description" :error="$errors->first('description')">
                                <x-ui.textarea id="rec-description" wire:model="description" rows="2"
                                               :placeholder="__('Optional. Copied onto every occurrence.')" />
                            </x-ui.field>

                            <div class="grid grid-cols-2 gap-2">
                                <x-ui.field :label="__('Priority')" for="rec-priority">
                                    <x-ui.select id="rec-priority" size="md" wire:model="priority"
                                                 :options="$this->priorityOptions()" />
                                </x-ui.field>

                                <x-ui.field :label="__('Column')" for="rec-status">
                                    <x-ui.select id="rec-status" size="md" wire:model="statusId"
                                                 :placeholder="__('Board default')">
                                        @foreach ($this->statusOptions as $status)
                                            <option value="{{ $status->getKey() }}">{{ $status->name }}</option>
                                        @endforeach
                                    </x-ui.select>
                                </x-ui.field>

                                <x-ui.field :label="__('Assignee')" for="rec-assignee">
                                    <x-ui.select id="rec-assignee" size="md" wire:model="assigneeId"
                                                 :placeholder="__('Nobody')">
                                        @foreach ($this->memberOptions as $member)
                                            <option value="{{ $member->getKey() }}">{{ $member->name }}</option>
                                        @endforeach
                                    </x-ui.select>
                                </x-ui.field>

                                <x-ui.field :label="__('Milestone')" for="rec-milestone">
                                    <x-ui.select id="rec-milestone" size="md" wire:model="milestoneId"
                                                 :placeholder="__('None')">
                                        @foreach ($this->milestoneOptions as $milestone)
                                            <option value="{{ $milestone->getKey() }}">{{ $milestone->name }}</option>
                                        @endforeach
                                    </x-ui.select>
                                </x-ui.field>

                                <x-ui.field :label="__('Estimate (hours)')" for="rec-estimate" :error="$errors->first('estimate')">
                                    <x-ui.input id="rec-estimate" wire:model="estimate" type="number" step="0.25" min="0" size="md" />
                                </x-ui.field>

                                <x-ui.field :label="__('Due after (days)')" for="rec-offset"
                                            :hint="__('Blank for no due date.')" :error="$errors->first('dueDayOffset')">
                                    <x-ui.input id="rec-offset" wire:model="dueDayOffset" type="number" min="0" size="md" />
                                </x-ui.field>
                            </div>
                        </fieldset>

                        {{-- The schedule ---------------------------------------- --}}
                        <fieldset class="space-y-3 border-t border-[var(--line-subtle)] pt-3">
                            <legend class="text-2xs font-semibold uppercase tracking-wide text-[var(--text-subtle)]">
                                {{ __('When it fires') }}
                            </legend>

                            <div class="flex items-end gap-2">
                                <x-ui.field :label="__('Repeats')" for="rec-frequency" class="flex-1">
                                    <x-ui.select id="rec-frequency" size="md" wire:model.live="frequency"
                                                 :options="$this->frequencyOptions()" />
                                </x-ui.field>

                                <x-ui.field :label="__('Every')" for="rec-interval" class="w-28"
                                            :error="$errors->first('interval')">
                                    <div class="flex items-center gap-1.5">
                                        <x-ui.input id="rec-interval" wire:model.live="interval" type="number"
                                                    min="1" max="365" size="md" class="w-16" />
                                        <span class="whitespace-nowrap text-xs text-[var(--text-muted)]">{{ $this->intervalUnit() }}</span>
                                    </div>
                                </x-ui.field>
                            </div>

                            @if ($this->showsWeekdays())
                                <div>
                                    <p class="text-xs font-medium text-[var(--text-DEFAULT)]">{{ __('On these days') }}</p>
                                    <div class="mt-1.5 flex flex-wrap gap-1">
                                        @foreach (range(1, 7) as $iso)
                                            @php $on = in_array($iso, $weekdays, true); @endphp
                                            <button type="button" wire:click="toggleWeekday({{ $iso }})"
                                                    wire:key="weekday-{{ $iso }}"
                                                    aria-pressed="{{ $on ? 'true' : 'false' }}"
                                                    class="h-7 min-w-9 rounded-md border px-2 text-xs font-medium transition-colors
                                                           {{ $on
                                                                ? 'border-transparent bg-[var(--accent)] text-white'
                                                                : 'border-[var(--line-DEFAULT)] bg-[var(--surface-panel)] text-[var(--text-muted)] hover:border-[var(--line-strong)]' }}">
                                                {{ $this->weekdayLabel($iso) }}
                                            </button>
                                        @endforeach
                                    </div>
                                    @if ($weekdays === [])
                                        <p class="mt-1 text-2xs text-[var(--text-muted)]">
                                            {{ __('None chosen — it will use the weekday the start date falls on.') }}
                                        </p>
                                    @endif
                                </div>
                            @endif

                            @if ($this->showsMonthdays())
                                <div>
                                    <p class="text-xs font-medium text-[var(--text-DEFAULT)]">{{ __('On these dates') }}</p>
                                    <div class="mt-1.5 grid grid-cols-7 gap-1">
                                        @foreach (range(1, 31) as $day)
                                            @php $on = in_array($day, $monthdays, true); @endphp
                                            <button type="button" wire:click="toggleMonthday({{ $day }})"
                                                    wire:key="monthday-{{ $day }}"
                                                    aria-pressed="{{ $on ? 'true' : 'false' }}"
                                                    class="h-7 rounded-md border text-2xs font-medium tabular-nums transition-colors
                                                           {{ $on
                                                                ? 'border-transparent bg-[var(--accent)] text-white'
                                                                : 'border-[var(--line-DEFAULT)] bg-[var(--surface-panel)] text-[var(--text-muted)] hover:border-[var(--line-strong)]' }}">
                                                {{ $day }}
                                            </button>
                                        @endforeach
                                    </div>
                                    <p class="mt-1 text-2xs text-[var(--text-muted)]">
                                        {{ __('A date a month does not have lands on that month\'s last day: the 31st becomes the 30th in April.') }}
                                    </p>
                                </div>
                            @endif

                            <div class="grid grid-cols-2 gap-2">
                                <x-ui.field :label="__('Starts')" for="rec-starts" required :error="$errors->first('startsOn')">
                                    <x-ui.input id="rec-starts" type="date" size="md" wire:model.live="startsOn" />
                                </x-ui.field>

                                <x-ui.field :label="__('Ends')" for="rec-ends" :error="$errors->first('endsOn')">
                                    <x-ui.input id="rec-ends" type="date" size="md" wire:model.live="endsOn" />
                                </x-ui.field>
                            </div>

                            <x-ui.field :label="__('Stop after')" for="rec-max"
                                        :hint="__('Occurrences. Blank means it keeps going.')"
                                        :error="$errors->first('maxOccurrences')">
                                <x-ui.input id="rec-max" type="number" min="1" size="md" wire:model.live="maxOccurrences" />
                            </x-ui.field>

                            <x-ui.checkbox wire:model.live="isActive" :label="__('Active')"
                                           :description="__('A paused rule keeps its place and writes nothing.')" />
                        </fieldset>

                        {{-- The preview ----------------------------------------- --}}
                        <div class="rounded-lg border border-[var(--line-subtle)] bg-[var(--surface-sunken)] p-3">
                            <p class="flex items-center gap-1.5 text-xs font-medium text-[var(--text-strong)]">
                                <x-icon.calendar class="size-3.5 text-[var(--text-subtle)]" />
                                {{ __('Next five occurrences') }}
                            </p>

                            @if ($preview === [])
                                <p class="mt-1.5 text-2xs text-[var(--text-muted)]">{{ $problem }}</p>
                            @else
                                <ol class="mt-2 space-y-1">
                                    @foreach ($preview as $index => $date)
                                        <li class="flex items-baseline gap-2 text-xs" wire:key="preview-{{ $index }}">
                                            <span class="w-4 shrink-0 text-2xs tabular-nums text-[var(--text-subtle)]">{{ $index + 1 }}</span>
                                            <span class="font-medium tabular-nums text-[var(--text-DEFAULT)]">
                                                {{ $date->translatedFormat('D j M Y') }}
                                            </span>
                                            <span class="text-2xs text-[var(--text-subtle)]">{{ $date->diffForHumans() }}</span>
                                        </li>
                                    @endforeach
                                </ol>
                                <p class="mt-2 text-2xs text-[var(--text-subtle)]">
                                    {{ __('Computed by the same calculator the scheduler runs.') }}
                                </p>
                            @endif

                            @error('frequency')
                                <p class="mt-2 text-2xs text-critical-600" role="alert">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex items-center justify-end gap-1.5 border-t border-[var(--line-subtle)] pt-3">
                            <x-ui.button type="button" size="md" variant="ghost" wire:click="cancel">{{ __('Cancel') }}</x-ui.button>
                            <x-ui.button type="submit" size="md" variant="primary" wire:loading.attr="disabled" wire:target="save">
                                {{ $editingId === null ? __('Create recurrence') : __('Save changes') }}
                            </x-ui.button>
                        </div>
                    </form>
                </div>
            </aside>
        @endif
    </div>
</div>

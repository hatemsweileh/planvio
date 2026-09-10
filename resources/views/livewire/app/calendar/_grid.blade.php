@php
    /**
     * The calendar surface, shared by the workspace and project screens.
     *
     * Expects `$scopedProject` (a Project or null) and reads everything else off the
     * component: the grid, the filter option lists and the drawer task are all computed
     * properties, so a re-render after a drop costs one round trip and no duplicated
     * query.
     */
    $calendar = $this->calendar;
    $filtered = $this->projectFilter !== '' || $this->assigneeFilter !== '' || $this->typeFilter !== 'all';
    $canCreate = $scopedProject
        ? auth()->user()?->can('task.create', $scopedProject)
        : auth()->user()?->can('task.create', $workspace);

    /*
     * The two chevrons carry `flip-rtl`, so in Arabic "previous" points right and "next"
     * points left — which is correct, because a month grid runs in reading order and later
     * days are to the left. What a key press means is the way the period visibly moves, not
     * the sign of the arithmetic, so the bindings and the shortcut hints trade places with
     * the glyphs. Leaving them alone told an Arabic reader "←" beside a button pointing the
     * other way, and moved the calendar away from the arrow being pressed.
     */
    $isRtl = ($textDirection ?? 'ltr') === 'rtl';
    $previousKey = $isRtl ? 'ArrowRight' : 'ArrowLeft';
    $nextKey = $isRtl ? 'ArrowLeft' : 'ArrowRight';
    $previousGlyph = $isRtl ? '→' : '←';
    $nextGlyph = $isRtl ? '←' : '→';
    $previousArrowName = $isRtl ? __('right arrow') : __('left arrow');
    $nextArrowName = $isRtl ? __('left arrow') : __('right arrow');
@endphp

<div class="flex min-h-0 flex-1 flex-col"
     x-data="{
        pick(event) {
            const day = event.currentTarget.dataset.day;
            const id = event.dataTransfer.getData('text/plain');
            if (id) { $wire.moveTask(id, day); }
        },
        /*
         * Local navigation keys. Deliberately inert while somebody is typing and while a
         * modifier is held, so they never fight a text field or the shell's own bindings
         * (which own c, a, / and ?). Alt + arrow belongs to the focused chip, so the plain
         * arrows are free to move the period.
         */
        key(event) {
            const el = event.target;
            if (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName)) return;
            if (event.metaKey || event.ctrlKey || event.altKey) return;

            const actions = {
                '{{ $previousKey }}': 'goPrevious',
                '{{ $nextKey }}': 'goNext',
            };

            if (actions[event.key]) {
                event.preventDefault();
                $wire.call(actions[event.key]);
                return;
            }

            const modes = { m: 'month', w: 'week', d: 'day' };
            const pressed = event.key.toLowerCase();

            if (pressed === 't') {
                event.preventDefault();
                $wire.call('goToToday');
            } else if (modes[pressed]) {
                event.preventDefault();
                $wire.call('setMode', modes[pressed]);
            }
        },
     }"
     x-on:keydown.window="key($event)">

    {{-- ---------------------------------------------------------------- --}}
    {{-- Toolbar                                                          --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="flex flex-wrap items-center gap-2 border-b border-[var(--line-subtle)]
                bg-[var(--surface-panel)] px-3 py-2 sm:px-4">

        <div class="flex items-center gap-1">
            <x-ui.button variant="secondary" size="md" icon-only wire:click="goPrevious"
                         :aria-label="__('Previous period').' — '.$previousArrowName"
                         :title="__('Previous').' ('.$previousGlyph.')'">
                <svg class="size-4 flip-rtl" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path d="M12 5 7 10l5 5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </x-ui.button>
            <x-ui.button variant="secondary" size="md" wire:click="goToToday"
                         :title="__('Today').' (T)'">{{ __('Today') }}</x-ui.button>
            <x-ui.button variant="secondary" size="md" icon-only wire:click="goNext"
                         :aria-label="__('Next period').' — '.$nextArrowName"
                         :title="__('Next').' ('.$nextGlyph.')'">
                <svg class="size-4 flip-rtl" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path d="m8 5 5 5-5 5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </x-ui.button>
        </div>

        <div class="min-w-0 flex-1">
            <h2 class="truncate text-sm font-semibold tracking-tight text-[var(--text-strong)]"
                aria-live="polite">
                {{ $calendar['title'] }}
            </h2>
            <p class="truncate text-2xs text-[var(--text-muted)]">
                {{ $calendar['subtitle'] }}
                <span class="text-[var(--text-subtle)]">· {{ $this->timezone() }}</span>
            </p>
        </div>

        <div wire:loading.delay class="text-[var(--text-subtle)]" wire:target="goPrevious,goNext,goToToday,setMode,goToDay,moveTask">
            <x-ui.spinner class="size-4" />
        </div>

        <x-ui.tabs variant="pill" class="shrink-0" :aria-label="__('Calendar view')">
            @foreach ([['month', __('Month')], ['week', __('Week')], ['day', __('Day')]] as [$value, $label])
                <x-ui.tab variant="pill" :active="$this->mode === $value" wire:click="setMode('{{ $value }}')"
                          wire:key="calendar-mode-{{ $value }}"
                          title="{{ $label }} ({{ mb_strtoupper(mb_substr($value, 0, 1)) }})">{{ $label }}</x-ui.tab>
            @endforeach
        </x-ui.tabs>

        <div class="flex flex-wrap items-center gap-1.5">
            @unless ($scopedProject)
                <label class="sr-only" for="calendar-project">{{ __('Project') }}</label>
                <x-ui.select id="calendar-project" size="sm" wire:model.live="projectFilter"
                             :options="$this->projectOptions" class="w-36 sm:w-44" />
            @endunless

            <label class="sr-only" for="calendar-assignee">{{ __('Assignee') }}</label>
            <x-ui.select id="calendar-assignee" size="sm" wire:model.live="assigneeFilter"
                         :options="$this->assigneeOptions" class="w-32 sm:w-40" />

            <label class="sr-only" for="calendar-type">{{ __('Item type') }}</label>
            <x-ui.select id="calendar-type" size="sm" wire:model.live="typeFilter" class="w-28 sm:w-36"
                         :options="[
                            'all' => __('Everything'),
                            'tasks' => __('Tasks'),
                            'milestones' => __('Milestones'),
                            'projects' => __('Project dates'),
                         ]" />
        </div>
    </div>

    @if ($calendar['truncated'])
        <p class="border-b border-[var(--line-subtle)] bg-caution-50 px-4 py-1.5 text-2xs
                  text-caution-700 dark:bg-caution-950 dark:text-caution-100">
            {{ __('This period holds more than :count dated items. Narrow the filters to see the rest.', ['count' => $calendar['total']]) }}
        </p>
    @endif

    {{-- ---------------------------------------------------------------- --}}
    {{-- Grid                                                             --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="scrollbar-thin min-h-0 flex-1 overflow-auto"
         wire:loading.class="opacity-60"
         wire:target="goPrevious,goNext,goToToday,setMode,goToDay,projectFilter,assigneeFilter,typeFilter">

        @if ($calendar['total'] === 0)
            <x-ui.empty-state icon="icon.calendar"
                              :title="__('Nothing scheduled in :period', ['period' => $calendar['title']])"
                              :description="$filtered
                                ? __('No task, milestone or project date in this period matches the filters you have set.')
                                : __('Tasks and milestones appear here on the day they are due, and project start and target dates appear alongside them.')">
                <x-slot:actions>
                    @if ($filtered)
                        <x-ui.button variant="secondary" size="md" wire:click="clearFilters">
                            {{ __('Clear filters') }}
                        </x-ui.button>
                    @endif
                    @if ($canCreate)
                        <x-ui.button variant="primary" size="md" icon="icon.plus"
                                     x-on:click="$dispatch('open-quick-create', { type: 'task'{{ $scopedProject ? ', project: '.$scopedProject->getKey() : '' }} })">
                            {{ __('New task') }}
                        </x-ui.button>
                    @endif
                    <x-ui.button variant="ghost" size="md" wire:click="goToToday">{{ __('Back to today') }}</x-ui.button>
                </x-slot:actions>
            </x-ui.empty-state>
        @else
            {{-- Month and week, from the medium breakpoint up. Seven columns of 50px on a
                 phone is a grid nobody can read, so below that the same data is an agenda. --}}
            @if ($calendar['columns'] === 7)
                <div class="hidden min-w-[44rem] md:block">
                    <div class="sticky top-0 z-10 grid grid-cols-7 border-b border-[var(--line-subtle)]
                                bg-[var(--surface-panel)]">
                        @foreach ($calendar['weekdays'] as $weekday)
                            <div class="px-2 py-1.5 text-2xs font-semibold uppercase tracking-wider
                                        {{ $weekday['weekend'] ? 'text-[var(--text-subtle)]' : 'text-[var(--text-muted)]' }}">
                                {{ $weekday['short'] }}
                            </div>
                        @endforeach
                    </div>

                    @foreach ($calendar['rows'] as $rowIndex => $row)
                        <div class="grid grid-cols-7 border-b border-[var(--line-subtle)] last:border-b-0"
                             wire:key="calendar-row-{{ $calendar['from'] }}-{{ $rowIndex }}">
                            @foreach ($row as $day)
                                @include('livewire.app.calendar._cell', [
                                    'day' => $day,
                                    'compact' => $this->mode === 'month',
                                    'scopedProject' => $scopedProject,
                                ])
                            @endforeach
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Agenda: the day view everywhere, and month/week on a phone. --}}
            <div class="{{ $calendar['columns'] === 7 ? 'md:hidden' : '' }} divide-y divide-[var(--line-subtle)]">
                @foreach ($calendar['rows'] as $row)
                    @foreach ($row as $day)
                        @continue($day['events'] === [] && ! $day['isToday'])
                        @continue(! $day['inScope'])

                        <section class="px-3 py-2.5 sm:px-4" wire:key="agenda-{{ $day['date'] }}"
                                 data-day="{{ $day['date'] }}"
                                 x-on:dragover.prevent
                                 x-on:drop.prevent="pick($event)">
                            <h3 class="flex items-baseline gap-2">
                                <span class="text-sm font-semibold {{ $day['isToday'] ? 'text-[var(--accent)]' : 'text-[var(--text-strong)]' }}">
                                    {{ $day['label'] }}
                                </span>
                                <span class="text-2xs text-[var(--text-muted)]">{{ $day['weekdayLong'] }}</span>
                                @if ($day['isToday'])
                                    <x-ui.badge color="brand" size="sm">{{ __('Today') }}</x-ui.badge>
                                @endif
                            </h3>

                            @if ($day['events'] === [])
                                <p class="mt-1 text-xs text-[var(--text-subtle)]">{{ __('Nothing due.') }}</p>
                            @else
                                <ul class="mt-1.5 space-y-1">
                                    {{-- The same cap the month cell uses. Both layouts are in
                                         the DOM at once — one of them hidden by a media query —
                                         so an unbounded agenda would double the cost of a busy
                                         month for a list nobody on a wide screen ever sees. --}}
                                    @foreach ($day['visible'] as $item)
                                        <li wire:key="agenda-{{ $day['date'] }}-{{ $item['type'] }}-{{ $item['id'] }}-{{ $item['kind'] ?? 'x' }}">
                                            @include('livewire.app.calendar._event', [
                                                'item' => $item,
                                                'dense' => false,
                                            ])
                                        </li>
                                    @endforeach
                                </ul>

                                @if ($day['overflow'] > 0)
                                    <button type="button" wire:click="goToDay('{{ $day['date'] }}')"
                                            class="mt-1 rounded px-1.5 py-0.5 text-xs font-medium text-[var(--accent)]
                                                   transition-colors hover:bg-[var(--surface-hover)]">
                                        {{ trans_choice('{1}+:count more|[2,*]+:count more', $day['overflow'], ['count' => $day['overflow']]) }}
                                    </button>
                                @endif
                            @endif
                        </section>
                    @endforeach
                @endforeach
            </div>
        @endif
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Task drawer                                                      --}}
    {{-- ---------------------------------------------------------------- --}}
    @include('livewire.app.calendar._drawer')
</div>

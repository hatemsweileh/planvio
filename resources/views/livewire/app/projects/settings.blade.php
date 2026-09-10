@php
    // Keyed by the component's own SECTIONS constant so the nav and the guard cannot drift.
    $sectionLabels = [
        'general' => [__('General'), 'icon.cog'],
        'statuses' => [__('Board columns'), 'icon.board'],
        'members' => [__('Members'), 'icon.users'],
        'tags' => [__('Tags'), 'icon.tag'],
        'fields' => [__('Custom fields'), 'icon.list'],
        'danger' => [__('Danger zone'), 'icon.warning'],
    ];

    $palette = \App\Livewire\App\Projects\Settings::STATUS_COLORS;
    $swatches = \App\Livewire\App\Projects\Create::COLORS;
    $emojis = \App\Livewire\App\Projects\Create::ICONS;

    $blast = $this->blastRadius;
@endphp

<div>
    <x-app.project-shell :project="$project" :workspace="$workspace" current="settings"
                         :favourite="$this->isFavourite" />

    <div class="page py-5">
        <div class="flex items-baseline justify-between gap-3">
            <h2 class="text-base font-semibold tracking-tight text-[var(--text-strong)]">
                {{ __('Project settings') }}
            </h2>
            <a href="{{ route('app.projects.show', [$workspace, $project]) }}"
               class="text-xs text-[var(--accent)] hover:underline">{{ __('Back to overview') }}</a>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-[13rem_1fr]">

            {{-- ------------------------------------------------------------ --}}
            {{-- Section nav                                                   --}}
            {{-- ------------------------------------------------------------ --}}
            <nav class="scrollbar-thin -mx-4 flex gap-1 overflow-x-auto px-4 lg:mx-0 lg:flex-col lg:px-0"
                 aria-label="{{ __('Settings sections') }}">
                @foreach (\App\Livewire\App\Projects\Settings::SECTIONS as $key)
                    @php
                        [$sectionLabel, $sectionIcon] = $sectionLabels[$key];
                        $isCurrent = $section === $key;
                    @endphp
                    <button type="button"
                            wire:click="$set('section', '{{ $key }}')"
                            aria-current="{{ $isCurrent ? 'page' : 'false' }}"
                            class="flex h-8 shrink-0 items-center gap-2 rounded-md px-2.5 text-sm transition-colors
                                   {{ $isCurrent
                                        ? 'bg-[var(--accent-soft)] font-medium text-[var(--accent-soft-text)]'
                                        : 'text-[var(--text-muted)] hover:bg-[var(--surface-hover)] hover:text-[var(--text-DEFAULT)]' }}
                                   {{ $key === 'danger' && ! $isCurrent ? 'text-critical-600' : '' }}">
                        <x-dynamic-component :component="$sectionIcon" class="size-4 shrink-0" />
                        <span class="whitespace-nowrap">{{ $sectionLabel }}</span>
                    </button>
                @endforeach
            </nav>

            <div class="min-w-0">

                {{-- ======================================================== --}}
                {{-- General                                                  --}}
                {{-- ======================================================== --}}
                @if ($section === 'general')
                    <form wire:submit="saveGeneral" class="space-y-4">

                        <x-ui.card :title="__('Identity')">
                            <div class="space-y-3">
                                <div class="grid gap-3 sm:grid-cols-[1fr_10rem]">
                                    <x-ui.field :label="__('Name')" for="settings-name" required :error="$errors->first('name')">
                                        <x-ui.input id="settings-name" wire:model="name" :invalid="$errors->has('name')" />
                                    </x-ui.field>

                                    <x-ui.field :label="__('Key')" for="settings-key" required
                                                :error="$errors->first('key')"
                                                :hint="$errors->has('key') ? null : __('Changing it renames every task reference.')">
                                        <x-ui.input id="settings-key" wire:model="key" maxlength="12"
                                                    class="font-mono uppercase tracking-wide"
                                                    :invalid="$errors->has('key')" />
                                    </x-ui.field>
                                </div>

                                <x-ui.field :label="__('Description')" for="settings-description"
                                            :error="$errors->first('description')">
                                    <x-ui.textarea id="settings-description" wire:model="description" rows="4"
                                                   :invalid="$errors->has('description')" />
                                </x-ui.field>

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <x-ui.field :label="__('Type')" for="settings-type" :error="$errors->first('type')">
                                        <x-ui.select id="settings-type" wire:model="type">
                                            @foreach (\App\Enums\ProjectType::cases() as $case)
                                                <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                            @endforeach
                                        </x-ui.select>
                                    </x-ui.field>

                                    <x-ui.field :label="__('Priority')" for="settings-priority" :error="$errors->first('priority')">
                                        <x-ui.select id="settings-priority" wire:model="priority">
                                            @foreach (\App\Enums\Priority::cases() as $case)
                                                <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                            @endforeach
                                        </x-ui.select>
                                    </x-ui.field>

                                    <x-ui.field :label="__('Stage')" for="settings-stage"
                                                :hint="__('Where the project sits in the workspace lifecycle.')"
                                                :error="$errors->first('statusId')">
                                        <x-ui.select id="settings-stage" wire:model="statusId" :placeholder="__('None')">
                                            @foreach ($this->projectStatuses as $projectStatus)
                                                <option value="{{ $projectStatus->getKey() }}">{{ $projectStatus->name }}</option>
                                            @endforeach
                                        </x-ui.select>
                                    </x-ui.field>

                                    <x-ui.field :label="__('Project manager')" for="settings-manager" :error="$errors->first('managerId')">
                                        <x-ui.select id="settings-manager" wire:model="managerId" :placeholder="__('Nobody')">
                                            @foreach ($this->managers as $person)
                                                <option value="{{ $person->getKey() }}">{{ $person->name }}</option>
                                            @endforeach
                                        </x-ui.select>
                                    </x-ui.field>
                                </div>

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <fieldset>
                                        <legend class="mb-1.5 block text-xs font-medium text-[var(--text-DEFAULT)]">{{ __('Colour') }}</legend>
                                        <div class="flex flex-wrap gap-1.5">
                                            @foreach ($swatches as $swatch)
                                                <button type="button" wire:click="$set('color', '{{ $swatch }}')"
                                                        aria-label="{{ $swatch }}"
                                                        aria-pressed="{{ $color === $swatch ? 'true' : 'false' }}"
                                                        class="size-6 rounded-full ring-offset-2 ring-offset-[var(--surface-panel)]
                                                               {{ $color === $swatch ? 'ring-2 ring-[var(--text-strong)]' : 'ring-1 ring-black/10' }}"
                                                        style="background-color: {{ $swatch }}"></button>
                                            @endforeach
                                        </div>
                                        @error('color')
                                            <p class="mt-1 text-xs text-critical-600" role="alert">{{ $message }}</p>
                                        @enderror
                                    </fieldset>

                                    <fieldset>
                                        <legend class="mb-1.5 block text-xs font-medium text-[var(--text-DEFAULT)]">{{ __('Icon') }}</legend>
                                        <div class="flex flex-wrap gap-1">
                                            <button type="button" wire:click="$set('icon', '')"
                                                    aria-label="{{ __('No icon') }}"
                                                    aria-pressed="{{ $icon === '' ? 'true' : 'false' }}"
                                                    class="grid size-7 place-items-center rounded-md border transition-colors
                                                           {{ $icon === ''
                                                                ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent-soft-text)]'
                                                                : 'border-[var(--line-subtle)] text-[var(--text-subtle)] hover:bg-[var(--surface-hover)]' }}">
                                                <x-icon.folder class="size-3.5" />
                                            </button>
                                            @foreach ($emojis as $emoji)
                                                <button type="button" wire:click="$set('icon', '{{ $emoji }}')"
                                                        aria-label="{{ $emoji }}"
                                                        aria-pressed="{{ $icon === $emoji ? 'true' : 'false' }}"
                                                        class="grid size-7 place-items-center rounded-md border text-sm leading-none transition-colors
                                                               {{ $icon === $emoji ? 'border-[var(--accent)] bg-[var(--accent-soft)]' : 'border-transparent hover:bg-[var(--surface-hover)]' }}">
                                                    {{ $emoji }}
                                                </button>
                                            @endforeach
                                        </div>
                                    </fieldset>
                                </div>
                            </div>
                        </x-ui.card>

                        <x-ui.card :title="__('Schedule, budget and client')">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <x-ui.field :label="__('Start date')" for="settings-start" :error="$errors->first('startDate')">
                                    <x-ui.input id="settings-start" type="date" wire:model="startDate" :invalid="$errors->has('startDate')" />
                                </x-ui.field>

                                <x-ui.field :label="__('Target date')" for="settings-target" :error="$errors->first('targetDate')">
                                    <x-ui.input id="settings-target" type="date" wire:model="targetDate" :invalid="$errors->has('targetDate')" />
                                </x-ui.field>

                                <x-ui.field :label="__('Budget')" for="settings-budget" :error="$errors->first('budget')">
                                    <x-ui.input id="settings-budget" type="number" step="0.01" min="0" inputmode="decimal"
                                                wire:model="budget" :invalid="$errors->has('budget')" />
                                </x-ui.field>

                                <x-ui.field :label="__('Currency')" for="settings-currency" :error="$errors->first('currency')">
                                    <x-ui.input id="settings-currency" wire:model="currency" maxlength="3"
                                                class="uppercase-latin" :invalid="$errors->has('currency')" />
                                </x-ui.field>

                                <x-ui.field :label="__('Client')" for="settings-client" :error="$errors->first('clientName')">
                                    <x-ui.input id="settings-client" wire:model="clientName" :invalid="$errors->has('clientName')" />
                                </x-ui.field>

                                <x-ui.field :label="__('Department')" for="settings-department" :error="$errors->first('department')">
                                    <x-ui.input id="settings-department" wire:model="department" :invalid="$errors->has('department')" />
                                </x-ui.field>
                            </div>
                        </x-ui.card>

                        <x-ui.card :title="__('Health')"
                                   :subtitle="__('Planvio measures health from overdue tasks, delayed milestones, workload concentration and the target date.')">
                            <div class="space-y-3">
                                <fieldset class="space-y-2">
                                    <legend class="sr-only">{{ __('How health is decided') }}</legend>
                                    @foreach ([
                                        ['auto', __('Measured automatically'), __('Recalculated from the project\'s own numbers. Recommended.')],
                                        ['manual', __('Set by hand'), __('Your value is shown and never overwritten. The measurement is still displayed beside it.')],
                                    ] as [$mode, $modeLabel, $modeHint])
                                        <label class="flex cursor-pointer items-start gap-2.5 rounded-md border p-2.5 transition-colors
                                                      {{ $healthMode === $mode
                                                           ? 'border-[var(--accent)] bg-[var(--accent-soft)]'
                                                           : 'border-[var(--line-subtle)] hover:bg-[var(--surface-hover)]' }}">
                                            <input type="radio" value="{{ $mode }}" wire:model.live="healthMode"
                                                   name="settings-health-mode"
                                                   class="mt-0.5 size-4 shrink-0 border-[var(--line-strong)] text-[var(--accent)]
                                                          focus:ring-2 focus:ring-[var(--accent-ring)]">
                                            <span class="min-w-0">
                                                <span class="block text-sm font-medium text-[var(--text-strong)]">{{ $modeLabel }}</span>
                                                <span class="block text-xs text-[var(--text-muted)]">{{ $modeHint }}</span>
                                            </span>
                                        </label>
                                    @endforeach
                                </fieldset>

                                @if ($healthMode === 'manual')
                                    <x-ui.field :label="__('Health')" for="settings-health" :error="$errors->first('health')">
                                        <x-ui.select id="settings-health" wire:model="health">
                                            @foreach (\App\Enums\ProjectHealth::cases() as $case)
                                                <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                            @endforeach
                                        </x-ui.select>
                                    </x-ui.field>
                                @endif

                                <x-ui.field :label="__('Note')" for="settings-health-note"
                                            :hint="__('Shown on the overview under “Analysis”, clearly separated from the measured facts.')"
                                            :error="$errors->first('healthNote')">
                                    <x-ui.textarea id="settings-health-note" wire:model="healthNote" rows="2"
                                                   :invalid="$errors->has('healthNote')"
                                                   :placeholder="__('Client signed off late; the schedule absorbs it.')" />
                                </x-ui.field>
                            </div>
                        </x-ui.card>

                        <div class="flex items-center justify-end gap-2">
                            <x-ui.button :href="route('app.projects.show', [$workspace, $project])" variant="secondary" size="md">
                                {{ __('Cancel') }}
                            </x-ui.button>
                            <x-ui.button type="submit" variant="primary" size="md">
                                <span wire:loading.remove wire:target="saveGeneral">{{ __('Save changes') }}</span>
                                <span wire:loading wire:target="saveGeneral" class="inline-flex items-center gap-1.5">
                                    <x-ui.spinner class="size-4" />{{ __('Saving…') }}
                                </span>
                            </x-ui.button>
                        </div>
                    </form>
                @endif

                {{-- ======================================================== --}}
                {{-- Board columns                                            --}}
                {{-- ======================================================== --}}
                @if ($section === 'statuses')
                    <div class="space-y-4">
                        <x-ui.card :title="__('Board columns')"
                                   :subtitle="__('Drag to reorder. Columns in a closed category stop the clock on the tasks in them.')"
                                   flush>
                            <ul x-data="sortableList('reorderStatuses')"
                                class="divide-y divide-[var(--line-subtle)]">
                                @foreach ($this->statuses as $status)
                                    <li wire:key="status-{{ $status->getKey() }}"
                                        data-sort-id="{{ $status->getKey() }}"
                                        class="px-3 py-2.5">

                                        @if ($editingStatusId === (int) $status->getKey())
                                            <div class="space-y-2">
                                                <div class="grid gap-2 sm:grid-cols-[1fr_9rem_8rem]">
                                                    <x-ui.field :label="__('Name')" :for="'status-name-'.$status->getKey()"
                                                                :error="$errors->first('statusName')">
                                                        <x-ui.input :id="'status-name-'.$status->getKey()"
                                                                    wire:model="statusName" :invalid="$errors->has('statusName')" />
                                                    </x-ui.field>

                                                    <x-ui.field :label="__('Category')" :for="'status-category-'.$status->getKey()">
                                                        <x-ui.select :id="'status-category-'.$status->getKey()" wire:model="statusCategory">
                                                            @foreach (\App\Enums\StatusCategory::cases() as $case)
                                                                <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                                            @endforeach
                                                        </x-ui.select>
                                                    </x-ui.field>

                                                    <x-ui.field :label="__('Colour')" :for="'status-color-'.$status->getKey()">
                                                        <x-ui.select :id="'status-color-'.$status->getKey()" wire:model="statusColor">
                                                            @foreach ($palette as $swatchName)
                                                                <option value="{{ $swatchName }}">{{ \App\Support\ChartPalette::label($swatchName) }}</option>
                                                            @endforeach
                                                        </x-ui.select>
                                                    </x-ui.field>
                                                </div>
                                                <div class="flex justify-end gap-2">
                                                    <x-ui.button variant="ghost" size="sm" wire:click="cancelStatusEdit">
                                                        {{ __('Cancel') }}
                                                    </x-ui.button>
                                                    <x-ui.button variant="primary" size="sm" wire:click="saveStatus">
                                                        {{ __('Save column') }}
                                                    </x-ui.button>
                                                </div>
                                            </div>
                                        @else
                                            <div class="flex items-center gap-2.5">
                                                <button type="button" data-drag-handle
                                                        class="cursor-grab text-[var(--text-subtle)] hover:text-[var(--text-muted)] active:cursor-grabbing"
                                                        aria-label="{{ __('Reorder :name', ['name' => $status->name]) }}">
                                                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                        <circle cx="7" cy="5" r="1.3" /><circle cx="13" cy="5" r="1.3" />
                                                        <circle cx="7" cy="10" r="1.3" /><circle cx="13" cy="10" r="1.3" />
                                                        <circle cx="7" cy="15" r="1.3" /><circle cx="13" cy="15" r="1.3" />
                                                    </svg>
                                                </button>

                                                <x-ui.badge :color="in_array($status->color, $palette, true) ? $status->color : 'gray'" size="md" dot>
                                                    {{ $status->name }}
                                                </x-ui.badge>

                                                <span class="text-2xs text-[var(--text-subtle)]">{{ $status->category->label() }}</span>

                                                @if ($status->is_default)
                                                    <x-ui.badge color="brand" size="sm">{{ __('Landing column') }}</x-ui.badge>
                                                @endif

                                                <span class="ms-auto text-xs tabular-nums text-[var(--text-muted)]">
                                                    {{ trans_choice('{0}empty|{1}1 task|[2,*]:count tasks', (int) $status->tasks_count, ['count' => (int) $status->tasks_count]) }}
                                                </span>

                                                <x-ui.dropdown align="end" width="w-52">
                                                    <x-slot:trigger>
                                                        <x-ui.button variant="ghost" size="sm" icon-only
                                                                     :aria-label="__('Actions for :name', ['name' => $status->name])">
                                                            <x-icon.dots class="size-3.5" />
                                                        </x-ui.button>
                                                    </x-slot:trigger>
                                                    <x-ui.dropdown-item icon="icon.cog" wire:click="editStatus({{ $status->getKey() }})">
                                                        {{ __('Rename or recolour') }}
                                                    </x-ui.dropdown-item>
                                                    @unless ($status->is_default)
                                                        <x-ui.dropdown-item icon="icon.check-circle"
                                                                            wire:click="makeStatusDefault({{ $status->getKey() }})">
                                                            {{ __('Make the landing column') }}
                                                        </x-ui.dropdown-item>
                                                    @endunless
                                                    <x-ui.dropdown-separator />
                                                    <x-ui.dropdown-item icon="icon.trash" danger
                                                                        wire:click="deleteStatus({{ $status->getKey() }})"
                                                                        wire:confirm="{{ __('Remove the “:name” column?', ['name' => $status->name]) }}">
                                                        {{ __('Delete column') }}
                                                    </x-ui.dropdown-item>
                                                </x-ui.dropdown>
                                            </div>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </x-ui.card>

                        <x-ui.card :title="__('Add a column')">
                            <form wire:submit="addStatus" class="grid gap-3 sm:grid-cols-[1fr_9rem_8rem_auto] sm:items-end">
                                <x-ui.field :label="__('Name')" for="new-status-name" :error="$errors->first('statusName')">
                                    <x-ui.input id="new-status-name" wire:model="statusName"
                                                :invalid="$errors->has('statusName')" :placeholder="__('In review')" />
                                </x-ui.field>

                                <x-ui.field :label="__('Category')" for="new-status-category">
                                    <x-ui.select id="new-status-category" wire:model="statusCategory">
                                        @foreach (\App\Enums\StatusCategory::cases() as $case)
                                            <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                        @endforeach
                                    </x-ui.select>
                                </x-ui.field>

                                <x-ui.field :label="__('Colour')" for="new-status-color">
                                    <x-ui.select id="new-status-color" wire:model="statusColor">
                                        @foreach ($palette as $swatchName)
                                            <option value="{{ $swatchName }}">{{ \App\Support\ChartPalette::label($swatchName) }}</option>
                                        @endforeach
                                    </x-ui.select>
                                </x-ui.field>

                                <x-ui.button type="submit" variant="secondary" size="md" icon="icon.plus">
                                    {{ __('Add') }}
                                </x-ui.button>
                            </form>
                        </x-ui.card>
                    </div>
                @endif

                {{-- ======================================================== --}}
                {{-- Members                                                  --}}
                {{-- ======================================================== --}}
                @if ($section === 'members')
                    @php $canManage = auth()->user()->can('manageMembers', $project); @endphp

                    <div class="space-y-4">
                        <x-ui.card :title="__('Project members')"
                                   :subtitle="__('A manager can change this project\'s settings, milestones and members. A guest sees only this project.')"
                                   flush>
                            @if ($this->members->isEmpty())
                                <x-ui.empty-state icon="icon.users"
                                                  :title="__('Nobody on the project yet')"
                                                  :description="__('Add the people doing the work so tasks can be assigned and they get the notifications.')" />
                            @else
                                <ul class="divide-y divide-[var(--line-subtle)]">
                                    @foreach ($this->members as $person)
                                        @php
                                            $isOwner = (int) $project->owner_id === (int) $person->getKey();
                                            // Project::members() has no ->using(), so the pivot carries a raw
                                            // string rather than a cast enum.
                                            $memberRole = \App\Enums\ProjectRole::tryFrom((string) $person->pivot->role)
                                                ?? \App\Enums\ProjectRole::Member;
                                        @endphp
                                        <li wire:key="member-{{ $person->getKey() }}"
                                            class="flex flex-wrap items-center gap-2.5 px-4 py-2.5">
                                            <x-ui.avatar :user="$person" size="md" />
                                            <div class="min-w-0 flex-1">
                                                <p class="flex items-center gap-1.5 truncate text-sm text-[var(--text-strong)]">
                                                    {{ $person->name }}
                                                    @if ($isOwner)
                                                        <x-ui.badge color="brand" size="sm">{{ __('Owner') }}</x-ui.badge>
                                                    @endif
                                                </p>
                                                <p class="truncate text-2xs text-[var(--text-subtle)]">{{ $person->email }}</p>
                                            </div>

                                            @if ($canManage)
                                                <div class="w-36">
                                                    <label for="member-role-{{ $person->getKey() }}" class="sr-only">
                                                        {{ __('Role for :name', ['name' => $person->name]) }}
                                                    </label>
                                                    <x-ui.select :id="'member-role-'.$person->getKey()" size="sm"
                                                                 wire:change="changeMemberRole({{ $person->getKey() }}, $event.target.value)">
                                                        @foreach (\App\Enums\ProjectRole::cases() as $case)
                                                            <option value="{{ $case->value }}" @selected($memberRole === $case)>
                                                                {{ $case->label() }}
                                                            </option>
                                                        @endforeach
                                                    </x-ui.select>
                                                </div>

                                                <x-ui.tooltip :label="__('Remove from project')">
                                                    <x-ui.button variant="danger-ghost" size="sm" icon-only
                                                                 wire:click="removeMember({{ $person->getKey() }})"
                                                                 wire:confirm="{{ __('Remove :name from this project? Their open tasks are handed to the project manager.', ['name' => $person->name]) }}"
                                                                 :aria-label="__('Remove :name', ['name' => $person->name])">
                                                        <x-icon.trash class="size-3.5" />
                                                    </x-ui.button>
                                                </x-ui.tooltip>
                                            @else
                                                <x-ui.badge :color="$memberRole->color()" size="sm">
                                                    {{ $memberRole->label() }}
                                                </x-ui.badge>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </x-ui.card>

                        @if ($canManage)
                            <div class="grid gap-4 lg:grid-cols-2">
                                <x-ui.card :title="__('Add somebody already here')">
                                    <form wire:submit="addMember" class="flex items-end gap-2">
                                        <div class="min-w-0 flex-1">
                                            <x-ui.field :label="__('Workspace member')" for="add-member"
                                                        :error="$errors->first('addMemberId')">
                                                <x-ui.select id="add-member" wire:model="addMemberId"
                                                             :placeholder="__('Choose a person')"
                                                             :invalid="$errors->has('addMemberId')">
                                                    @foreach ($this->addableMembers as $person)
                                                        <option value="{{ $person->getKey() }}">{{ $person->name }} — {{ $person->email }}</option>
                                                    @endforeach
                                                </x-ui.select>
                                            </x-ui.field>
                                        </div>
                                        <x-ui.button type="submit" variant="secondary" size="md" icon="icon.plus">
                                            {{ __('Add') }}
                                        </x-ui.button>
                                    </form>

                                    @if ($this->addableMembers->isEmpty())
                                        <p class="mt-2 text-xs text-[var(--text-muted)]">
                                            {{ __('Everybody in the workspace is already on this project.') }}
                                        </p>
                                    @endif
                                </x-ui.card>

                                <x-ui.card :title="__('Invite by e-mail')"
                                           :subtitle="__('They get a link that expires. Nothing is created until they accept.')">
                                    <form wire:submit="invite" class="space-y-3">
                                        <x-ui.field :label="__('E-mail address')" for="invite-email" :error="$errors->first('inviteEmail')">
                                            <x-ui.input id="invite-email" type="email" wire:model="inviteEmail"
                                                        :invalid="$errors->has('inviteEmail')"
                                                        placeholder="person@example.com" autocomplete="off" />
                                        </x-ui.field>

                                        <x-ui.field :label="__('Join as')" for="invite-role"
                                                    :hint="__('A guest can only ever see the projects they are named in.')"
                                                    :error="$errors->first('inviteRole')">
                                            <x-ui.select id="invite-role" wire:model="inviteRole">
                                                @foreach (\App\Enums\ProjectRole::cases() as $case)
                                                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                                @endforeach
                                            </x-ui.select>
                                        </x-ui.field>

                                        <div class="flex justify-end">
                                            <x-ui.button type="submit" variant="primary" size="md">
                                                <span wire:loading.remove wire:target="invite">{{ __('Send invitation') }}</span>
                                                <span wire:loading wire:target="invite" class="inline-flex items-center gap-1.5">
                                                    <x-ui.spinner class="size-4" />{{ __('Sending…') }}
                                                </span>
                                            </x-ui.button>
                                        </div>
                                    </form>
                                </x-ui.card>
                            </div>

                            @if ($this->invitations->isNotEmpty())
                                <x-ui.card :title="__('Pending invitations')" flush>
                                    <ul class="divide-y divide-[var(--line-subtle)]">
                                        @foreach ($this->invitations as $invitation)
                                            <li wire:key="invitation-{{ $invitation->getKey() }}"
                                                class="flex items-center gap-2.5 px-4 py-2.5">
                                                <span class="grid size-7 shrink-0 place-items-center rounded-full
                                                             bg-[var(--surface-sunken)] text-[var(--text-subtle)]">
                                                    <x-icon.inbox class="size-3.5" />
                                                </span>
                                                <div class="min-w-0 flex-1">
                                                    <p class="truncate text-sm text-[var(--text-DEFAULT)]">{{ $invitation->email }}</p>
                                                    <p class="text-2xs text-[var(--text-subtle)]">
                                                        {{ $invitation->role->label() }}
                                                        @if ($invitation->expires_at)
                                                            · {{ $invitation->expires_at->isPast()
                                                                    ? __('expired')
                                                                    : __('expires :date', ['date' => $invitation->expires_at->isoFormat('D MMM YYYY')]) }}
                                                        @endif
                                                    </p>
                                                </div>
                                                <x-ui.badge :color="$invitation->expires_at?->isPast() ? 'red' : 'amber'" size="sm">
                                                    {{ $invitation->expires_at?->isPast() ? __('Expired') : __('Pending') }}
                                                </x-ui.badge>
                                            </li>
                                        @endforeach
                                    </ul>
                                </x-ui.card>
                            @endif
                        @endif
                    </div>
                @endif

                {{-- ======================================================== --}}
                {{-- Tags                                                     --}}
                {{-- ======================================================== --}}
                @if ($section === 'tags')
                    <div class="space-y-4">
                        <x-ui.card :title="__('Tags')"
                                   :subtitle="__('Tags are shared across the whole workspace, so renaming one here renames it everywhere.')"
                                   flush>
                            @if ($this->tags->isEmpty())
                                <x-ui.empty-state icon="icon.tag"
                                                  :title="__('No tags yet')"
                                                  :description="__('Tags cut across projects — “blocked by client”, “needs legal”, “quick win”. Add the first one below.')" />
                            @else
                                <ul class="divide-y divide-[var(--line-subtle)]">
                                    @foreach ($this->tags as $tag)
                                        <li wire:key="tag-{{ $tag->getKey() }}" class="px-3 py-2.5">
                                            @if ($editingTagId === (int) $tag->getKey())
                                                <div class="flex flex-wrap items-end gap-2">
                                                    <div class="min-w-0 flex-1">
                                                        <x-ui.field :label="__('Name')" :for="'tag-name-'.$tag->getKey()"
                                                                    :error="$errors->first('tagName')">
                                                            <x-ui.input :id="'tag-name-'.$tag->getKey()" wire:model="tagName"
                                                                        :invalid="$errors->has('tagName')" />
                                                        </x-ui.field>
                                                    </div>
                                                    <div class="w-32">
                                                        <x-ui.field :label="__('Colour')" :for="'tag-color-'.$tag->getKey()">
                                                            <x-ui.select :id="'tag-color-'.$tag->getKey()" wire:model="tagColor">
                                                                @foreach ($palette as $swatchName)
                                                                    <option value="{{ $swatchName }}">{{ \App\Support\ChartPalette::label($swatchName) }}</option>
                                                                @endforeach
                                                            </x-ui.select>
                                                        </x-ui.field>
                                                    </div>
                                                    <x-ui.button variant="ghost" size="md" wire:click="cancelTagEdit">{{ __('Cancel') }}</x-ui.button>
                                                    <x-ui.button variant="primary" size="md" wire:click="saveTag">{{ __('Save') }}</x-ui.button>
                                                </div>
                                            @else
                                                <div class="flex items-center gap-2.5">
                                                    <x-ui.badge :color="in_array($tag->color, $palette, true) ? $tag->color : 'gray'" size="md" dot>
                                                        {{ $tag->name }}
                                                    </x-ui.badge>
                                                    <span class="ms-auto text-xs tabular-nums text-[var(--text-muted)]">
                                                        {{ trans_choice('{0}unused|{1}1 task|[2,*]:count tasks', (int) $tag->tasks_count, ['count' => (int) $tag->tasks_count]) }}
                                                    </span>
                                                    @can('update', $tag)
                                                        <x-ui.button variant="ghost" size="sm" wire:click="editTag({{ $tag->getKey() }})">
                                                            {{ __('Edit') }}
                                                        </x-ui.button>
                                                    @endcan
                                                    @can('delete', $tag)
                                                        <x-ui.button variant="danger-ghost" size="sm" icon-only
                                                                     wire:click="deleteTag({{ $tag->getKey() }})"
                                                                     wire:confirm="{{ __('Delete the “:name” tag? It is removed from every task that carries it.', ['name' => $tag->name]) }}"
                                                                     :aria-label="__('Delete :name', ['name' => $tag->name])">
                                                            <x-icon.trash class="size-3.5" />
                                                        </x-ui.button>
                                                    @endcan
                                                </div>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </x-ui.card>

                        @can('create', [\App\Models\Tag::class, $workspace])
                            <x-ui.card :title="__('Add a tag')">
                                <form wire:submit="addTag" class="grid gap-3 sm:grid-cols-[1fr_9rem_auto] sm:items-end">
                                    <x-ui.field :label="__('Name')" for="new-tag-name" :error="$errors->first('tagName')">
                                        <x-ui.input id="new-tag-name" wire:model="tagName"
                                                    :invalid="$errors->has('tagName')" :placeholder="__('Needs legal')" />
                                    </x-ui.field>
                                    <x-ui.field :label="__('Colour')" for="new-tag-color">
                                        <x-ui.select id="new-tag-color" wire:model="tagColor">
                                            @foreach ($palette as $swatchName)
                                                <option value="{{ $swatchName }}">{{ \App\Support\ChartPalette::label($swatchName) }}</option>
                                            @endforeach
                                        </x-ui.select>
                                    </x-ui.field>
                                    <x-ui.button type="submit" variant="secondary" size="md" icon="icon.plus">
                                        {{ __('Add tag') }}
                                    </x-ui.button>
                                </form>
                            </x-ui.card>
                        @endcan
                    </div>
                @endif

                {{-- ======================================================== --}}
                {{-- Custom fields                                            --}}
                {{-- ======================================================== --}}
                @if ($section === 'fields')
                    <div class="space-y-4">
                        <x-ui.card :title="__('Custom fields on tasks')"
                                   :subtitle="__('Fields defined for the whole workspace appear here too, and are edited in workspace settings.')"
                                   flush>
                            @if ($this->fields->isEmpty())
                                <x-ui.empty-state icon="icon.list"
                                                  :title="__('No custom fields')"
                                                  :description="__('Add one when a task needs something the built-in fields do not carry — a client reference, an environment, a sign-off date.')" />
                            @else
                                <ul class="divide-y divide-[var(--line-subtle)]">
                                    @foreach ($this->fields as $field)
                                        @php $isWorkspaceWide = $field->project_id === null; @endphp
                                        <li wire:key="field-{{ $field->getKey() }}" class="px-3 py-2.5">
                                            @if ($editingFieldId === (int) $field->getKey())
                                                <div class="space-y-2">
                                                    <div class="grid gap-2 sm:grid-cols-[1fr_10rem]">
                                                        <x-ui.field :label="__('Name')" :for="'field-name-'.$field->getKey()"
                                                                    :error="$errors->first('fieldName')">
                                                            <x-ui.input :id="'field-name-'.$field->getKey()" wire:model="fieldName"
                                                                        :invalid="$errors->has('fieldName')" />
                                                        </x-ui.field>
                                                        <x-ui.field :label="__('Type')" :for="'field-type-'.$field->getKey()">
                                                            <x-ui.select :id="'field-type-'.$field->getKey()" wire:model.live="fieldType">
                                                                @foreach (\App\Enums\CustomFieldType::cases() as $case)
                                                                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                                                @endforeach
                                                            </x-ui.select>
                                                        </x-ui.field>
                                                    </div>

                                                    @if (\App\Enums\CustomFieldType::from($fieldType)->hasOptions())
                                                        <x-ui.field :label="__('Choices')" :for="'field-options-'.$field->getKey()"
                                                                    :hint="__('One per line.')" :error="$errors->first('fieldOptions')">
                                                            <x-ui.textarea :id="'field-options-'.$field->getKey()" rows="3" wire:model="fieldOptions" />
                                                        </x-ui.field>
                                                    @endif

                                                    <x-ui.checkbox wire:model="fieldRequired" :label="__('Required on every task')" />

                                                    <div class="flex justify-end gap-2">
                                                        <x-ui.button variant="ghost" size="sm" wire:click="cancelFieldEdit">{{ __('Cancel') }}</x-ui.button>
                                                        <x-ui.button variant="primary" size="sm" wire:click="saveField">{{ __('Save field') }}</x-ui.button>
                                                    </div>
                                                </div>
                                            @else
                                                <div class="flex flex-wrap items-center gap-2.5">
                                                    <div class="min-w-0 flex-1">
                                                        <p class="flex items-center gap-1.5 truncate text-sm font-medium text-[var(--text-strong)]">
                                                            {{ $field->name }}
                                                            @if ($field->is_required)
                                                                <x-ui.badge color="amber" size="sm">{{ __('Required') }}</x-ui.badge>
                                                            @endif
                                                            @if ($isWorkspaceWide)
                                                                <x-ui.badge color="gray" size="sm">{{ __('Workspace-wide') }}</x-ui.badge>
                                                            @endif
                                                        </p>
                                                        <p class="truncate text-2xs text-[var(--text-subtle)]">
                                                            {{ $field->type->label() }} · <x-ui.bidi class="font-mono">{{ $field->key }}</x-ui.bidi>
                                                        </p>
                                                    </div>

                                                    @if (! $isWorkspaceWide)
                                                        @can('update', $field)
                                                            <x-ui.button variant="ghost" size="sm" wire:click="editField({{ $field->getKey() }})">
                                                                {{ __('Edit') }}
                                                            </x-ui.button>
                                                        @endcan
                                                        @can('delete', $field)
                                                            <x-ui.button variant="danger-ghost" size="sm" icon-only
                                                                         wire:click="deleteField({{ $field->getKey() }})"
                                                                         wire:confirm="{{ __('Delete “:name”? Every answer stored in it is deleted with it.', ['name' => $field->name]) }}"
                                                                         :aria-label="__('Delete :name', ['name' => $field->name])">
                                                                <x-icon.trash class="size-3.5" />
                                                            </x-ui.button>
                                                        @endcan
                                                    @endif
                                                </div>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </x-ui.card>

                        @can('create', [\App\Models\CustomField::class, $project])
                            <x-ui.card :title="__('Add a field')">
                                <form wire:submit="addField" class="space-y-3">
                                    <div class="grid gap-3 sm:grid-cols-[1fr_10rem]">
                                        <x-ui.field :label="__('Name')" for="new-field-name" :error="$errors->first('fieldName')">
                                            <x-ui.input id="new-field-name" wire:model="fieldName"
                                                        :invalid="$errors->has('fieldName')" :placeholder="__('Environment')" />
                                        </x-ui.field>
                                        <x-ui.field :label="__('Type')" for="new-field-type">
                                            <x-ui.select id="new-field-type" wire:model.live="fieldType">
                                                @foreach (\App\Enums\CustomFieldType::cases() as $case)
                                                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                                @endforeach
                                            </x-ui.select>
                                        </x-ui.field>
                                    </div>

                                    @if ($editingFieldId === null && \App\Enums\CustomFieldType::from($fieldType)->hasOptions())
                                        <x-ui.field :label="__('Choices')" for="new-field-options"
                                                    :hint="__('One per line.')" :error="$errors->first('fieldOptions')">
                                            <x-ui.textarea id="new-field-options" rows="3" wire:model="fieldOptions"
                                                           placeholder="{{ __('Staging') }}&#10;{{ __('Production') }}" />
                                        </x-ui.field>
                                    @endif

                                    <div class="flex items-center justify-between gap-3">
                                        <x-ui.checkbox wire:model="fieldRequired" :label="__('Required on every task')" />
                                        <x-ui.button type="submit" variant="secondary" size="md" icon="icon.plus">
                                            {{ __('Add field') }}
                                        </x-ui.button>
                                    </div>
                                </form>
                            </x-ui.card>
                        @endcan
                    </div>
                @endif

                {{-- ======================================================== --}}
                {{-- Danger zone                                              --}}
                {{-- ======================================================== --}}
                @if ($section === 'danger')
                    <div class="space-y-4">

                        @can('archive', $project)
                            <x-ui.card>
                                <x-slot:header>
                                    <h3 class="text-sm font-semibold text-[var(--text-strong)]">
                                        {{ $project->is_archived ? __('Restore this project') : __('Archive this project') }}
                                    </h3>
                                </x-slot:header>

                                @if ($project->is_archived)
                                    <p class="text-sm leading-relaxed text-[var(--text-muted)]">
                                        {{ __('Archived on :date. Everything is still here — restoring puts it back in the active list, keeps its key and leaves every task, milestone and file exactly as it was.', [
                                            'date' => $project->archived_at?->isoFormat('D MMM YYYY') ?? '—',
                                        ]) }}
                                    </p>
                                    <div class="mt-3">
                                        <x-ui.button variant="secondary" size="md" icon="icon.archive" wire:click="restore">
                                            {{ __('Restore project') }}
                                        </x-ui.button>
                                    </div>
                                @else
                                    <p class="text-sm leading-relaxed text-[var(--text-muted)]">
                                        {{ __('Archiving hides the project from the active list and the sidebar. Nothing is deleted: tasks, milestones, time entries and files stay exactly as they are, and you can bring it back at any time.') }}
                                    </p>
                                    <div class="mt-3">
                                        <x-ui.button variant="secondary" size="md" icon="icon.archive"
                                                     wire:click="archive"
                                                     wire:confirm="{{ __('Archive “:project”? It disappears from the active list until you restore it.', ['project' => $project->name]) }}">
                                            {{ __('Archive project') }}
                                        </x-ui.button>
                                    </div>
                                @endif
                            </x-ui.card>
                        @endcan

                        @can('delete', $project)
                            <div class="rounded-lg border border-critical-500/40 bg-[var(--surface-panel)] shadow-panel">
                                <div class="border-b border-critical-500/30 px-4 py-3">
                                    <h3 class="flex items-center gap-2 text-sm font-semibold text-critical-700 dark:text-critical-100">
                                        <x-icon.warning class="size-4" />
                                        {{ __('Delete this project') }}
                                    </h3>
                                </div>

                                <div class="p-4">
                                    <p class="text-sm leading-relaxed text-[var(--text-DEFAULT)]">
                                        {{ __('Deleting removes “:project” and everything reached through it. It stops being visible to everyone in the workspace immediately.', ['project' => $project->name]) }}
                                    </p>

                                    {{-- The blast radius, counted. A warning without numbers is
                                         a warning nobody can weigh. --}}
                                    <dl class="mt-3 grid grid-cols-2 gap-px overflow-hidden rounded-md border
                                               border-[var(--line-subtle)] bg-[var(--line-subtle)] sm:grid-cols-4">
                                        @foreach ([
                                            [__('Tasks'), $blast['tasks']],
                                            [__('Milestones'), $blast['milestones']],
                                            [__('Wiki pages'), $blast['wiki_pages']],
                                            [__('Time entries'), $blast['time_entries']],
                                            [__('Expenses'), $blast['expenses']],
                                            [__('Files'), $blast['attachments']],
                                            [__('Members'), $blast['members']],
                                            [__('Board columns'), $this->statuses->count()],
                                        ] as [$blastLabel, $blastValue])
                                            <div class="bg-[var(--surface-panel)] px-3 py-2">
                                                <dt class="text-2xs uppercase tracking-wide text-[var(--text-subtle)]">{{ $blastLabel }}</dt>
                                                <dd class="text-sm font-semibold tabular-nums text-[var(--text-strong)]">{{ $blastValue }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>

                                    <div class="mt-4 max-w-sm">
                                        <x-ui.field for="delete-confirmation"
                                                    :label="__('Type :key to confirm', ['key' => $project->display_key])"
                                                    :error="$errors->first('deleteConfirmation')">
                                            <x-ui.input id="delete-confirmation" wire:model="deleteConfirmation"
                                                        class="font-mono uppercase tracking-wide"
                                                        autocomplete="off"
                                                        :invalid="$errors->has('deleteConfirmation')"
                                                        :placeholder="$project->display_key" />
                                        </x-ui.field>
                                    </div>

                                    <div class="mt-3">
                                        <x-ui.button variant="danger" size="md" icon="icon.trash"
                                                     wire:click="destroy"
                                                     wire:confirm="{{ __('Delete “:project” and everything in it?', ['project' => $project->name]) }}">
                                            {{ __('Delete project') }}
                                        </x-ui.button>
                                    </div>
                                </div>
                            </div>
                        @endcan

                        @cannot('delete', $project)
                            @cannot('archive', $project)
                                <x-ui.card>
                                    <x-ui.empty-state compact icon="icon.shield"
                                                      :title="__('Nothing to do here')"
                                                      :description="__('Archiving and deleting a project are reserved for workspace administrators and this project\'s managers.')" />
                                </x-ui.card>
                            @endcannot
                        @endcannot
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

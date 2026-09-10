@php
    $templates = $this->templates;
    $selected = $this->selectedTemplate;
    $contents = $this->templateContents;

    $tint = static fn (?string $value): string =>
        is_string($value) && preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/', $value) === 1
            ? $value
            : 'var(--accent)';

    $steps = [
        1 => __('Start from'),
        2 => __('Details'),
    ];
@endphp

<div class="page page-prose py-6">

    {{-- ------------------------------------------------------------------ --}}
    {{-- Step rail                                                          --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="flex items-center justify-between gap-4">
        <div class="min-w-0">
            <h2 class="text-base font-semibold tracking-tight text-[var(--text-strong)]">
                {{ __('New project') }}
            </h2>
            <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                {{ __('Two decisions: where it starts from, and what it is.') }}
            </p>
        </div>

        <ol class="hidden items-center gap-2 sm:flex" aria-label="{{ __('Steps') }}">
            @foreach ($steps as $number => $label)
                <li class="flex items-center gap-2">
                    <span class="grid size-5 place-items-center rounded-full text-2xs font-semibold tabular-nums
                                 {{ $step >= $number
                                      ? 'bg-[var(--accent)] text-white'
                                      : 'bg-[var(--surface-active)] text-[var(--text-subtle)]' }}"
                          @if ($step === $number) aria-current="step" @endif>
                        {{ $number }}
                    </span>
                    <span class="text-xs {{ $step === $number ? 'font-medium text-[var(--text-strong)]' : 'text-[var(--text-muted)]' }}">
                        {{ $label }}
                    </span>
                    @if ($number === 1)
                        <span class="h-px w-6 bg-[var(--line-DEFAULT)]" aria-hidden="true"></span>
                    @endif
                </li>
            @endforeach
        </ol>
    </div>

    @if ($step === 1)
        {{-- ---------------------------------------------------------------- --}}
        {{-- 1 — where it starts from                                         --}}
        {{-- ---------------------------------------------------------------- --}}
        <div class="mt-5 space-y-3">

            <button type="button" wire:click="startBlank"
                    class="group flex w-full items-center gap-3 rounded-lg border border-[var(--line-subtle)]
                           bg-[var(--surface-panel)] p-4 text-start shadow-panel transition-[border-color,box-shadow]
                           hover:border-[var(--line-strong)] hover:shadow-raised">
                <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-[var(--surface-sunken)]
                             text-[var(--text-muted)]">
                    <x-icon.folder class="size-5" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-semibold text-[var(--text-strong)]">{{ __('Blank project') }}</span>
                    <span class="mt-0.5 block text-xs text-[var(--text-muted)]">
                        {{ __('An empty board using this workspace\'s default columns. Everything else is yours to add.') }}
                    </span>
                </span>
                <x-icon.chevron-right class="size-4 shrink-0 flip-rtl text-[var(--text-subtle)] transition-transform group-hover:nudge-inline" />
            </button>

            @if ($this->aiAvailable)
                <button type="button"
                        x-on:click="$dispatch('open-ai-panel', { intent: 'create' })"
                        class="group flex w-full items-center gap-3 rounded-lg border border-[var(--line-subtle)]
                               bg-[var(--surface-panel)] p-4 text-start shadow-panel transition-[border-color,box-shadow]
                               hover:border-[var(--line-strong)] hover:shadow-raised">
                    <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-[var(--accent-soft)]
                                 text-[var(--accent-soft-text)]">
                        <x-icon.sparkles class="size-5" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold text-[var(--text-strong)]">{{ __('Create with AI') }}</span>
                        <span class="mt-0.5 block text-xs text-[var(--text-muted)]">
                            {{ __('Describe the work in a sentence or two. Planvio drafts the milestones and tasks, and nothing is written until you approve it.') }}
                        </span>
                    </span>
                    <x-icon.chevron-right class="size-4 shrink-0 flip-rtl text-[var(--text-subtle)] transition-transform group-hover:nudge-inline" />
                </button>
            @endif

            @if ($templates->isNotEmpty())
                <div class="pt-2">
                    <h3 class="px-0.5 text-2xs font-semibold uppercase tracking-wider text-[var(--text-subtle)]">
                        {{ __('Start from a template') }}
                    </h3>

                    <ul class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                        @foreach ($templates as $template)
                            <li wire:key="template-{{ $template->getKey() }}">
                                <button type="button" wire:click="startFromTemplate({{ $template->getKey() }})"
                                        class="group flex h-full w-full items-start gap-2.5 rounded-lg border
                                               border-[var(--line-subtle)] bg-[var(--surface-panel)] p-3 text-start
                                               shadow-panel transition-[border-color,box-shadow]
                                               hover:border-[var(--line-strong)] hover:shadow-raised">
                                    <span class="grid size-8 shrink-0 place-items-center rounded-lg text-base leading-none"
                                          style="background-color: color-mix(in oklab, {{ $tint($template->color) }} 16%, transparent)"
                                          aria-hidden="true">
                                        {{ $template->icon ?: '📁' }}
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="flex items-center gap-1.5">
                                            <span class="truncate text-sm font-medium text-[var(--text-strong)]">{{ $template->name }}</span>
                                            @if ($template->workspace_id !== null)
                                                <x-ui.badge color="brand" size="sm">{{ __('Yours') }}</x-ui.badge>
                                            @endif
                                        </span>
                                        {{-- dir="auto": a template's name and blurb are rows an
                                             administrator can rename, and the system ones ship in
                                             English. Two or three lines of English inside an Arabic
                                             page otherwise has its full stop resolved to the page
                                             direction and printed mid-line. --}}
                                        <span dir="auto"
                                              class="mt-0.5 block text-xs leading-relaxed text-[var(--text-muted)]">
                                            {{ \Illuminate\Support\Str::limit((string) $template->description, 110) }}
                                        </span>
                                        <span class="mt-1.5 block text-2xs tabular-nums text-[var(--text-subtle)]">
                                            {{ __(':milestones milestones · :tasks tasks', [
                                                'milestones' => count($template->section('milestones')),
                                                'tasks' => count($template->section('tasks')),
                                            ]) }}
                                        </span>
                                    </span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @error('templateId')
                <p class="text-xs text-critical-600" role="alert">{{ $message }}</p>
            @enderror

            <div class="pt-1">
                <x-ui.button :href="route('app.projects.index', $workspace)" variant="ghost" size="md">
                    {{ __('Cancel') }}
                </x-ui.button>
            </div>
        </div>

    @else
        {{-- ---------------------------------------------------------------- --}}
        {{-- 2 — what it is                                                   --}}
        {{-- ---------------------------------------------------------------- --}}
        <form wire:submit="save" class="mt-5 space-y-3">

            @error('form')
                <div class="flex items-start gap-2 rounded-lg border border-critical-500/40 bg-critical-50 px-3.5 py-3
                            text-sm text-critical-700 dark:bg-critical-950 dark:text-critical-100"
                     role="alert">
                    <x-icon.warning class="mt-0.5 size-4 shrink-0" />
                    <span>{{ $message }}</span>
                </div>
            @enderror

            {{-- What was chosen, and what it will build. --}}
            <div class="flex items-start gap-3 rounded-lg border border-[var(--line-subtle)]
                        bg-[var(--surface-sunken)] px-3.5 py-3">
                <span class="grid size-8 shrink-0 place-items-center rounded-lg text-base leading-none"
                      style="background-color: color-mix(in oklab, {{ $tint($color) }} 16%, transparent)"
                      aria-hidden="true">
                    {{ $selected?->icon ?: ($icon ?: '📁') }}
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-medium text-[var(--text-strong)]">
                        {{ $selected ? __('From the “:template” template', ['template' => $selected->name]) : __('Blank project') }}
                    </p>
                    <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                        @if ($selected)
                            {{ __(':statuses board columns, :milestones milestones, :tasks tasks, :tags tags and :views saved views will be created with the project.', [
                                'statuses' => $contents['statuses'],
                                'milestones' => $contents['milestones'],
                                'tasks' => $contents['tasks'],
                                'tags' => $contents['tags'],
                                'views' => $contents['views'],
                            ]) }}
                        @else
                            {{ __('The board is seeded with this workspace\'s default columns.') }}
                        @endif
                    </p>
                </div>
                <x-ui.button type="button" variant="ghost" size="sm" wire:click="backToStart">
                    {{ __('Change') }}
                </x-ui.button>
            </div>

            {{-- Identity ---------------------------------------------------- --}}
            <x-ui.card :title="__('Identity')">
                <div class="space-y-3">
                    <div class="grid gap-3 sm:grid-cols-[1fr_10rem]">
                        <x-ui.field :label="__('Name')" for="project-name" required :error="$errors->first('name')">
                            <x-ui.input id="project-name" wire:model.live.debounce.400ms="name"
                                        :invalid="$errors->has('name')"
                                        autofocus autocomplete="off"
                                        :placeholder="__('Website redesign')" />
                        </x-ui.field>

                        <x-ui.field :label="__('Key')" for="project-key" required
                                    :error="$errors->first('key')"
                                    :hint="$errors->has('key') ? null : __('Prefixes every task: :example', ['example' => ($key ?: 'WEB').'-42'])">
                            <div class="flex items-center gap-1">
                                <x-ui.input id="project-key" wire:model.blur="key"
                                            :invalid="$errors->has('key')"
                                            class="font-mono uppercase tracking-wide"
                                            maxlength="12" autocomplete="off" />
                                @if ($keyIsCustom)
                                    <x-ui.tooltip :label="__('Derive from the name again')">
                                        <x-ui.button type="button" variant="ghost" size="md" icon-only
                                                     wire:click="resetKey" :aria-label="__('Derive the key from the name again')">
                                            <x-icon.sparkles class="size-4" />
                                        </x-ui.button>
                                    </x-ui.tooltip>
                                @endif
                            </div>
                        </x-ui.field>
                    </div>

                    <x-ui.field :label="__('Description')" for="project-description"
                                :hint="__('One paragraph on what done looks like. It is the first thing the AI reads about this project.')"
                                :error="$errors->first('description')">
                        <x-ui.textarea id="project-description" wire:model="description" rows="3"
                                       :invalid="$errors->has('description')"
                                       :placeholder="__('Rebuild the marketing site on the new design system, live before the autumn campaign.')" />
                    </x-ui.field>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-ui.field :label="__('Type')" for="project-type" :error="$errors->first('type')">
                            <x-ui.select id="project-type" wire:model="type" :invalid="$errors->has('type')">
                                @foreach (\App\Enums\ProjectType::cases() as $case)
                                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>

                        <x-ui.field :label="__('Project manager')" for="project-manager"
                                    :error="$errors->first('managerId')"
                                    :hint="__('Gets the manager role on the project.')">
                            <x-ui.select id="project-manager" wire:model="managerId"
                                         :invalid="$errors->has('managerId')" :placeholder="__('Nobody yet')">
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
                                @foreach (\App\Livewire\App\Projects\Create::COLORS as $swatch)
                                    <button type="button"
                                            wire:click="$set('color', '{{ $swatch }}')"
                                            aria-label="{{ $swatch }}"
                                            aria-pressed="{{ $color === $swatch ? 'true' : 'false' }}"
                                            class="size-6 rounded-full ring-offset-2 ring-offset-[var(--surface-panel)] transition-shadow
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
                                        aria-pressed="{{ $icon === '' ? 'true' : 'false' }}"
                                        aria-label="{{ __('No icon') }}"
                                        class="grid size-7 place-items-center rounded-md border text-xs transition-colors
                                               {{ $icon === ''
                                                    ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent-soft-text)]'
                                                    : 'border-[var(--line-subtle)] text-[var(--text-subtle)] hover:bg-[var(--surface-hover)]' }}">
                                    <x-icon.folder class="size-3.5" />
                                </button>
                                @foreach (\App\Livewire\App\Projects\Create::ICONS as $emoji)
                                    <button type="button" wire:click="$set('icon', '{{ $emoji }}')"
                                            aria-pressed="{{ $icon === $emoji ? 'true' : 'false' }}"
                                            aria-label="{{ $emoji }}"
                                            class="grid size-7 place-items-center rounded-md border text-sm leading-none transition-colors
                                                   {{ $icon === $emoji
                                                        ? 'border-[var(--accent)] bg-[var(--accent-soft)]'
                                                        : 'border-transparent hover:bg-[var(--surface-hover)]' }}">
                                        {{ $emoji }}
                                    </button>
                                @endforeach
                            </div>
                        </fieldset>
                    </div>
                </div>
            </x-ui.card>

            {{-- Schedule and money ------------------------------------------ --}}
            <x-ui.card :title="__('Schedule and budget')" :subtitle="__('All optional — a project without dates is still a project.')">
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.field :label="__('Start date')" for="project-start" :error="$errors->first('startDate')">
                        <x-ui.input id="project-start" type="date" wire:model="startDate"
                                    :invalid="$errors->has('startDate')" />
                    </x-ui.field>

                    <x-ui.field :label="__('Target date')" for="project-target" :error="$errors->first('targetDate')">
                        <x-ui.input id="project-target" type="date" wire:model="targetDate"
                                    :invalid="$errors->has('targetDate')" />
                    </x-ui.field>

                    <x-ui.field :label="__('Budget')" for="project-budget" :error="$errors->first('budget')">
                        <x-ui.input id="project-budget" type="number" step="0.01" min="0"
                                    wire:model="budget" :invalid="$errors->has('budget')"
                                    inputmode="decimal" placeholder="0.00" />
                    </x-ui.field>

                    <x-ui.field :label="__('Currency')" for="project-currency" :error="$errors->first('currency')">
                        <x-ui.input id="project-currency" wire:model="currency" maxlength="3"
                                    class="uppercase-latin" :invalid="$errors->has('currency')" />
                    </x-ui.field>
                </div>
            </x-ui.card>

            {{-- People ------------------------------------------------------ --}}
            <x-ui.card :title="__('Team')"
                       :subtitle="__('You are added as project manager automatically. Anyone else you pick joins as a member.')">
                <x-slot:actions>
                    <span class="text-xs tabular-nums text-[var(--text-muted)]">
                        {{ __(':count selected', ['count' => count($memberIds)]) }}
                    </span>
                </x-slot:actions>

                <div class="space-y-2">
                    <label for="project-member-search" class="sr-only">{{ __('Search people') }}</label>
                    <x-ui.input id="project-member-search" type="search" icon="icon.search"
                                wire:model.live.debounce.300ms="memberSearch"
                                :placeholder="__('Search by name or e-mail')" />

                    @if ($this->candidates->isEmpty())
                        <x-ui.empty-state compact icon="icon.users"
                                          :title="__('Nobody found')"
                                          :description="__('No workspace member matches that search.')" />
                    @else
                        <ul class="scrollbar-thin max-h-56 divide-y divide-[var(--line-subtle)] overflow-y-auto
                                   rounded-md border border-[var(--line-subtle)]">
                            @foreach ($this->candidates as $person)
                                @php
                                    $isSelf = (int) $person->getKey() === (int) auth()->id();
                                    $checked = $isSelf || in_array((int) $person->getKey(), $memberIds, true);
                                @endphp
                                <li wire:key="candidate-{{ $person->getKey() }}">
                                    <label class="flex cursor-pointer items-center gap-2.5 px-2.5 py-2 transition-colors
                                                  hover:bg-[var(--surface-hover)] {{ $isSelf ? 'cursor-default opacity-70' : '' }}">
                                        <input type="checkbox"
                                               @checked($checked)
                                               @disabled($isSelf)
                                               wire:click="toggleMember({{ $person->getKey() }})"
                                               class="size-4 shrink-0 rounded border-[var(--line-strong)]
                                                      bg-[var(--surface-panel)] text-[var(--accent)]
                                                      checked:border-[var(--accent)] checked:bg-[var(--accent)]
                                                      focus:ring-2 focus:ring-[var(--accent-ring)]">
                                        <x-ui.avatar :user="$person" size="sm" />
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-sm text-[var(--text-DEFAULT)]">{{ $person->name }}</span>
                                            <span class="block truncate text-2xs text-[var(--text-subtle)]">{{ $person->email }}</span>
                                        </span>
                                        @if ($isSelf)
                                            <x-ui.badge color="brand" size="sm">{{ __('Manager') }}</x-ui.badge>
                                        @endif
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @error('memberIds.*')
                        <p class="text-xs text-critical-600" role="alert">{{ $message }}</p>
                    @enderror
                </div>
            </x-ui.card>

            {{-- Commit ------------------------------------------------------ --}}
            <div class="flex items-center justify-between gap-3 pt-1">
                <x-ui.button type="button" variant="ghost" size="md" wire:click="backToStart">
                    {{ __('Back') }}
                </x-ui.button>

                <div class="flex items-center gap-2">
                    <x-ui.button :href="route('app.projects.index', $workspace)" variant="secondary" size="md">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button type="submit" variant="primary" size="md">
                        <span wire:loading.remove wire:target="save">{{ __('Create project') }}</span>
                        <span wire:loading wire:target="save" class="inline-flex items-center gap-1.5">
                            <x-ui.spinner class="size-4" />{{ __('Creating…') }}
                        </span>
                    </x-ui.button>
                </div>
            </div>
        </form>
    @endif
</div>

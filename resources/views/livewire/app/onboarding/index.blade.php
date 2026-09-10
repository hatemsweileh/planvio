@php
    $steps = $this->steps;
    $done = $this->completedCount();
    $total = $this->totalCount();
@endphp

<div class="page page-prose py-8">

    {{-- ---------------------------------------------------------------- --}}
    {{-- Welcome                                                          --}}
    {{-- ---------------------------------------------------------------- --}}
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <div class="flex items-center gap-2.5">
                <x-ui.brand-mark class="size-8" />
                <h1 class="text-xl font-semibold tracking-tight text-[var(--text-strong)]">
                    {{ __('Welcome to Planvio') }}
                </h1>
            </div>
            <p class="mt-2 max-w-xl text-sm leading-relaxed text-[var(--text-muted)]">
                {{ __('Five short steps and :workspace is a working workspace. Skip anything — you can come back to this page whenever you like.', ['workspace' => $workspace->name]) }}
            </p>
        </div>

        <x-ui.button variant="ghost" wire:click="finish">
            {{ $done === $total ? __('Take me in') : __('I’ll do this later') }}
        </x-ui.button>
    </header>

    <div class="mt-5 flex items-center gap-3">
        <x-ui.progress :value="$done" :max="$total" class="max-w-xs" :label="__('Setup progress')" />
        <span class="text-xs tabular-nums text-[var(--text-muted)]">
            {{ __(':done of :total done', ['done' => $done, 'total' => $total]) }}
        </span>
    </div>

    <div class="mt-6 flex flex-col gap-5 lg:flex-row lg:gap-8">

        {{-- ------------------------------------------------------------ --}}
        {{-- The checklist                                                --}}
        {{-- ------------------------------------------------------------ --}}
        <nav class="shrink-0 lg:w-64" aria-label="{{ __('Setup steps') }}">
            <ol class="space-y-1">
                @foreach ($steps as $key => $meta)
                    <li>
                        <button type="button" wire:click="goTo('{{ $key }}')"
                                @if (! $meta['available']) disabled @endif
                                @if ($step === $key) aria-current="step" @endif
                                class="flex w-full items-start gap-2.5 rounded-lg px-2.5 py-2 text-start
                                       transition-colors disabled:cursor-not-allowed disabled:opacity-50
                                       {{ $step === $key
                                           ? 'bg-[var(--accent-soft)]'
                                           : 'hover:bg-[var(--surface-hover)]' }}">

                            <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full border
                                         {{ $meta['done']
                                             ? 'border-positive-500 bg-positive-500 text-white'
                                             : 'border-[var(--line-strong)] text-[var(--text-subtle)]' }}">
                                @if ($meta['done'])
                                    <svg class="size-3" viewBox="0 0 12 12" fill="none" stroke="currentColor"
                                         stroke-width="2.25" aria-hidden="true">
                                        <path d="m2 6 3 3 5-6" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                @else
                                    <span class="text-[10px] font-semibold tabular-nums">{{ $loop->iteration }}</span>
                                @endif
                            </span>

                            <span class="min-w-0">
                                <span class="block text-sm font-medium
                                             {{ $step === $key
                                                 ? 'text-[var(--accent-soft-text)]'
                                                 : 'text-[var(--text-DEFAULT)]' }}">
                                    {{ $meta['title'] }}
                                </span>
                                <span class="block text-xs leading-relaxed text-[var(--text-muted)]">
                                    {{ $meta['summary'] }}
                                </span>
                            </span>
                        </button>
                    </li>
                @endforeach
            </ol>
        </nav>

        {{-- ------------------------------------------------------------ --}}
        {{-- The current step                                             --}}
        {{-- ------------------------------------------------------------ --}}
        <div class="min-w-0 flex-1">

            {{-- 1. Workspace ------------------------------------------- --}}
            @if ($step === 'workspace')
                <x-ui.card :title="__('Name your workspace')"
                           :subtitle="__('A workspace is one organisation, team or client. Everything else lives inside it.')">
                    <form wire:submit="saveWorkspace" class="space-y-4">
                        <x-ui.field :label="__('Workspace name')" for="ob-name"
                                    :error="$errors->first('workspaceName')" required>
                            <x-ui.input id="ob-name" size="lg" wire:model="workspaceName" maxlength="80"
                                        :placeholder="__('Acme, Marketing, Northwind Ltd')"
                                        :invalid="$errors->has('workspaceName')" autofocus />
                        </x-ui.field>

                        <p class="text-xs leading-relaxed text-[var(--text-muted)]">
                            {{ __('You can change the address, logo, accent colour and formats later in workspace settings.') }}
                        </p>

                        <div class="flex items-center justify-between gap-2">
                            <x-ui.button variant="ghost" type="button" wire:click="skip">{{ __('Skip') }}</x-ui.button>
                            <x-ui.button type="submit" variant="primary">{{ __('Save and continue') }}</x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @endif

            {{-- 2. People ---------------------------------------------- --}}
            @if ($step === 'people')
                <x-ui.card :title="__('Invite the people you work with')"
                           :subtitle="__('They get an email with a link straight into this workspace. It expires on its own.')">
                    <form wire:submit="sendInvitations" class="space-y-4">
                        <div class="space-y-2">
                            @foreach ($inviteEmails as $index => $email)
                                <x-ui.field :error="$errors->first('inviteEmails.'.$index)"
                                            wire:key="invite-row-{{ $index }}">
                                    <label for="ob-invite-{{ $index }}" class="sr-only">
                                        {{ __('Email address :n', ['n' => $index + 1]) }}
                                    </label>
                                    <x-ui.input id="ob-invite-{{ $index }}" type="email"
                                                wire:model="inviteEmails.{{ $index }}"
                                                :placeholder="__('name@company.com')"
                                                :invalid="$errors->has('inviteEmails.'.$index)" />
                                </x-ui.field>
                            @endforeach
                        </div>

                        <x-ui.field :label="__('They join as')" for="ob-invite-role"
                                    :hint="__('Members can create and work on tasks. You can change anyone’s role later.')">
                            <x-ui.select id="ob-invite-role" wire:model="inviteRole">
                                @foreach (\App\Enums\WorkspaceRole::cases() as $case)
                                    @continue($case === \App\Enums\WorkspaceRole::Owner)
                                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>

                        @if ($this->pendingInvitations > 0)
                            <p class="text-xs text-[var(--text-muted)]">
                                {{ trans_choice(
                                    '{1} :count invitation is already out.|[2,*] :count invitations are already out.',
                                    $this->pendingInvitations,
                                    ['count' => $this->pendingInvitations],
                                ) }}
                            </p>
                        @endif

                        <div class="flex items-center justify-between gap-2">
                            <x-ui.button variant="ghost" type="button" wire:click="skip">
                                {{ __('Skip — it’s just me for now') }}
                            </x-ui.button>
                            <x-ui.button type="submit" variant="primary">{{ __('Send invitations') }}</x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @endif

            {{-- 3. Project --------------------------------------------- --}}
            @if ($step === 'project')
                <x-ui.card :title="__('Create your first project')"
                           :subtitle="__('A project holds tasks, milestones, files and documents. Most people start with one and grow from there.')">
                    <form wire:submit="createProject" class="space-y-4">
                        <x-ui.field :label="__('Project name')" for="ob-project"
                                    :error="$errors->first('projectName')" required>
                            <x-ui.input id="ob-project" size="lg" wire:model="projectName" maxlength="120"
                                        :placeholder="__('Website redesign')"
                                        :invalid="$errors->has('projectName')" />
                        </x-ui.field>

                        <div>
                            <p class="mb-1.5 text-xs font-medium text-[var(--text-DEFAULT)]">
                                {{ __('How should it start?') }}
                            </p>
                            <div class="grid gap-2 sm:grid-cols-3" role="radiogroup"
                                 aria-label="{{ __('How should it start?') }}">
                                @foreach ([
                                    ['blank', __('Blank'), __('Default statuses, nothing else.'), 'icon.folder'],
                                    ['template', __('From a template'), __('Statuses, milestones and starter tasks.'), 'icon.list'],
                                    ['ai', __('Describe it to AI'), __('It drafts the structure for you to review.'), 'icon.sparkles'],
                                ] as [$value, $label, $blurb, $icon])
                                    @php $usable = $value !== 'ai' || $this->aiUsable(); @endphp
                                    <button type="button" wire:click="$set('projectApproach', '{{ $value }}')"
                                            role="radio"
                                            aria-checked="{{ $projectApproach === $value ? 'true' : 'false' }}"
                                            @if (! $usable) disabled @endif
                                            class="flex h-full flex-col gap-1 rounded-lg border p-3 text-start
                                                   transition-colors disabled:cursor-not-allowed disabled:opacity-50
                                                   {{ $projectApproach === $value
                                                       ? 'border-[var(--accent)] bg-[var(--accent-soft)]'
                                                       : 'border-[var(--line-subtle)] hover:bg-[var(--surface-hover)]' }}">
                                        <span class="flex items-center gap-1.5 text-sm font-medium text-[var(--text-strong)]">
                                            <x-dynamic-component :component="$icon" class="size-4 shrink-0" />
                                            {{ $label }}
                                        </span>
                                        <span class="text-xs leading-relaxed text-[var(--text-muted)]">{{ $blurb }}</span>
                                        @unless ($usable)
                                            <span class="mt-auto text-2xs text-[var(--text-subtle)]">
                                                {{ __('AI is not configured yet.') }}
                                            </span>
                                        @endunless
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        @if ($projectApproach === 'template')
                            <x-ui.field :label="__('Template')" for="ob-template"
                                        :error="$errors->first('templateId')">
                                @if ($this->templates->isEmpty())
                                    <p class="rounded-md border border-dashed border-[var(--line-DEFAULT)] px-3 py-2
                                              text-xs text-[var(--text-muted)]">
                                        {{ __('No templates are available on this installation. Start blank instead — you can save this project as a template later.') }}
                                    </p>
                                @else
                                    <x-ui.select id="ob-template" wire:model="templateId"
                                                 :invalid="$errors->has('templateId')">
                                        <option value="">{{ __('Choose a template') }}</option>
                                        @foreach ($this->templates as $template)
                                            <option value="{{ $template->getKey() }}">
                                                {{ $template->icon ? $template->icon.' ' : '' }}{{ $template->name }}
                                            </option>
                                        @endforeach
                                    </x-ui.select>
                                @endif
                            </x-ui.field>
                        @endif

                        @if ($projectApproach === 'ai')
                            <x-ui.field :label="__('What is this project?')" for="ob-brief"
                                        :error="$errors->first('aiBrief')"
                                        :hint="__('Planvio AI drafts the milestones and tasks and shows them to you before anything is created.')">
                                <x-ui.textarea id="ob-brief" wire:model="aiBrief" rows="4" maxlength="2000"
                                               :placeholder="__('Rebuild the marketing site on the new brand, live before the trade show in October. Design, content, build, launch.')"
                                               :invalid="$errors->has('aiBrief')" />
                            </x-ui.field>
                        @endif

                        <div class="flex items-center justify-between gap-2">
                            <x-ui.button variant="ghost" type="button" wire:click="skip">{{ __('Skip') }}</x-ui.button>
                            <x-ui.button type="submit" variant="primary">
                                {{ $projectApproach === 'ai' ? __('Ask Planvio AI') : __('Create project') }}
                            </x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @endif

            {{-- 4. Tasks ----------------------------------------------- --}}
            @if ($step === 'tasks')
                <x-ui.card :title="__('Put some work in it')"
                           :subtitle="$this->firstProject
                               ? __('One per line. They land in :project, in its first status.', ['project' => $this->firstProject->name])
                               : __('Create a project first and this step opens up.')">
                    @if ($this->firstProject === null)
                        <x-ui.empty-state icon="icon.folder"
                                          :title="__('No project yet')"
                                          :description="__('Tasks need somewhere to live.')"
                                          compact>
                            <x-slot:actions>
                                <x-ui.button variant="primary" size="md" wire:click="goTo('project')">
                                    {{ __('Create a project') }}
                                </x-ui.button>
                            </x-slot:actions>
                        </x-ui.empty-state>
                    @else
                        <form wire:submit="createTasks" class="space-y-4">
                            <x-ui.field :label="__('Tasks')" for="ob-tasks" :error="$errors->first('taskTitles')"
                                        :hint="__('Up to 25 at a time. Dates, assignees and priorities come later.')"
                                        required>
                                <x-ui.textarea id="ob-tasks" wire:model="taskTitles" rows="6"
                                               :invalid="$errors->has('taskTitles')"
                                               placeholder="{{ __('Agree the brief') }}&#10;{{ __('Draft the wireframes') }}&#10;{{ __('Review with the client') }}" />
                            </x-ui.field>

                            <div class="flex items-center justify-between gap-2">
                                <x-ui.button variant="ghost" type="button" wire:click="skip">{{ __('Skip') }}</x-ui.button>
                                <x-ui.button type="submit" variant="primary">{{ __('Add tasks') }}</x-ui.button>
                            </div>
                        </form>
                    @endif
                </x-ui.card>
            @endif

            {{-- 5. AI -------------------------------------------------- --}}
            @if ($step === 'ai')
                <x-ui.card :title="__('Decide what AI may do')"
                           :subtitle="__('Planvio can answer questions, propose changes for you to approve, or act on its own inside limits you set. Nothing is on until you choose.')">
                    <ul class="space-y-3">
                        @foreach ([
                            [__('Assistant'), __('Answers and drafts. It never changes your data.'), 'blue'],
                            [__('Copilot'), __('Proposes a change and waits for your approval.'), 'brand'],
                            [__('Autonomous'), __('Acts inside your limits and reports what it did.'), 'purple'],
                        ] as [$title, $blurb, $tone])
                            <li class="flex items-start gap-2.5">
                                <x-ui.badge :color="$tone" dot class="mt-0.5 shrink-0">{{ $title }}</x-ui.badge>
                                <span class="text-sm leading-relaxed text-[var(--text-muted)]">{{ $blurb }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <p class="mt-4 text-xs leading-relaxed text-[var(--text-muted)]">
                        {{ __('Whichever you choose, the agent can never do more than the person it is acting for, every action is written to the audit trail, and one switch stops it entirely.') }}
                    </p>

                    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
                        <x-ui.button variant="ghost" wire:click="finish">{{ __('Not now') }}</x-ui.button>
                        <x-ui.button variant="primary" icon="icon.sparkles"
                                     :href="route('app.settings', $workspace).'?section=ai'" wire:navigate>
                            {{ __('Open AI settings') }}
                        </x-ui.button>
                    </div>
                </x-ui.card>
            @endif

            {{-- Finish ------------------------------------------------- --}}
            @if ($done === $total)
                <div class="mt-4 rounded-lg border border-positive-500/40 bg-positive-50 p-3 dark:bg-positive-950/40">
                    <p class="flex items-start gap-2 text-sm text-positive-700 dark:text-positive-100">
                        <x-icon.check-circle class="mt-0.5 size-4 shrink-0" />
                        {{ __('That is everything. :workspace is set up.', ['workspace' => $workspace->name]) }}
                    </p>
                    <x-ui.button variant="primary" size="sm" class="mt-3" wire:click="finish">
                        {{ __('Go to the workspace') }}
                    </x-ui.button>
                </div>
            @endif
        </div>
    </div>
</div>

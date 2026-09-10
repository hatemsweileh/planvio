@php
    $canManage = $this->canManage();
    $engaged = $this->killSwitchEngaged();
    $setting = $this->setting;
@endphp

<div class="space-y-4">

    {{-- ---------------------------------------------------------------- --}}
    {{-- Kill switch. The loudest thing on the page, by design.           --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($engaged)
        <div class="rounded-lg border border-critical-500/50 bg-critical-50 p-3 dark:bg-critical-950/40"
             role="alert">
            <div class="flex flex-wrap items-start gap-2.5">
                <x-icon.warning class="mt-0.5 size-4 shrink-0 text-critical-600" />
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-critical-700 dark:text-critical-100">
                        {{ __('AI is stopped in this workspace') }}
                    </p>
                    <p class="mt-0.5 text-xs leading-relaxed text-critical-700 dark:text-critical-100">
                        {{ __('No new run starts, and anything already queued aborts at its next step.') }}
                        @if (filled($setting?->kill_switch_reason))
                            <span class="block mt-1">
                                {{ __('Reason: :reason', ['reason' => $setting->kill_switch_reason]) }}
                            </span>
                        @endif
                        @if ($setting?->kill_switch_at)
                            <span class="block">
                                {{ __('Stopped') }}
                                <span x-data="relativeTime('{{ $setting->kill_switch_at->toIso8601String() }}')"
                                      x-text="label"></span>
                            </span>
                        @endif
                    </p>
                </div>

                @if ($canManage)
                    <x-ui.button variant="secondary" size="sm" wire:click="releaseKillSwitch"
                                 wire:confirm="{{ __('Let AI run again in this workspace?') }}">
                        {{ __('Resume AI') }}
                    </x-ui.button>
                @endif
            </div>
        </div>
    @endif

    @unless ($this->platformEnabled())
        <div class="rounded-lg border border-[var(--line-DEFAULT)] bg-[var(--surface-sunken)] p-3">
            <p class="flex items-start gap-2 text-xs leading-relaxed text-[var(--text-muted)]">
                <x-icon.shield class="mt-0.5 size-3.5 shrink-0" />
                {{ __('AI is switched off for this whole installation, so nothing configured here will run until an administrator enables it. The settings are still saved.') }}
            </p>
        </div>
    @endunless

    @if ($this->inheritsDefaults())
        <div class="rounded-lg border border-[var(--line-DEFAULT)] bg-[var(--surface-sunken)] p-3">
            <p class="flex items-start gap-2 text-xs leading-relaxed text-[var(--text-muted)]">
                <x-icon.sparkles class="mt-0.5 size-3.5 shrink-0" />
                {{ __('This workspace is currently following the installation defaults. Saving below gives it settings of its own.') }}
            </p>
        </div>
    @endif

    <form wire:submit="save" class="space-y-4">

        {{-- ------------------------------------------------------------ --}}
        {{-- On / off and provider                                        --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card :title="__('Availability')"
                   :subtitle="__('Whether people in this workspace can use Planvio AI at all, and which model answers.')">
            <div class="space-y-4">
                <x-ui.checkbox wire:model.live="isEnabled"
                               :label="__('Planvio AI is available in this workspace')"
                               :description="__('When off, the assistant, the AI panel and every automation stay hidden.')" />

                <x-ui.field :label="__('Model provider')" for="ai-provider" :error="$errors->first('providerId')"
                            :hint="__('Configured by a platform administrator. Credentials never appear here.')">
                    @if ($this->providers->isEmpty())
                        <p class="rounded-md border border-dashed border-[var(--line-DEFAULT)] px-3 py-2 text-xs
                                  text-[var(--text-muted)]">
                            {{ __('No provider is configured on this installation yet.') }}
                        </p>
                    @else
                        <x-ui.select id="ai-provider" wire:model="providerId" :invalid="$errors->has('providerId')">
                            <option value="">{{ __('No provider') }}</option>
                            @foreach ($this->providers as $provider)
                                <option value="{{ $provider->getKey() }}">
                                    {{ $provider->name }} — {{ $provider->model }}{{ $provider->is_active ? '' : ' ('.__('inactive').')' }}
                                </option>
                            @endforeach
                        </x-ui.select>
                    @endif
                </x-ui.field>
            </div>
        </x-ui.card>

        {{-- ------------------------------------------------------------ --}}
        {{-- Mode. Three cards, not a select.                             --}}
        {{-- ------------------------------------------------------------ --}}
        <section aria-labelledby="ai-mode-heading">
            <div class="mb-2">
                <h3 id="ai-mode-heading" class="text-sm font-semibold text-[var(--text-strong)]">
                    {{ __('How much may it do on its own?') }}
                </h3>
                <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                    {{ __('The starting point for every conversation and automation. A project can be stricter, never looser.') }}
                </p>
            </div>

            <div class="grid gap-3 lg:grid-cols-3" role="radiogroup" aria-labelledby="ai-mode-heading">
                @foreach ($this->modeCards() as $value => $card)
                    @php
                        $selected = $defaultMode === $value;
                        $locked = $value === 'autonomous' && ! $this->canGrantAutonomy();
                    @endphp

                    <button type="button"
                            wire:click="selectMode('{{ $value }}')"
                            role="radio"
                            aria-checked="{{ $selected ? 'true' : 'false' }}"
                            @if ($locked || ! $canManage) disabled @endif
                            class="flex h-full flex-col rounded-lg border p-3.5 text-start transition-all duration-150
                                   disabled:cursor-not-allowed disabled:opacity-60
                                   {{ $selected
                                       ? 'border-[var(--accent)] bg-[var(--accent-soft)] shadow-raised'
                                       : 'border-[var(--line-subtle)] bg-[var(--surface-panel)] shadow-panel hover:border-[var(--line-strong)]' }}">

                        <span class="flex items-center gap-2">
                            <span class="grid size-5 shrink-0 place-items-center rounded-full border-2
                                         {{ $selected
                                             ? 'border-[var(--accent)]'
                                             : 'border-[var(--line-strong)]' }}">
                                @if ($selected)
                                    <span class="size-2.5 rounded-full bg-[var(--accent)]"></span>
                                @endif
                            </span>
                            <span class="text-sm font-semibold text-[var(--text-strong)]">{{ $card['title'] }}</span>
                            <x-ui.badge :color="$card['color']" size="sm" class="ms-auto">
                                {{ $value === 'assistant' ? __('Safest') : ($value === 'autonomous' ? __('Most capable') : __('Balanced')) }}
                            </x-ui.badge>
                        </span>

                        <span class="mt-2 block text-xs font-medium text-[var(--text-DEFAULT)]">
                            {{ $card['summary'] }}
                        </span>

                        <ul class="mt-2.5 space-y-1.5">
                            @foreach ($card['consequences'] as $consequence)
                                <li class="flex items-start gap-1.5 text-xs leading-relaxed text-[var(--text-muted)]">
                                    <svg class="mt-1 size-3 shrink-0 text-[var(--text-subtle)]" viewBox="0 0 12 12"
                                         fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path d="m2 6 3 3 5-6" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                    <span>{{ $consequence }}</span>
                                </li>
                            @endforeach
                        </ul>

                        @if ($locked)
                            <span class="mt-2.5 block text-2xs text-[var(--text-subtle)]">
                                {{ __('Only an owner or administrator can grant this.') }}
                            </span>
                        @endif
                    </button>
                @endforeach
            </div>

            @error('defaultMode')
                <p class="mt-2 text-xs text-critical-600" role="alert">{{ $message }}</p>
            @enderror

            @if ($this->canGrantAutonomy())
                <div class="mt-3 rounded-lg border border-[var(--line-subtle)] bg-[var(--surface-panel)] p-3
                            shadow-panel">
                    <x-ui.checkbox wire:model.live="autonomousEnabled"
                                   :label="__('Allow autonomous runs')"
                                   :description="__('Without this, a run that asks for autonomous mode quietly runs as copilot instead — it asks before every change.')" />
                </div>
            @endif
        </section>

        {{-- ------------------------------------------------------------ --}}
        {{-- Tools                                                        --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card :title="__('Tools')"
                   :subtitle="__('What the agent can reach for. Every call is still checked against the acting person’s own permissions — this narrows the list, it never widens anyone’s rights.')">
            <div class="space-y-4">
                <x-ui.checkbox wire:model.live="restrictTools"
                               :label="__('Restrict to a chosen list')"
                               :description="__('Off means every tool below is available, subject to mode and approval.')" />

                @error('allowedTools')
                    <p class="text-xs text-critical-600" role="alert">{{ $message }}</p>
                @enderror

                <div class="space-y-3">
                    @foreach ($this->toolCatalogue as $group => $tools)
                        <div class="rounded-lg border border-[var(--line-subtle)]">
                            <p class="border-b border-[var(--line-subtle)] bg-[var(--surface-sunken)] px-3 py-1.5
                                      text-2xs font-semibold uppercase tracking-wide text-[var(--text-subtle)]">
                                {{ $group }}
                            </p>

                            <ul class="divide-y divide-[var(--line-subtle)]">
                                @foreach ($tools as $tool)
                                    <li class="flex flex-wrap items-start gap-x-3 gap-y-1 px-3 py-2">
                                        <div class="min-w-0 flex-1">
                                            <p class="flex items-center gap-1.5">
                                                <code class="font-mono text-xs text-[var(--text-strong)]">
                                                    {{ $tool['name'] }}
                                                </code>
                                                <x-ui.badge :color="$tool['risk']->color()" size="sm">
                                                    {{ $tool['risk']->label() }}
                                                </x-ui.badge>
                                                @unless ($tool['mutating'])
                                                    <span class="text-2xs text-[var(--text-subtle)]">{{ __('read only') }}</span>
                                                @endunless
                                            </p>
                                            {{-- The example dates and ids inside a description
                                                 are isolated: an Arabic word before a number
                                                 makes it an Arabic number, which takes its
                                                 hyphens away and prints 2026-09-30 backwards. --}}
                                            <p class="mt-0.5 line-clamp-2 text-xs leading-relaxed text-[var(--text-muted)]">
                                                {{ \App\Support\Bidi::numbers($tool['description']) }}
                                            </p>
                                        </div>

                                        <div class="flex shrink-0 items-center gap-4">
                                            @if ($restrictTools)
                                                <label class="flex cursor-pointer items-center gap-1.5 text-xs
                                                              text-[var(--text-muted)]">
                                                    <input type="checkbox" wire:model="allowedTools"
                                                           value="{{ $tool['name'] }}"
                                                           class="size-4 rounded border-[var(--line-strong)]
                                                                  bg-[var(--surface-panel)] text-[var(--accent)]
                                                                  checked:border-[var(--accent)] checked:bg-[var(--accent)]
                                                                  focus:ring-2 focus:ring-[var(--accent-ring)]">
                                                    {{ __('Allowed') }}
                                                </label>
                                            @endif

                                            @if ($tool['mutating'])
                                                <label class="flex cursor-pointer items-center gap-1.5 text-xs
                                                              text-[var(--text-muted)]">
                                                    <input type="checkbox" wire:model="approvalTools"
                                                           value="{{ $tool['name'] }}"
                                                           class="size-4 rounded border-[var(--line-strong)]
                                                                  bg-[var(--surface-panel)] text-[var(--accent)]
                                                                  checked:border-[var(--accent)] checked:bg-[var(--accent)]
                                                                  focus:ring-2 focus:ring-[var(--accent-ring)]">
                                                    {{ __('Always ask') }}
                                                </label>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </div>
        </x-ui.card>

        {{-- ------------------------------------------------------------ --}}
        {{-- Limits                                                       --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card :title="__('Limits')"
                   :subtitle="__('A run that loops is a run that costs money and changes things nobody asked for. These are the walls it stops at.')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field :label="__('Tool calls per run')" for="ai-tool-calls"
                            :error="$errors->first('maxToolCallsPerRun')"
                            :hint="__('The run stops and reports when it reaches this.')" required>
                    <x-ui.input id="ai-tool-calls" type="number" min="1" max="200"
                                wire:model="maxToolCallsPerRun"
                                :invalid="$errors->has('maxToolCallsPerRun')" />
                </x-ui.field>

                <x-ui.field :label="__('Seconds per run')" for="ai-run-seconds"
                            :error="$errors->first('maxRunSeconds')" required>
                    <x-ui.input id="ai-run-seconds" type="number" min="10" max="900"
                                wire:model="maxRunSeconds" :invalid="$errors->has('maxRunSeconds')" />
                </x-ui.field>

                <x-ui.field :label="__('Runs per day')" for="ai-runs-day"
                            :error="$errors->first('maxRunsPerDay')"
                            :hint="__('Across the whole workspace.')" required>
                    <x-ui.input id="ai-runs-day" type="number" min="1" max="100000"
                                wire:model="maxRunsPerDay" :invalid="$errors->has('maxRunsPerDay')" />
                </x-ui.field>

                <x-ui.field :label="__('Errors before a run gives up')" for="ai-errors"
                            :error="$errors->first('errorThreshold')" required>
                    <x-ui.input id="ai-errors" type="number" min="1" max="20"
                                wire:model="errorThreshold" :invalid="$errors->has('errorThreshold')" />
                </x-ui.field>

                <x-ui.field :label="__('Keep AI history for (days)')" for="ai-retention"
                            :error="$errors->first('retentionDays')"
                            :hint="__('Leave empty to keep conversations and run logs indefinitely.')">
                    <x-ui.input id="ai-retention" type="number" min="1" max="3650"
                                wire:model="retentionDays" :invalid="$errors->has('retentionDays')" />
                </x-ui.field>
            </div>

            <div class="mt-4">
                <x-ui.checkbox wire:model="notifyOnAction"
                               :label="__('Notify people when the agent changes their work')"
                               :description="__('A task reassigned by AI is still a task reassigned. Off, the change is only in the activity feed.')" />
            </div>
        </x-ui.card>

        {{-- ------------------------------------------------------------ --}}
        {{-- House style                                                  --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card :title="__('Standing instructions')"
                   :subtitle="__('Added to every prompt in this workspace. House style, vocabulary, the things it should always check.')">
            <x-ui.field for="ai-instructions" :error="$errors->first('systemInstructions')"
                        :hint="__('Content from your workspace is always passed to the model as data, never as instructions — this box is the one place you speak to it directly.')">
                <x-ui.textarea id="ai-instructions" wire:model="systemInstructions" rows="5"
                               maxlength="4000"
                               :placeholder="__('Write in British English. Always name the milestone a task belongs to. Never change a due date without saying why.')"
                               :invalid="$errors->has('systemInstructions')" />
            </x-ui.field>
        </x-ui.card>

        <div class="flex flex-wrap items-center justify-between gap-2">
            @if ($canManage && ! $engaged)
                <x-ui.button variant="danger-ghost" type="button" icon="icon.warning"
                             wire:click="$set('showKillSwitch', true)">
                    {{ __('Stop AI in this workspace') }}
                </x-ui.button>
            @else
                <span></span>
            @endif

            <div class="flex items-center gap-2">
                <span wire:loading wire:target="save" class="text-xs text-[var(--text-muted)]">
                    {{ __('Saving…') }}
                </span>
                <x-ui.button type="submit" variant="primary" :disabled="! $canManage">
                    {{ __('Save AI settings') }}
                </x-ui.button>
            </div>
        </div>
    </form>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Kill switch dialog                                               --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.modal wire:model="showKillSwitch" :title="__('Stop AI in this workspace')"
                :description="__('Nothing new starts, and runs already in flight abort at their next step. Nothing already done is undone.')"
                size="md">
        <x-ui.field :label="__('Why (optional)')" for="kill-reason"
                    :hint="__('Shown to anyone who tries to use AI here, and recorded in the audit log.')">
            <x-ui.input id="kill-reason" wire:model="killSwitchReason" maxlength="255"
                        :placeholder="__('Investigating an unexpected change to the Q3 board')" />
        </x-ui.field>

        <x-slot:footer>
            <x-ui.button variant="ghost" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="danger" wire:click="engageKillSwitch" wire:target="engageKillSwitch">
                {{ __('Stop AI now') }}
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>

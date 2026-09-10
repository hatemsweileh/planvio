{{--
    The composer.

    Three controls and no more: what you want, how much rope the assistant has, and what it
    is allowed to be looking at. The mode is shown rather than chosen — it is resolved from
    the workspace's settings, its policies and your own permissions
    (App\Ai\Policy\PolicyResolver), and a control that pretended otherwise would be lying
    about who decides. The tooltip says what the current one actually means.

    Expects: $compact (bool)
--}}
@php
    $compact = $compact ?? false;
    $lockScope = $lockScope ?? false;
    $unavailable = $this->unavailableReason;

    // Resolved only when there is something to compose with: folding the policy stack costs
    // three queries, and a workspace with AI switched off has nothing to spend them on.
    $mode = $unavailable === null ? $this->mode() : null;
    $scopeProject = $unavailable === null ? $this->scopeProject() : null;
@endphp

<div class="shrink-0 border-t border-[var(--line-subtle)] bg-[var(--surface-panel)] px-3 py-3 sm:px-4">
    <div class="mx-auto w-full {{ $compact ? '' : 'max-w-3xl' }}">

        @if ($unavailable)
            {{--
                The disabled state. It names the setting that is off rather than showing a
                dead text box, and it never guesses at a fix it cannot see.
            --}}
            <div class="flex items-start gap-2.5 rounded-md border border-[var(--line-subtle)]
                        bg-[var(--surface-sunken)] px-3 py-2.5">
                <x-icon.warning class="mt-0.5 size-4 shrink-0 text-[var(--text-subtle)]" />
                <div class="min-w-0 flex-1">
                    <p class="text-xs leading-relaxed text-[var(--text-DEFAULT)]">{{ $unavailable }}</p>
                    @if ($this->workspace)
                        @can('ai.manage', $this->workspace)
                            <div class="mt-2">
                                <x-ui.button size="sm" icon="icon.cog" :href="route('app.settings', $this->workspace)">
                                    {{ __('AI settings') }}
                                </x-ui.button>
                            </div>
                        @endcan
                    @endif
                </div>
            </div>
        @else
            @if ($this->refusal)
                <div class="mb-2 flex items-start gap-2.5 rounded-md border border-critical-500/40
                            bg-critical-50 px-3 py-2.5 dark:bg-critical-950/40">
                    <x-icon.warning class="mt-0.5 size-4 shrink-0 text-critical-600 dark:text-critical-500" />
                    <p class="min-w-0 flex-1 text-xs leading-relaxed text-critical-700 dark:text-critical-500">
                        {{ $this->refusal }}
                    </p>
                </div>
            @endif

            <form wire:submit="send"
                  x-data="{ get empty() { return ! ($wire.draft ?? '').trim() } }"
                  class="rounded-lg border border-[var(--line-DEFAULT)] bg-[var(--surface-panel)] shadow-xs
                         focus-within:border-[var(--accent)] focus-within:ring-2 focus-within:ring-[var(--accent-ring)]">

                <label for="ai-composer-{{ $this->getId() }}" class="sr-only">{{ __('Message Planvio AI') }}</label>

                <textarea id="ai-composer-{{ $this->getId() }}"
                          dir="auto"
                          wire:model="draft"
                          x-ref="draft"
                          x-on:keydown.enter.exact.prevent="if (! empty) $wire.send()"
                          rows="{{ $compact ? 2 : 3 }}"
                          maxlength="4000"
                          placeholder="{{ $scopeProject
                              ? __('Ask about :project…', ['project' => $scopeProject->name])
                              : __('Ask anything about this workspace…') }}"
                          class="scrollbar-thin block w-full resize-none border-0 bg-transparent px-3 py-2.5 text-sm
                                 leading-relaxed text-[var(--text-strong)] placeholder:text-[var(--text-subtle)]
                                 focus:outline-none focus:ring-0"></textarea>

                <div class="flex flex-wrap items-center gap-1.5 border-t border-[var(--line-subtle)] px-2 py-1.5">

                    {{-- Mode: shown, explained, never chosen here. --}}
                    <x-ui.tooltip :label="$mode->description()" position="top">
                        <span class="inline-flex h-7 items-center gap-1.5 rounded-md border border-[var(--line-subtle)]
                                     bg-[var(--surface-sunken)] px-2 text-2xs font-medium text-[var(--text-DEFAULT)]"
                              tabindex="0">
                            <x-ui.status-dot :color="$mode->color()" size="sm" />
                            {{ $mode->label() }}
                        </span>
                    </x-ui.tooltip>

                    {{-- Scope: what the run is allowed to be looking at. --}}
                    @if ($lockScope)
                        {{--
                            On a project's own tab the scope is the screen. Shown, not offered:
                            a control that could re-aim this conversation would quietly change
                            which `ai_policies` rows apply to it.
                        --}}
                        <span class="inline-flex h-7 max-w-[12rem] items-center gap-1.5 rounded-md border
                                     border-[var(--line-subtle)] bg-[var(--surface-sunken)] px-2 text-2xs
                                     font-medium text-[var(--text-DEFAULT)]">
                            @if ($scopeProject)
                                <span class="size-1.5 shrink-0 rounded-full"
                                      style="background-color: {{ $scopeProject->color }}" aria-hidden="true"></span>
                                <span dir="auto" class="truncate">{{ $scopeProject->name }}</span>
                            @else
                                <x-icon.folder class="size-3.5 shrink-0 text-[var(--text-subtle)]" />
                                <span class="truncate">{{ __('Whole workspace') }}</span>
                            @endif
                        </span>
                    @else
                    <x-ui.dropdown align="start" width="w-64">
                        <x-slot:trigger>
                            <button type="button"
                                    class="inline-flex h-7 max-w-[12rem] items-center gap-1.5 rounded-md border
                                           border-[var(--line-subtle)] bg-[var(--surface-sunken)] px-2 text-2xs
                                           font-medium text-[var(--text-DEFAULT)] transition-colors
                                           hover:border-[var(--line-DEFAULT)] hover:bg-[var(--surface-hover)]">
                                @if ($scopeProject)
                                    <span class="size-1.5 shrink-0 rounded-full"
                                          style="background-color: {{ $scopeProject->color }}" aria-hidden="true"></span>
                                    <span dir="auto" class="truncate">{{ $scopeProject->name }}</span>
                                @else
                                    <x-icon.folder class="size-3.5 shrink-0 text-[var(--text-subtle)]" />
                                    <span class="truncate">{{ __('Whole workspace') }}</span>
                                @endif
                                <x-icon.chevron-down class="size-3 shrink-0 text-[var(--text-subtle)]" />
                            </button>
                        </x-slot:trigger>

                        <p class="px-2 pb-1 pt-1 text-2xs font-semibold uppercase tracking-wider text-[var(--text-subtle)]">
                            {{ __('Scope the next question') }}
                        </p>

                        <x-ui.dropdown-item icon="icon.folder"
                                            :active="$scopeProject === null"
                                            wire:click="scopeTo">
                            {{ __('Whole workspace') }}
                        </x-ui.dropdown-item>

                        @if ($this->scopeOptions->isNotEmpty())
                            <x-ui.dropdown-separator />

                            <div class="scrollbar-thin max-h-64 overflow-y-auto">
                                @foreach ($this->scopeOptions as $option)
                                    <x-ui.dropdown-item :active="$scopeProject?->getKey() === $option->getKey()"
                                                        wire:click="scopeTo({{ $option->getKey() }})">
                                        <span class="flex items-center gap-2">
                                            <span class="size-1.5 shrink-0 rounded-full"
                                                  style="background-color: {{ $option->color }}" aria-hidden="true"></span>
                                            <span dir="auto" class="truncate">{{ $option->name }}</span>
                                        </span>
                                    </x-ui.dropdown-item>
                                @endforeach
                            </div>
                        @endif
                    </x-ui.dropdown>
                    @endif

                    <p class="ms-auto hidden pe-1 text-2xs text-[var(--text-subtle)] sm:block">
                        {{ __('Enter to send · Shift + Enter for a new line') }}
                    </p>

                    <x-ui.button type="submit" variant="primary" size="sm" x-bind:disabled="empty">
                        <span wire:loading.remove wire:target="send,ask">{{ __('Send') }}</span>
                        <span wire:loading wire:target="send,ask" class="inline-flex items-center gap-1.5">
                            <x-ui.spinner class="size-3.5" />
                            {{ __('Sending') }}
                        </span>
                    </x-ui.button>
                </div>
            </form>
        @endif
    </div>
</div>

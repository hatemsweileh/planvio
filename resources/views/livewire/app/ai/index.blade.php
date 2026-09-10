@php
    $progress = $this->progress;
    $canApprove = $this->canSee('approvals');
    $canAudit = $this->canSee('runs');
    $waiting = $canApprove ? $this->pendingApprovalCount() : 0;
@endphp

{{--
    The AI workspace.

    Three panes on one screen rather than three routes, because they are one story at
    different distances: what is happening, what is waiting on a person, and what already
    happened. A product whose agent can change your projects has no business letting anybody
    use the first without ever finding the other two.
--}}
<div class="flex h-full min-w-0" x-data="{ rail: false }">

    {{-- ------------------------------------------------------------------ --}}
    {{-- Rail                                                               --}}
    {{-- ------------------------------------------------------------------ --}}
    <aside class="hidden w-72 shrink-0 border-e border-[var(--line-subtle)] lg:block">
        @include('livewire.app.ai._rail')
    </aside>

    {{-- The same rail as an overlay on small screens. --}}
    <div x-show="rail" x-cloak class="fixed inset-0 z-40 lg:hidden" role="dialog" aria-modal="true">
        <div x-show="rail" x-transition:enter="ease-out duration-150" x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-100"
             x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             x-on:click="rail = false" class="absolute inset-0 bg-ink-950/30" aria-hidden="true"></div>

        <div x-show="rail"
             x-transition:enter="transform transition ease-[cubic-bezier(0.32,0.72,0,1)] duration-200"
             x-transition:enter-start="slide-from-start" x-transition:enter-end="translate-x-0"
             x-transition:leave="transform transition ease-in duration-150"
             x-transition:leave-start="translate-x-0" x-transition:leave-end="slide-from-start"
             x-trap.noscroll="rail"
             x-on:click="rail = false"
             class="absolute inset-y-0 start-0 w-72 border-e border-[var(--line-subtle)] shadow-overlay">
            @include('livewire.app.ai._rail')
        </div>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Main column                                                        --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="flex min-h-0 min-w-0 flex-1 flex-col bg-[var(--surface-panel)]">

        <div class="flex h-12 shrink-0 items-center gap-2 border-b border-[var(--line-subtle)] px-3 sm:px-4">

            <x-ui.button variant="ghost" size="sm" icon-only x-on:click="rail = true"
                         class="lg:hidden" :aria-label="__('Conversations')">
                <x-icon.chat class="size-4" />
            </x-ui.button>

            <x-ui.tabs variant="pill" class="min-w-0" aria-label="{{ __('AI sections') }}">
                <x-ui.tab variant="pill" :active="$pane === 'chat'" wire:click="showPane('chat')">
                    {{ __('Conversation') }}
                </x-ui.tab>

                @if ($canApprove)
                    <x-ui.tab variant="pill" :active="$pane === 'approvals'" wire:click="showPane('approvals')"
                              :count="$waiting > 0 ? $waiting : null">
                        {{ __('Approvals') }}
                    </x-ui.tab>
                @endif

                @if ($canAudit)
                    <x-ui.tab variant="pill" :active="$pane === 'runs'" wire:click="showPane('runs')">
                        {{ __('Runs') }}
                    </x-ui.tab>
                @endif
            </x-ui.tabs>

            <div class="ms-auto flex min-w-0 items-center gap-2">
                @if ($canApprove && $waiting > 0 && $pane !== 'approvals')
                    {{--
                        A parked run is the one state that must never be discoverable only by
                        chance: it is work that has stopped and is waiting on a human.
                    --}}
                    <button type="button" wire:click="showPane('approvals')"
                            class="inline-flex h-7 items-center gap-1.5 rounded-md border border-caution-500/40
                                   bg-caution-50 px-2 text-2xs font-medium text-caution-700 transition-colors
                                   hover:brightness-95 dark:bg-caution-500/10 dark:text-caution-500">
                        <x-icon.shield class="size-3.5" />
                        {{ trans_choice('{1}:count action waiting|[2,*]:count actions waiting', $waiting, ['count' => $waiting]) }}
                    </button>
                @endif
            </div>
        </div>

        {{-- ---------------------------------------------------------------- --}}
        {{-- Panes                                                            --}}
        {{-- ---------------------------------------------------------------- --}}
        @if ($pane === 'chat')
            <div class="scrollbar-thin min-h-0 flex-1 overflow-y-auto"
                 @if ($progress?->live) wire:poll.visible.{{ $progress->interval }}="tick" @endif>
                @include('livewire.app.ai._thread', ['compact' => false])
            </div>

            @include('livewire.app.ai._composer', ['compact' => false])

        @elseif ($pane === 'approvals' && $canApprove)
            <div class="scrollbar-thin min-h-0 flex-1 overflow-y-auto">
                @livewire('app.ai.approvals', ['workspace' => $workspace], key('ai-approvals-'.$workspace->getKey()))
            </div>

        @elseif ($pane === 'runs' && $canAudit)
            <div class="scrollbar-thin min-h-0 flex-1 overflow-y-auto">
                @livewire('app.ai.runs', ['workspace' => $workspace], key('ai-runs-'.$workspace->getKey()))
            </div>
        @endif
    </div>
</div>

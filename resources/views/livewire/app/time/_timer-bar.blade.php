@php
    /**
     * The clock.
     *
     * The elapsed reading ticks in the browser from the entry's `started_at`, so the page
     * does not poll the server once a second to show a number the client can work out for
     * itself. Stopping is the only thing that writes, and the minutes it records are
     * computed server-side by {@see \App\Actions\Time\StopTimer} — the browser's counter is
     * a display, never a source of truth.
     *
     * Expects `$showPicker` (bool) and, when true, `$showProject` (bool).
     */
    $running = $this->running;
    $elsewhere = $this->runningElsewhere;
@endphp

<div class="rounded-lg border border-[var(--line-subtle)] bg-[var(--surface-panel)] px-3 py-2.5 shadow-panel">
    @if ($running)
        <div class="flex flex-wrap items-center gap-3"
             x-data="{
                startedAt: {{ ($running->started_at?->getTimestamp() ?? 0) * 1000 }},
                elapsed: '0:00',
                init() { this.tick(); this.ticker = setInterval(() => this.tick(), 1000); },
                destroy() { clearInterval(this.ticker); },
                tick() {
                    const total = Math.max(0, Math.floor((Date.now() - this.startedAt) / 1000));
                    const h = Math.floor(total / 3600);
                    const m = Math.floor((total % 3600) / 60);
                    const s = total % 60;
                    this.elapsed = (h > 0 ? h + ':' + String(m).padStart(2, '0') : String(m))
                        + ':' + String(s).padStart(2, '0');
                },
             }">
            <span class="relative flex size-2.5 shrink-0" aria-hidden="true">
                <span class="absolute inline-flex size-full animate-ping rounded-full bg-positive-500 opacity-60"></span>
                <span class="relative inline-flex size-2.5 rounded-full bg-positive-500"></span>
            </span>

            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-[var(--text-strong)]">
                    {{ $running->task?->title ?? $running->project?->name }}
                </p>
                <p class="truncate text-2xs text-[var(--text-muted)]">
                    {{ $running->project?->name }}
                    @if ($running->description)
                        <span class="text-[var(--text-subtle)]">· {{ $running->description }}</span>
                    @endif
                    @unless ($running->is_billable)
                        <span class="text-[var(--text-subtle)]">· {{ __('non-billable') }}</span>
                    @endunless
                </p>
            </div>

            <p class="shrink-0 font-mono text-lg font-semibold tabular-nums text-[var(--text-strong)]"
               x-text="elapsed"
               aria-live="off">0:00</p>
            <span class="sr-only">{{ __('A timer is running on :project.', ['project' => $running->project?->name]) }}</span>

            <x-ui.button variant="danger" size="md" wire:click="stopTimer" wire:target="stopTimer"
                         icon="icon.clock">
                {{ __('Stop') }}
            </x-ui.button>
        </div>
    @elseif ($elsewhere)
        <div class="flex flex-wrap items-center gap-3">
            <x-icon.warning class="size-4 shrink-0 text-caution-600" />
            <p class="min-w-0 flex-1 text-xs text-[var(--text-DEFAULT)]">
                {{ __('You have a timer running in another workspace. Starting one here will stop it.') }}
            </p>
        </div>
    @else
        <form wire:submit="startTimer" class="flex flex-wrap items-end gap-2">
            @if ($showPicker)
                @if ($showProject)
                    <x-ui.field :label="__('Project')" for="timer-project" class="min-w-40 flex-1"
                                :error="$errors->first('formProject')">
                        <x-ui.select id="timer-project" size="md" wire:model.live="formProject"
                                     :options="$this->projectOptions" />
                    </x-ui.field>
                @endif

                <x-ui.field :label="__('Task')" for="timer-task" class="min-w-40 flex-1">
                    <x-ui.select id="timer-task" size="md" wire:model="formTask" :options="$this->taskOptions" />
                </x-ui.field>

                <x-ui.field :label="__('What are you working on?')" for="timer-note" class="min-w-48 flex-[2]">
                    <x-ui.input id="timer-note" size="md" wire:model="formDescription"
                                :placeholder="__('Optional note')" maxlength="255" />
                </x-ui.field>
            @endif

            <div class="flex items-center gap-2 pb-0.5">
                <x-ui.checkbox wire:model="formBillable" :label="__('Billable')" />
                <x-ui.button type="submit" variant="primary" size="md" icon="icon.clock" wire:target="startTimer">
                    {{ __('Start timer') }}
                </x-ui.button>
            </div>
        </form>
    @endif
</div>

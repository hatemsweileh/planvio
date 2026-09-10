@php
    use App\Support\Bidi;
    use App\Support\Formats;
@endphp

{{--
    Run history.

    Every execution of the agent loop in this workspace, whoever asked for it and whatever
    started it — a chat message, an automation at 3am, the API. Open one and the tool trace
    underneath it says exactly what that run did, in order, with the arguments, the result,
    the duration and, where a person had to approve a call, their name against it.

    This is the audit view (`ai.view_logs`), which is why it shows other people's runs and
    why the tool trace is the point of the row rather than a detail of it.
--}}
<div class="page py-5">

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <h2 class="text-sm font-semibold tracking-tight text-[var(--text-strong)]">
                {{ $runUuid === null ? __('Runs') : __('One run') }}
            </h2>
            <p class="mt-1 max-w-xl text-xs leading-relaxed text-[var(--text-muted)]">
                {{ $runUuid === null
                    ? __('Every agent run recorded in this workspace. Open one to see the calls it made and what each of them returned.')
                    : __('The run this link was about, and every call it made.') }}
            </p>
        </div>

        @if ($runUuid === null)
            <div class="w-44 shrink-0">
                <label for="run-status" class="sr-only">{{ __('Filter by status') }}</label>
                <x-ui.select id="run-status" size="sm" wire:model.live="status" :options="$this->statusOptions()" />
            </div>
        @else
            <x-ui.button size="sm" variant="secondary" wire:click="showAllRuns">
                {{ __('Show all runs') }}
            </x-ui.button>
        @endif
    </div>

    <div class="mt-4 overflow-hidden rounded-lg border border-[var(--line-subtle)] bg-[var(--surface-panel)] shadow-panel">
        @if ($this->runs->isEmpty())
            <x-ui.empty-state icon="icon.sparkles"
                              :title="$status === 'all' ? __('No runs yet') : __('No runs with that status')"
                              :description="$status === 'all'
                                  ? __('Once somebody asks the assistant for something, the run and every call it makes are recorded here.')
                                  : __('Try another status, or clear the filter to see everything.')">
                @if ($runUuid !== null)
                    <x-slot:actions>
                        <x-ui.button size="sm" variant="secondary" wire:click="showAllRuns">
                            {{ __('Show all runs') }}
                        </x-ui.button>
                    </x-slot:actions>
                @endif
            </x-ui.empty-state>
        @else
            <ul class="divide-y divide-[var(--line-subtle)]">
                @foreach ($this->runs as $run)
                    @php $open = $this->openRunId === (int) $run->getKey(); @endphp

                    <li wire:key="run-{{ $run->getKey() }}">
                        <button type="button"
                                wire:click="toggleRun({{ $run->getKey() }})"
                                aria-expanded="{{ $open ? 'true' : 'false' }}"
                                class="flex w-full items-start gap-3 px-3 py-2.5 text-start transition-colors
                                       hover:bg-[var(--surface-hover)] sm:px-4">

                            <span class="flip-rtl mt-1 inline-flex shrink-0">
                                <x-icon.chevron-right class="size-3.5 text-[var(--text-subtle)]
                                                              transition-transform {{ $open ? 'rotate-90' : '' }}" />
                            </span>

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <x-ui.badge :color="$run->status->color()" size="sm" dot>
                                        {{ $run->status->label() }}
                                    </x-ui.badge>

                                    {{-- The objective is the person's own sentence, so it takes
                                         its direction from what they typed rather than from the
                                         page; Bidi::numbers keeps the dates and keys inside it
                                         in the order they were typed. --}}
                                    <span dir="auto" class="truncate text-xs font-medium text-[var(--text-strong)]">
                                        {{ Bidi::numbers(\Illuminate\Support\Str::limit(
                                            (string) ($run->objective ?: __('No objective recorded')), 110,
                                        )) }}
                                    </span>
                                </div>

                                <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-2xs text-[var(--text-subtle)]">
                                    <span>{{ $run->trigger?->label() }}</span>
                                    <span aria-hidden="true">&middot;</span>
                                    <span>{{ $run->mode?->label() }}</span>

                                    @if ($run->project)
                                        <span aria-hidden="true">&middot;</span>
                                        <span class="inline-flex items-center gap-1">
                                            <span class="size-1.5 rounded-full"
                                                  style="background-color: {{ $run->project->color }}"
                                                  aria-hidden="true"></span>
                                            {{ $run->project->name }}
                                        </span>
                                    @endif

                                    @if ($run->user)
                                        <span aria-hidden="true">&middot;</span>
                                        <span class="inline-flex items-center gap-1">
                                            <x-ui.avatar :user="$run->user" size="xs" />
                                            {{ $run->user->name }}
                                        </span>
                                    @endif

                                    <span aria-hidden="true">&middot;</span>
                                    <span x-data="relativeTime('{{ ($run->finished_at ?? $run->created_at)?->toIso8601String() }}')"
                                          x-text="label"></span>
                                </div>
                            </div>

                            <div class="hidden shrink-0 flex-col items-end gap-0.5 text-2xs tabular-nums
                                        text-[var(--text-subtle)] sm:flex">
                                @if ($run->durationSeconds() !== null)
                                    <span>{{ __(':seconds s', ['seconds' => Formats::number($run->durationSeconds(), 1)]) }}</span>
                                @endif
                                <span>
                                    {{ trans_choice('{1}:count call|[2,*]:count calls', (int) $run->tool_runs_count, [
                                        'count' => (int) $run->tool_runs_count,
                                    ]) }}
                                </span>
                                @if ((int) $run->tokens_in + (int) $run->tokens_out > 0)
                                    <span>
                                        {{ __(':in in / :out out', [
                                            'in' => Formats::number((int) $run->tokens_in),
                                            'out' => Formats::number((int) $run->tokens_out),
                                        ]) }}
                                    </span>
                                @endif
                            </div>
                        </button>

                        @if ($open)
                            <div class="space-y-2 border-t border-[var(--line-subtle)] bg-[var(--surface-sunken)]
                                        px-3 py-3 sm:px-4">

                                @if (filled($run->summary))
                                    <p dir="auto"
                                       class="border-s-2 border-[var(--line-DEFAULT)] ps-2.5 text-xs leading-relaxed
                                              text-[var(--text-DEFAULT)]">{{ Bidi::numbers((string) $run->summary) }}</p>
                                @endif

                                @if (filled($run->error))
                                    <p dir="auto"
                                       class="border-s-2 border-critical-500/60 ps-2.5 text-xs leading-relaxed
                                              text-critical-600 dark:text-critical-500">{{ Bidi::numbers((string) $run->error) }}</p>
                                @endif

                                @forelse ($this->openTrace as $line)
                                    <div wire:key="run-{{ $run->getKey() }}-trace-{{ $loop->index }}">
                                        @include('livewire.app.ai._trace', [
                                            'trace' => $line['trace'],
                                            'indent' => '',
                                        ])

                                        @if ($line['approver'])
                                            <p class="mt-1 ps-3 text-2xs text-[var(--text-subtle)]">
                                                {{ __('Approved by :name', ['name' => $line['approver']]) }}
                                                @if ($line['approvedAt'])
                                                    <span x-data="relativeTime('{{ $line['approvedAt']->toIso8601String() }}')"
                                                          x-text="label"></span>
                                                @endif
                                            </p>
                                        @endif
                                    </div>
                                @empty
                                    <p class="text-xs text-[var(--text-muted)]">
                                        {{ __('This run made no tool calls — the model answered from the context it was given.') }}
                                    </p>
                                @endforelse

                                {{-- A uuid and a model name are identifiers, not a sentence:
                                     they are read left to right in every language. --}}
                                <p class="pt-1 font-mono text-2xs text-[var(--text-subtle)]" dir="ltr">
                                    {{ $run->uuid }}@if (filled($run->model)) · {{ $run->model }} @endif
                                </p>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>

            <x-ui.pagination :paginator="$this->runs" />
        @endif
    </div>
</div>

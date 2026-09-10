{{--
    The conversation itself: what was said, and what was done, in the order it happened.

    User turns sit right and plain. The assistant speaks under the Planvio mark. Between
    them, every tool call the agent made is drawn as a trace line straight out of
    `ai_tool_runs`, so "I created the task" and "the task was created" are never the same
    claim — one is text, the other is the audit row underneath it.

    Expects: $compact (bool) — the drawer's narrow column
--}}
@php
    use App\Support\Bidi;

    $entries = $this->timeline;
    $progress = $this->progress;
    $compact = $compact ?? false;
    $indent = $compact ? '' : 'sm:ms-10';
    $conversation = $this->conversation;
    $scopeProject = $this->scopeProject();
@endphp

@if ($entries === [] && $progress === null)
    {{-- ---------------------------------------------------------------- --}}
    {{-- Nothing yet: say what this is for, and offer the first question.  --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="flex h-full flex-col items-center justify-center px-4 py-10 text-center">
        <span class="grid size-11 place-items-center rounded-xl border border-[var(--line-subtle)]
                     bg-[var(--surface-sunken)] text-[var(--text-strong)]">
            <x-ui.brand-mark class="size-5" />
        </span>

        <h3 class="mt-3 text-[0.9375rem] font-semibold text-[var(--text-strong)]">
            @if ($scopeProject)
                {{ __('Ask about :project', ['project' => $scopeProject->name]) }}
            @else
                {{ __('Ask Planvio AI') }}
            @endif
        </h3>

        <p class="mt-1 max-w-sm text-pretty text-xs leading-relaxed text-[var(--text-muted)]">
            {{ __('It sees only what you can see, acts only under your name, and every call it makes is listed here as it happens.') }}
        </p>

        <div class="mt-4 w-full max-w-md space-y-1.5">
            @foreach ($this->suggestions() as $suggestion)
                <button type="button"
                        x-on:click="$wire.ask(@js($suggestion))"
                        class="block w-full rounded-md border border-[var(--line-subtle)] bg-[var(--surface-sunken)]
                               px-3 py-2 text-start text-xs leading-relaxed text-[var(--text-DEFAULT)]
                               transition-colors hover:border-[var(--line-DEFAULT)] hover:bg-[var(--surface-hover)]">
                    {{ $suggestion }}
                </button>
            @endforeach
        </div>
    </div>
@else
    <div class="mx-auto w-full {{ $compact ? '' : 'max-w-3xl' }} space-y-4 px-4 py-5">

        @if ($conversation?->project)
            <p class="flex items-center justify-center gap-1.5 text-2xs text-[var(--text-subtle)]">
                <span class="size-1.5 rounded-full" style="background-color: {{ $conversation->project->color }}"
                      aria-hidden="true"></span>
                {{ __('Scoped to :project', ['project' => $conversation->project->name]) }}
            </p>
        @endif

        @foreach ($entries as $entry)
            <div wire:key="{{ $entry->key() }}">
                @if ($entry->kind === \App\Livewire\App\Ai\Support\TimelineEntry::USER)
                    {{--
                        Plain, no ornament: it is your own sentence. `dir="auto"` because it
                        is your own sentence in your own language — an English question inside
                        an Arabic interface reads left to right and keeps its full stop at its
                        own end, and an Arabic one inside an English interface does the mirror.
                    --}}
                    <div class="flex justify-end">
                        <div dir="auto"
                             class="max-w-[85%] whitespace-pre-wrap rounded-lg rounded-ee-sm border
                                    border-[var(--line-subtle)] bg-[var(--surface-sunken)] px-3.5 py-2.5
                                    text-sm leading-relaxed text-[var(--text-strong)]">{{ Bidi::numbers((string) $entry->message->content) }}</div>
                    </div>

                @elseif ($entry->kind === \App\Livewire\App\Ai\Support\TimelineEntry::ASSISTANT)
                    <div class="flex gap-3">
                        <span class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-md border
                                     border-[var(--line-subtle)] bg-[var(--surface-panel)] text-[var(--text-strong)]">
                            <x-ui.brand-mark class="size-4" />
                        </span>
                        {{--
                            Escaped, always. Model output is text, never markup: rendering it
                            as HTML would hand a prompt-injected response a script tag.
                        --}}
                        <div dir="auto"
                             class="min-w-0 flex-1 whitespace-pre-wrap pt-0.5 text-sm leading-relaxed
                                    text-[var(--text-DEFAULT)]">{{ Bidi::numbers((string) $entry->message->content) }}</div>
                    </div>

                @elseif ($entry->pendingApproval())
                    @include('livewire.app.ai._approval-card', [
                        'toolRun' => $entry->pendingApproval(),
                        'indent' => $indent,
                        'showObjective' => false,
                    ])

                @else
                    @include('livewire.app.ai._trace', ['trace' => $entry->trace, 'indent' => $indent])
                @endif
            </div>
        @endforeach

        {{-- ---------------------------------------------------------------- --}}
        {{-- The live status line                                             --}}
        {{-- ---------------------------------------------------------------- --}}
        @if ($progress)
            <div class="{{ $indent }} flex items-start gap-2.5 rounded-md border border-dashed
                        border-[var(--line-DEFAULT)] bg-[var(--surface-sunken)] px-3 py-2.5">
                @if ($progress->live)
                    <x-ui.spinner class="mt-0.5 size-3.5 shrink-0 text-[var(--text-subtle)]" />
                @else
                    <x-ui.status-dot :color="$progress->tone" size="sm" class="mt-[0.4375rem]" />
                @endif

                <div class="min-w-0 flex-1">
                    <p class="text-xs font-medium text-[var(--text-strong)]">{{ $progress->headline }}</p>
                    @if ($progress->detail)
                        <p class="mt-0.5 text-2xs leading-relaxed text-[var(--text-muted)]">{{ $progress->detail }}</p>
                    @endif
                </div>
            </div>
        @endif

        {{--
            The key changes whenever the thread grows, so Livewire replaces this node and
            Alpine re-initialises it — which is what keeps the newest turn in view without a
            scroll listener running on every frame.
        --}}
        <div wire:key="thread-foot-{{ count($entries) }}-{{ $progress?->headline }}"
             x-data
             x-init="$nextTick(() => $el.scrollIntoView({ block: 'end' }))"
             aria-hidden="true"></div>
    </div>
@endif

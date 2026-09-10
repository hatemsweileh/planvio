{{--
    One tool call, as a person needs to read it.

    This block is the product's credibility. Everything on it comes from the `ai_tool_runs`
    row the agent wrote as it ran — the tool, a readable line of the arguments, the result it
    reported, how long it took and where it ended up — so a reader never has to take the
    assistant's own account of what it did. The snake_case name sits next to the human label
    on purpose: it is the string that appears in the policy allow-list and in the audit log,
    and hiding it would make those two things unfindable from here.

    Expects: $trace (App\Livewire\App\Ai\Support\ToolTrace), $indent (string)
--}}
@php
    use App\Support\Bidi;
@endphp
<div class="{{ $indent }} rounded-md border border-[var(--line-subtle)] bg-[var(--surface-sunken)] px-3 py-2">
    <div class="flex items-start gap-2.5">
        <x-ui.status-dot :color="$trace->tone()" size="sm" class="mt-[0.4375rem]" />

        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <span class="text-xs font-medium text-[var(--text-strong)]">{{ $trace->label }}</span>

                <code class="font-mono text-2xs text-[var(--text-subtle)]"
                      dir="ltr">{{ $trace->tool }}</code>

                @if ($trace->isMutating())
                    <x-ui.badge :color="$trace->risk->color()" size="sm">{{ $trace->risk->label() }}</x-ui.badge>
                @endif

                <span class="ms-auto flex shrink-0 items-center gap-2 text-2xs tabular-nums text-[var(--text-subtle)]">
                    @if ($trace->duration)
                        <span>{{ $trace->duration }}</span>
                    @endif
                    <span class="text-[var(--text-muted)]">{{ $trace->statusLabel() }}</span>
                </span>
            </div>

            {{--
                One isolate per argument, rather than one line of text.

                The line is `key: value · key: value`, its keys always Latin and its values
                whatever the caller passed — so unisolated it is several runs of opposing
                direction separated by neutrals, and the bidi algorithm reorders the *pairs*
                against each other: `title · project id · priority · due date` comes out
                `title · due date · priority · project id`. Each pair is its own isolate, so
                the pairs stay in the order they were recorded and each one reads in the
                direction its own content implies.
            --}}
            <p class="mt-0.5 flex flex-wrap gap-x-1.5 break-words font-mono text-2xs leading-relaxed
                      text-[var(--text-muted)]">
                @foreach ($trace->argumentPairs() as $pair)
                    <span dir="auto" class="bidi-isolate">{{ Bidi::numbers($pair) }}</span>
                    @unless ($loop->last)
                        <span aria-hidden="true">&middot;</span>
                    @endunless
                @endforeach
            </p>

            @if ($trace->result)
                {{--
                    `dir="auto"` because a tool writes its summary in the language of the run,
                    and the dates and ids inside it are isolated because an Arabic word before
                    a number turns it into an Arabic number and takes its separators away.
                --}}
                <p dir="auto"
                   class="mt-1.5 border-s-2 border-[var(--line-DEFAULT)] ps-2.5 text-xs leading-relaxed
                          text-[var(--text-DEFAULT)]">
                    {{ Bidi::numbers($trace->result) }}
                </p>
            @endif

            @if ($trace->error)
                {{--
                    A failed call is stated, not softened. The model has to report it too, but
                    a person reading the thread should not have to infer it from the absence
                    of a result.
                --}}
                <p dir="auto"
                   class="mt-1.5 border-s-2 border-critical-500/60 ps-2.5 text-xs leading-relaxed text-critical-600
                          dark:text-critical-500">
                    {{ Bidi::numbers($trace->error) }}
                </p>
            @endif
        </div>
    </div>
</div>

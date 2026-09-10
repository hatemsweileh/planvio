{{--
    One line of dated work on the dashboard.

    Everything it needs arrives as a variable — `$task`, `$tone`, `$due` and the two class
    maps — rather than being derived here, so the partial stays a piece of markup and the
    parent keeps the single loop that decides what a row means.

    @var \App\Models\Task $task
    @var string $tone     one of late|today|soon|later|none
    @var string $due      the rendered due label
    @var array<string, string> $dueText
    @var array<string, string> $dueDot
--}}
<a href="{{ route('app.tasks.show', [$workspace, $task]) }}"
   class="group flex items-start gap-3 px-4 py-2.5 transition-colors hover:bg-[var(--surface-hover)]">

    <span class="mt-1.5 size-1.5 shrink-0 rounded-full {{ $dueDot[$tone] ?? $dueDot['none'] }}"
          aria-hidden="true"></span>

    <span class="min-w-0 flex-1">
        <span dir="auto" class="block truncate text-sm font-medium text-[var(--text-strong)] group-hover:text-[var(--accent)]">
            {{ $task->title }}
        </span>

        <span class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-2xs text-[var(--text-muted)]">
            <span class="font-mono text-[var(--text-subtle)]"><x-ui.bidi>{{ $task->key }}</x-ui.bidi></span>

            @if ($task->project)
                <span class="inline-flex min-w-0 items-center gap-1">
                    <span class="size-1.5 shrink-0 rounded-full"
                          style="background-color: {{ $task->project->color }}" aria-hidden="true"></span>
                    <span dir="auto" class="truncate">{{ $task->project->name }}</span>
                </span>
            @endif

            @if (! in_array($task->priority, [\App\Enums\Priority::None, \App\Enums\Priority::Medium], true))
                <x-ui.badge :color="$task->priority->color()" size="sm">{{ $task->priority->label() }}</x-ui.badge>
            @endif
        </span>
    </span>

    <span class="shrink-0 whitespace-nowrap text-xs font-medium {{ $dueText[$tone] ?? $dueText['none'] }}">
        {{ $due }}
    </span>
</a>

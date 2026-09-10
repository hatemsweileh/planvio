@php
    use App\Support\Formats;

    /**
     * A short list of tasks inside the status report.
     *
     * Expects `$tasks`, `$dateField` (`completed_at` or `due_date`), `$emptyText` and
     * `$tone` (`neutral` or `critical`).
     */
@endphp

@if ($tasks->isEmpty())
    <p class="px-4 py-3 text-xs text-[var(--text-subtle)]">{{ $emptyText }}</p>
@else
    <ul class="divide-y divide-[var(--line-subtle)]">
        @foreach ($tasks as $task)
            @php
                $date = Formats::date($dateField === 'completed_at' ? $task->completed_at : $task->due_date);
            @endphp
            <li class="flex items-center gap-2 px-4 py-1.5 print-avoid-break">
                <span class="w-16 shrink-0 font-mono text-[10px] text-[var(--text-subtle)]"><x-ui.bidi>{{ $task->key }}</x-ui.bidi></span>
                <span dir="auto" class="min-w-0 flex-1 truncate text-xs text-[var(--text-DEFAULT)]">{{ $task->title }}</span>
                @if ($task->assignee)
                    <span class="hidden shrink-0 text-2xs text-[var(--text-muted)] sm:inline">{{ $task->assignee->name }}</span>
                @endif
                <span class="shrink-0 whitespace-nowrap text-end text-2xs tabular-nums
                             {{ ($tone ?? 'neutral') === 'critical' ? 'font-semibold text-critical-600 dark:text-critical-500' : 'text-[var(--text-muted)]' }}">
                    {{ $date }}
                </span>
            </li>
        @endforeach
    </ul>
@endif

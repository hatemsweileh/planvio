<div class="flex h-full min-h-0 flex-col">
    <div class="flex items-center gap-2 border-b border-[var(--line-subtle)] bg-[var(--surface-panel)]
                px-3 pt-2.5 sm:px-4">
        <h1 class="min-w-0 truncate text-sm font-semibold tracking-tight text-[var(--text-strong)]">
            <a href="{{ route('app.projects.show', [$workspace, $project]) }}"
               class="hover:text-[var(--accent)]">{{ $project->name }}</a>
            <span class="text-[var(--text-subtle)]">/</span>
            <span class="font-normal text-[var(--text-muted)]">{{ __('Calendar') }}</span>
        </h1>
    </div>

    @include('livewire.app.calendar._grid', ['scopedProject' => $project])
</div>

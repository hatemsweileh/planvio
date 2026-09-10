<div class="page py-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 dir="auto" class="truncate text-base font-semibold tracking-tight text-[var(--text-strong)]">
            {{ $project->name }} <span class="text-[var(--text-subtle)]">/</span> {{ __('Activity') }}
        </h2>
    </div>

    <div class="mt-4 rounded-lg border border-[var(--line-subtle)] bg-[var(--surface-panel)] shadow-panel">
        <x-ui.empty-state icon="icon.clock"
                          :title="__('No activity yet')"
                          :description="__('Every change to this project is recorded here, by whom and when — the AI included.')">
            <x-slot:actions>
            <x-ui.button :href="route('app.projects.show', [$workspace, $project])" variant="secondary" size="md" icon="icon.folder">
                {{ __('Project overview') }}
            </x-ui.button>
            </x-slot:actions>
        </x-ui.empty-state>
    </div>
</div>

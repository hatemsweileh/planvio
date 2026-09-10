<x-ui.dropdown align="end" width="w-52">
    <x-slot:trigger>
        <x-ui.tooltip :label="__('Create').'  ·  C'">
            <x-ui.button variant="primary" size="md" icon-only :aria-label="__('Create')">
                <x-icon.plus class="size-4" />
            </x-ui.button>
        </x-ui.tooltip>
    </x-slot:trigger>

    <x-ui.dropdown-item x-on:click="$dispatch('open-quick-create', { type: 'task' })"
                        icon="icon.check-circle" shortcut="C">
        {{ __('Task') }}
    </x-ui.dropdown-item>

    @can('project.create', [\App\Models\Project::class, $workspace])
        <x-ui.dropdown-item :href="route('app.projects.create', $workspace)" icon="icon.folder">
            {{ __('Project') }}
        </x-ui.dropdown-item>
    @endcan

    <x-ui.dropdown-item x-on:click="$dispatch('open-quick-create', { type: 'milestone' })" icon="icon.flag">
        {{ __('Milestone') }}
    </x-ui.dropdown-item>

    <x-ui.dropdown-item x-on:click="$dispatch('open-quick-create', { type: 'document' })" icon="icon.document">
        {{ __('Document') }}
    </x-ui.dropdown-item>

    @can('ai.use', $workspace)
        <x-ui.dropdown-separator />
        <x-ui.dropdown-item x-on:click="$dispatch('open-ai-panel', { intent: 'create' })" icon="icon.sparkles">
            {{ __('Create with AI') }}
        </x-ui.dropdown-item>
    @endcan

    @can('workspace.manage', $workspace)
        <x-ui.dropdown-separator />
        <x-ui.dropdown-item :href="route('app.teams', $workspace)" icon="icon.users">
            {{ __('Invite people') }}
        </x-ui.dropdown-item>
    @endcan
</x-ui.dropdown>

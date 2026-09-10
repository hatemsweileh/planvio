{{--
    The assistant, offered on a task.

    Each item hands the drawer an objective with this task named in it *and* the task itself
    as scope, so the run is recorded against the right project and the tools start from the
    right record rather than from a title the model has to go and find.

    The objective lands in the composer rather than being sent: the person clicked for the
    assistant, not for the assistant to have already acted, and reading the exact sentence
    before it goes is the whole difference. Nothing here changes the task on its own — and
    with `ai.use` withheld, none of it renders at all.
--}}
@can('ai.use', $workspace)
    <x-ui.dropdown align="end" width="w-64">
        <x-slot:trigger>
            <x-ui.tooltip :label="__('Planvio AI')">
                <x-ui.button size="md" variant="ghost" icon-only :aria-label="__('Planvio AI actions')">
                    <x-icon.sparkles class="size-4" />
                </x-ui.button>
            </x-ui.tooltip>
        </x-slot:trigger>

        <p class="px-2 pb-1 pt-1 text-2xs font-semibold uppercase tracking-wider text-[var(--text-subtle)]">
            {{ __('With this task') }}
        </p>

        @foreach ($this->aiPrompts() as $key => $action)
            {{--
                `Js::from` written out rather than the `@js` directive: a Blade directive
                inside an `<x-…>` tag attribute is not compiled, so `@js(...)` reached the
                browser verbatim and every one of these items threw a syntax error instead
                of opening the panel. `{{ }}` renders a Htmlable unescaped, so this is the
                same JSON the directive would have produced.
            --}}
            <x-ui.dropdown-item icon="icon.sparkles"
                                x-on:click="$dispatch('open-ai-panel', {
                                    prompt: {{ \Illuminate\Support\Js::from($action['prompt']) }},
                                    taskId: {{ (int) $task->getKey() }},
                                    projectId: {{ (int) $task->project_id }},
                                })">
                {{ $action['label'] }}
            </x-ui.dropdown-item>
        @endforeach
    </x-ui.dropdown>
@endcan

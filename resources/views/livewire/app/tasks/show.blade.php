@php $task = $this->task; @endphp

<div class="mx-auto w-full max-w-3xl">
    @if ($task)
        @php $ws = $task->workspace; @endphp

        <div class="sticky top-0 z-10 flex h-14 items-center gap-2 border-b border-[var(--line-subtle)]
                    bg-[var(--surface-panel)] px-4 sm:px-5">
            <nav class="flex min-w-0 flex-1 items-center gap-1.5 text-xs text-[var(--text-muted)]"
                 aria-label="{{ __('Breadcrumb') }}">
                <a href="{{ route('app.projects.show', [$ws, $task->project]) }}"
                   class="inline-flex min-w-0 items-center gap-1.5 transition-colors hover:text-[var(--text-DEFAULT)]">
                    <span class="size-2 shrink-0 rounded-full" style="background-color: {{ $task->project->color }}"
                          aria-hidden="true"></span>
                    <span dir="auto" class="truncate">{{ $task->project->name }}</span>
                </a>
                <span aria-hidden="true">/</span>
                <a href="{{ route('app.projects.tasks', [$ws, $task->project]) }}"
                   class="transition-colors hover:text-[var(--text-DEFAULT)]">{{ __('Tasks') }}</a>
                <span aria-hidden="true">/</span>
                <span class="font-mono tabular-nums text-[var(--text-subtle)]"><x-ui.bidi>{{ $task->key }}</x-ui.bidi></span>
            </nav>

            <div class="flex items-center gap-1">
                @include('livewire.app.tasks._ai-actions', ['task' => $task, 'workspace' => $ws])

                <x-ui.tooltip :label="$this->isWatching() ? __('Stop watching') : __('Watch this task')">
                    <x-ui.button size="md" variant="ghost" icon-only wire:click="toggleWatch"
                                 :aria-label="$this->isWatching() ? __('Stop watching') : __('Watch this task')"
                                 class="{{ $this->isWatching() ? 'text-[var(--accent)]' : '' }}">
                        <x-icon.bell class="size-4" />
                    </x-ui.button>
                </x-ui.tooltip>

                <x-ui.dropdown align="end" width="w-56">
                    <x-slot:trigger>
                        <x-ui.button size="md" variant="ghost" icon-only :aria-label="__('More actions')">
                            <x-icon.dots class="size-4" />
                        </x-ui.button>
                    </x-slot:trigger>

                    <div x-data="copyable('{{ route('app.tasks.show', [$ws, $task]) }}')">
                        <x-ui.dropdown-item x-on:click.stop="copy()" icon="icon.paperclip">
                            <span x-show="! copied">{{ __('Copy link') }}</span>
                            <span x-show="copied" x-cloak class="text-[var(--accent)]">{{ __('Copied') }}</span>
                        </x-ui.dropdown-item>
                    </div>

                    <x-ui.dropdown-item :href="route('app.projects.board', [$ws, $task->project])" icon="icon.board">
                        {{ __('Open the board') }}
                    </x-ui.dropdown-item>

                    @can('delete', $task)
                        <x-ui.dropdown-separator />
                        <x-ui.dropdown-item danger icon="icon.trash" wire:click="confirmTaskDeletion">
                            {{ __('Delete task') }}
                        </x-ui.dropdown-item>
                    @endcan
                </x-ui.dropdown>
            </div>
        </div>

        {{-- The same body the drawer renders. One implementation, two frames. --}}
        @include('livewire.app.tasks._body', ['task' => $task])
    @else
        <div class="px-4 py-10 sm:px-5">
            <x-ui.card flush>
                <x-ui.empty-state icon="icon.warning"
                                  :title="__('This task is no longer here')"
                                  :description="__('It may have been deleted, or moved somewhere you cannot see.')">
                    <x-slot:actions>
                        <x-ui.button variant="secondary" size="md" :href="route('app.home', $workspace)">
                            {{ __('Back to the workspace') }}
                        </x-ui.button>
                    </x-slot:actions>
                </x-ui.empty-state>
            </x-ui.card>
        </div>
    @endif
</div>

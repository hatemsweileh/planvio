<div>
    @php $task = $this->task; @endphp

    @if ($task)
        @php $ws = $task->workspace; @endphp

        <x-ui.drawer wire:model="open" size="lg" :title="$task->title">

            <header class="flex h-14 shrink-0 items-center gap-2 border-b border-[var(--line-subtle)] px-4 sm:px-5">
                <a href="{{ route('app.projects.show', [$ws, $task->project]) }}"
                   class="inline-flex min-w-0 items-center gap-1.5 text-xs text-[var(--text-muted)]
                          transition-colors hover:text-[var(--text-DEFAULT)]">
                    <span class="size-2 shrink-0 rounded-full" style="background-color: {{ $task->project->color }}"
                          aria-hidden="true"></span>
                    <span dir="auto" class="truncate">{{ $task->project->name }}</span>
                </a>

                <span class="font-mono text-2xs tabular-nums text-[var(--text-subtle)]"><x-ui.bidi>{{ $task->key }}</x-ui.bidi></span>

                <div class="ms-auto flex items-center gap-1">
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

                        <x-ui.dropdown-item :href="route('app.tasks.show', [$ws, $task])" icon="icon.document">
                            {{ __('Open as a page') }}
                        </x-ui.dropdown-item>

                        <div x-data="copyable('{{ route('app.tasks.show', [$ws, $task]) }}')">
                            <x-ui.dropdown-item x-on:click.stop="copy()" icon="icon.paperclip">
                                <span x-show="! copied">{{ __('Copy link') }}</span>
                                <span x-show="copied" x-cloak class="text-[var(--accent)]">{{ __('Copied') }}</span>
                            </x-ui.dropdown-item>
                        </div>

                        @can('delete', $task)
                            <x-ui.dropdown-separator />
                            <x-ui.dropdown-item danger icon="icon.trash" wire:click="confirmTaskDeletion">
                                {{ __('Delete task') }}
                            </x-ui.dropdown-item>
                        @endcan
                    </x-ui.dropdown>

                    <button type="button" wire:click="closeDrawer"
                            class="grid size-8 shrink-0 place-items-center rounded-md text-[var(--text-subtle)]
                                   transition-colors hover:bg-[var(--surface-hover)] hover:text-[var(--text-DEFAULT)]"
                            aria-label="{{ __('Close') }}">
                        <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75">
                            <path d="m5 5 10 10M15 5 5 15" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>
            </header>

            <div class="scrollbar-thin min-h-0 flex-1 overflow-y-auto">
                @include('livewire.app.tasks._body', ['task' => $task])
            </div>
        </x-ui.drawer>
    @endif
</div>

{{--
    Saved views.

    A saved view is nothing more mysterious than the filter bar written down, so the menu
    that lists them is also the place they are created: whatever is on screen right now is
    what "Save this view" stores.
--}}
<x-ui.dropdown align="end" width="w-72">
    <x-slot:trigger>
        @php
            $applied = $appliedViewId === null ? null : $this->savedViews->firstWhere('id', $appliedViewId);
        @endphp
        <x-ui.button size="md" variant="{{ $applied ? 'soft' : 'secondary' }}" icon="icon.star"
                     trailing-icon="icon.chevron-down">
            <span class="max-w-32 truncate">{{ $applied?->name ?? __('Views') }}</span>
        </x-ui.button>
    </x-slot:trigger>

    @if ($this->savedViews->isNotEmpty())
        <div class="max-h-64 overflow-y-auto scrollbar-thin">
            @foreach ($this->savedViews as $view)
                <div class="group flex items-center gap-1 rounded pe-1 hover:bg-[var(--surface-hover)]
                            {{ $appliedViewId === (int) $view->getKey() ? 'bg-[var(--surface-hover)]' : '' }}">
                    <button type="button"
                            wire:click="applySavedView({{ $view->getKey() }})"
                            x-on:click="open = false"
                            class="flex min-w-0 flex-1 items-center gap-2 rounded px-2 py-1.5 text-start text-sm
                                   text-[var(--text-DEFAULT)]">
                        <x-ui.tick :on="$appliedViewId === (int) $view->getKey()" />
                        <span dir="auto" class="truncate">{{ $view->name }}</span>
                        @if ($view->is_shared || $view->user_id === null)
                            <x-ui.badge color="gray" size="sm">{{ __('Shared') }}</x-ui.badge>
                        @endif
                    </button>

                    <x-ui.tooltip :label="$view->is_pinned ? __('Unpin') : __('Pin')">
                        <button type="button"
                                wire:click="togglePinnedView({{ $view->getKey() }})"
                                class="grid size-6 shrink-0 place-items-center rounded transition-colors
                                       hover:bg-[var(--surface-active)]
                                       {{ $view->is_pinned ? 'text-[var(--accent)]' : 'text-[var(--text-subtle)] opacity-0 group-hover:opacity-100 focus-visible:opacity-100' }}"
                                aria-label="{{ $view->is_pinned ? __('Unpin :name', ['name' => $view->name]) : __('Pin :name', ['name' => $view->name]) }}">
                            <x-icon.star class="size-3.5" />
                        </button>
                    </x-ui.tooltip>

                    @can('delete', $view)
                        <x-ui.tooltip :label="__('Delete view')">
                            <button type="button"
                                    wire:click="deleteSavedView({{ $view->getKey() }})"
                                    wire:confirm="{{ __('Delete the view “:name”? This cannot be undone.', ['name' => $view->name]) }}"
                                    class="grid size-6 shrink-0 place-items-center rounded text-[var(--text-subtle)]
                                           opacity-0 transition-colors hover:bg-critical-50 hover:text-critical-600
                                           focus-visible:opacity-100 group-hover:opacity-100
                                           dark:hover:bg-critical-950"
                                    aria-label="{{ __('Delete :name', ['name' => $view->name]) }}">
                                <x-icon.trash class="size-3.5" />
                            </button>
                        </x-ui.tooltip>
                    @endcan
                </div>
            @endforeach
        </div>

        <x-ui.dropdown-separator />
    @endif

    {{-- Saving is a form, so clicking inside it must not close the menu. --}}
    <form wire:submit="saveCurrentView" x-on:click.stop class="space-y-2 p-2">
        <p class="text-2xs font-semibold uppercase tracking-wider text-[var(--text-subtle)]">
            {{ __('Save this view') }}
        </p>

        <x-ui.input wire:model="newViewName"
                    :value="$newViewName"
                    size="sm"
                    maxlength="255"
                    :placeholder="__('e.g. Overdue and unassigned')"
                    :aria-label="__('View name')" />

        @can('create', [\App\Models\SavedView::class, $project])
            {{--
                `:checked` rather than Blade's `@checked` directive: inside an `<x-…>` tag
                that directive defeats the component compiler, and the tag is then emitted
                verbatim — so the checkbox is simply absent from the menu and Alpine tries
                to evaluate `:label` as one of its own bindings. Same reason the pager binds
                `:disabled`.
            --}}
            <x-ui.checkbox wire:model="newViewShared" :checked="$newViewShared"
                           :label="__('Share with the workspace')"
                           :description="__('Everyone here sees it in this menu.')" />
        @endcan

        <x-ui.button type="submit" variant="secondary" size="sm" class="w-full" wire:target="saveCurrentView">
            {{ __('Save view') }}
        </x-ui.button>
    </form>
</x-ui.dropdown>

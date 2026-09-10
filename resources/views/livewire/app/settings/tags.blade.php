@php
    $canCreate = auth()->user()->can('create', [\App\Models\Tag::class, $workspace]);
@endphp

<div>
    <x-ui.card flush>
        <x-slot:header>
            <p class="text-sm font-semibold text-[var(--text-strong)]">{{ __('Tags') }}</p>
            <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                {{ __('One shared vocabulary across tasks and projects.') }}
            </p>
        </x-slot:header>

        <x-slot:actions>
            <div class="flex items-center gap-2">
                <div class="w-40 sm:w-48">
                    <label for="tag-search" class="sr-only">{{ __('Search tags') }}</label>
                    <x-ui.input id="tag-search" size="sm" type="search" icon="icon.search"
                                wire:model.live.debounce.300ms="search" busy-target="search" :placeholder="__('Search')" />
                </div>
                @if ($canCreate)
                    <x-ui.button variant="secondary" size="sm" icon="icon.plus" wire:click="startCreate">
                        {{ __('Add tag') }}
                    </x-ui.button>
                @endif
            </div>
        </x-slot:actions>

        @if ($this->tags->isEmpty())
            <x-ui.empty-state icon="icon.tag"
                              :title="filled($search) ? __('No tag matches that') : __('No tags yet')"
                              :description="filled($search)
                                  ? __('Try a shorter search.')
                                  : __('Tags cut across projects: “Urgent”, “Client”, “Needs design”. They are how a filter finds work that no single project owns.')"
                              compact>
                <x-slot:actions>
                    @if (filled($search))
                        <x-ui.button variant="secondary" size="md" wire:click="$set('search', '')">
                            {{ __('Clear search') }}
                        </x-ui.button>
                    @elseif ($canCreate)
                        <x-ui.button variant="primary" size="md" icon="icon.plus" wire:click="startCreate">
                            {{ __('Create the first tag') }}
                        </x-ui.button>
                    @endif
                </x-slot:actions>
            </x-ui.empty-state>
        @else
            <ul class="divide-y divide-[var(--line-subtle)]">
                @foreach ($this->tags as $tag)
                    <li wire:key="tag-{{ $tag->getKey() }}"
                        class="group/row flex items-center gap-3 px-3 py-2.5 transition-colors
                               hover:bg-[var(--surface-hover)]">
                        <x-ui.badge :color="$tag->color ?: 'gray'" dot>{{ $tag->name }}</x-ui.badge>

                        <span class="min-w-0 flex-1 truncate text-xs text-[var(--text-muted)]">
                            {{ $tag->description }}
                        </span>

                        <span class="shrink-0 text-xs tabular-nums text-[var(--text-subtle)]">
                            {{ __(':tasks tasks · :projects projects', [
                                'tasks' => $tag->tasks_count,
                                'projects' => $tag->projects_count,
                            ]) }}
                        </span>

                        @can('update', $tag)
                            <div class="flex shrink-0 items-center gap-1 opacity-0 transition-opacity
                                        focus-within:opacity-100 group-hover/row:opacity-100">
                                <x-ui.button variant="ghost" size="sm" icon-only
                                             wire:click="startEdit({{ $tag->getKey() }})"
                                             :aria-label="__('Edit :tag', ['tag' => $tag->name])">
                                    <x-icon.cog class="size-4" />
                                </x-ui.button>
                                @can('delete', $tag)
                                    <x-ui.button variant="ghost" size="sm" icon-only
                                                 wire:click="deleteTag({{ $tag->getKey() }})"
                                                 wire:confirm="{{ __('Delete “:tag”? It is removed from everything it labels.', ['tag' => $tag->name]) }}"
                                                 :aria-label="__('Delete :tag', ['tag' => $tag->name])">
                                        <x-icon.trash class="size-4" />
                                    </x-ui.button>
                                @endcan
                            </div>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    <x-ui.modal wire:model="showForm" :title="$editingId ? __('Edit tag') : __('New tag')" size="md">
        <div class="space-y-4">
            <x-ui.field :label="__('Name')" for="tag-name" :error="$errors->first('name')" required>
                <x-ui.input id="tag-name" wire:model="name" maxlength="60"
                            :placeholder="__('Urgent, Client, Needs design')"
                            :invalid="$errors->has('name')" />
            </x-ui.field>

            <x-ui.field :label="__('Description')" for="tag-description" :error="$errors->first('description')"
                        :hint="__('Optional. What this tag is for, so it keeps meaning one thing.')">
                <x-ui.input id="tag-description" wire:model="description" maxlength="255"
                            :invalid="$errors->has('description')" />
            </x-ui.field>

            <div>
                <p class="mb-1.5 text-xs font-medium text-[var(--text-DEFAULT)]">{{ __('Colour') }}</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($this->colors() as $swatch)
                        <button type="button" wire:click="$set('color', '{{ $swatch }}')"
                                class="rounded-md p-0.5 transition-shadow
                                       {{ $color === $swatch ? 'ring-2 ring-[var(--accent)]' : '' }}"
                                aria-pressed="{{ $color === $swatch ? 'true' : 'false' }}"
                                aria-label="{{ \App\Support\ChartPalette::label($swatch) }}">
                            <x-ui.badge :color="$swatch" dot>{{ \App\Support\ChartPalette::label($swatch) }}</x-ui.badge>
                        </button>
                    @endforeach
                </div>
                @error('color')
                    <p class="mt-1 text-xs text-critical-600" role="alert">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <x-slot:footer>
            <x-ui.button variant="ghost" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="primary" wire:click="save" wire:target="save">
                {{ $editingId ? __('Save tag') : __('Add tag') }}
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>

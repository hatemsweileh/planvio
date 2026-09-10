@php
    $canCreate = auth()->user()->can('create', [\App\Models\ProjectStatus::class, $workspace]);
    $deleting = $deletingId ? $this->statuses->firstWhere('id', $deletingId) : null;
@endphp

<div>
    <x-ui.card flush>
        <x-slot:header>
            <p class="text-sm font-semibold text-[var(--text-strong)]">{{ __('Statuses') }}</p>
            <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                {{ __('Shared by every project. Drag to reorder — the order is the order they appear in menus and on the portfolio board.') }}
            </p>
        </x-slot:header>

        <x-slot:actions>
            @if ($canCreate)
                <x-ui.button variant="secondary" size="sm" icon="icon.plus" wire:click="startCreate">
                    {{ __('Add status') }}
                </x-ui.button>
            @endif
        </x-slot:actions>

        @if ($this->statuses->isEmpty())
            <x-ui.empty-state icon="icon.board"
                              :title="__('No project statuses')"
                              :description="__('Without at least one status a project has no lifecycle. Add the stages your work actually moves through — Planning, Active, On hold, Done.')"
                              compact>
                <x-slot:actions>
                    @if ($canCreate)
                        <x-ui.button variant="primary" size="md" icon="icon.plus" wire:click="startCreate">
                            {{ __('Add the first status') }}
                        </x-ui.button>
                    @endif
                </x-slot:actions>
            </x-ui.empty-state>
        @else
            <ul x-data="sortableList('reorderStatuses')" class="divide-y divide-[var(--line-subtle)]">
                @foreach ($this->statuses as $status)
                    <li data-sort-id="{{ $status->getKey() }}" wire:key="status-{{ $status->getKey() }}"
                        class="group/row flex items-center gap-2 px-3 py-2.5 transition-colors
                               hover:bg-[var(--surface-hover)]">

                        <span data-drag-handle
                              class="hidden size-5 shrink-0 cursor-grab place-items-center rounded
                                     text-[var(--text-subtle)] opacity-0 transition-opacity
                                     group-hover/row:opacity-100 sm:grid"
                              aria-hidden="true" title="{{ __('Drag to reorder') }}">
                            <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor">
                                <circle cx="8" cy="5" r="1.2"/><circle cx="12" cy="5" r="1.2"/>
                                <circle cx="8" cy="10" r="1.2"/><circle cx="12" cy="10" r="1.2"/>
                                <circle cx="8" cy="15" r="1.2"/><circle cx="12" cy="15" r="1.2"/>
                            </svg>
                        </span>

                        <x-ui.badge :color="$status->color" dot>{{ $status->name }}</x-ui.badge>

                        @if ($status->is_default)
                            <span class="text-2xs font-medium uppercase tracking-wide text-[var(--text-subtle)]">
                                {{ __('Default') }}
                            </span>
                        @endif

                        <span class="ms-auto text-xs text-[var(--text-muted)]">
                            {{ $status->category?->label() }}
                        </span>

                        <span class="w-20 shrink-0 text-end text-xs tabular-nums text-[var(--text-subtle)]">
                            {{ trans_choice('{0} unused|{1} :count project|[2,*] :count projects', $status->projects_count, ['count' => $status->projects_count]) }}
                        </span>

                        @can('update', $status)
                            <x-ui.dropdown align="end" width="w-48">
                                <x-slot:trigger>
                                    <x-ui.button variant="ghost" size="sm" icon-only
                                                 :aria-label="__('Actions for :name', ['name' => $status->name])">
                                        <x-icon.dots class="size-4" />
                                    </x-ui.button>
                                </x-slot:trigger>
                                <x-ui.dropdown-item icon="icon.cog" wire:click="startEdit({{ $status->getKey() }})">
                                    {{ __('Edit status') }}
                                </x-ui.dropdown-item>
                                @can('delete', $status)
                                    <x-ui.dropdown-separator />
                                    <x-ui.dropdown-item icon="icon.trash" danger
                                                        wire:click="startDelete({{ $status->getKey() }})">
                                        {{ __('Delete status') }}
                                    </x-ui.dropdown-item>
                                @endcan
                            </x-ui.dropdown>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Add / edit                                                       --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.modal wire:model="showForm" :title="$editingId ? __('Edit status') : __('New status')" size="md">
        <div class="space-y-4">
            <x-ui.field :label="__('Name')" for="status-name" :error="$errors->first('name')" required>
                <x-ui.input id="status-name" wire:model="name" maxlength="60"
                            :placeholder="__('Active, On hold, Delivered')"
                            :invalid="$errors->has('name')" />
            </x-ui.field>

            <x-ui.field :label="__('Category')" for="status-category" :error="$errors->first('category')"
                        :hint="__('What this stage means to reporting: a status in a “done” category counts a project as finished.')" required>
                <x-ui.select id="status-category" wire:model="category" :invalid="$errors->has('category')">
                    @foreach (\App\Enums\StatusCategory::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </x-ui.select>
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

            <x-ui.checkbox wire:model="isDefault"
                           :label="__('Start new projects here')"
                           :description="__('Only one status can be the default. Choosing this moves the flag.')" />
        </div>

        <x-slot:footer>
            <x-ui.button variant="ghost" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="primary" wire:click="save" wire:target="save">
                {{ $editingId ? __('Save status') : __('Add status') }}
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Delete, which has to say where the projects go                   --}}
    {{-- ---------------------------------------------------------------- --}}
    @if ($deleting)
        <x-ui.modal wire:model="deletingId" :title="__('Delete “:name”', ['name' => $deleting->name])" size="md">
            <div class="space-y-3">
                <p class="text-sm leading-relaxed text-[var(--text-DEFAULT)]">
                    {{ trans_choice(
                        '{0} No project uses this status, so nothing moves.
                         |{1} One project uses this status. Choose where it should go.
                         |[2,*] :count projects use this status. Choose where they should go.',
                        $deleting->projects_count,
                        ['count' => $deleting->projects_count],
                    ) }}
                </p>

                @if ($deleting->projects_count > 0)
                    <x-ui.field :label="__('Move those projects to')" for="status-reassign">
                        <x-ui.select id="status-reassign" wire:model="reassignTo">
                            <option value="">{{ __('No status') }}</option>
                            @foreach ($this->statuses as $option)
                                @continue((int) $option->getKey() === (int) $deleting->getKey())
                                <option value="{{ $option->getKey() }}">{{ $option->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                @endif
            </div>

            <x-slot:footer>
                <x-ui.button variant="ghost" wire:click="cancelDelete">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="danger" wire:click="confirmDelete" wire:target="confirmDelete">
                    {{ __('Delete status') }}
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>

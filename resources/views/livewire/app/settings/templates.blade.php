@php
    $canManage = auth()->user()->can('create', [\App\Models\ProjectTemplate::class, $workspace]);
@endphp

<div class="space-y-4">

    {{-- ---------------------------------------------------------------- --}}
    {{-- The workspace's own templates                                    --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card flush>
        <x-slot:header>
            <p class="text-sm font-semibold text-[var(--text-strong)]">{{ __('Your templates') }}</p>
            <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                {{ __('Authored in this workspace. Editable, and offered whenever somebody creates a project.') }}
            </p>
        </x-slot:header>

        @if ($this->own->isEmpty())
            <x-ui.empty-state icon="icon.folder"
                              :title="__('No templates of your own yet')"
                              :description="__('Copy one of the shipped templates below to make it yours, or save an existing project as a template from its settings — the structure you already trust becomes the starting point for the next one.')"
                              compact />
        @else
            <ul class="divide-y divide-[var(--line-subtle)]">
                @foreach ($this->own as $template)
                    @php $contents = $this->contents($template); @endphp
                    <li wire:key="own-{{ $template->getKey() }}"
                        class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-3
                               {{ $template->is_active ? '' : 'opacity-60' }}">

                        <span class="grid size-8 shrink-0 place-items-center rounded-md bg-[var(--surface-sunken)]
                                     text-sm">
                            {{ $template->icon ?: '📁' }}
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-1.5">
                                <span class="truncate text-sm font-medium text-[var(--text-strong)]">
                                    {{ $template->name }}
                                </span>
                                @unless ($template->is_active)
                                    <x-ui.badge color="gray" size="sm">{{ __('Retired') }}</x-ui.badge>
                                @endunless
                            </span>
                            @if (filled($template->description))
                                <span dir="auto" class="block truncate text-xs text-[var(--text-muted)]">
                                    {{ $template->description }}
                                </span>
                            @endif
                            <span class="block text-2xs text-[var(--text-subtle)]">
                                {{ __(':statuses statuses · :milestones milestones · :tasks tasks · :tags tags', $contents) }}
                            </span>
                        </span>

                        <x-ui.badge color="gray">{{ $template->type?->label() }}</x-ui.badge>

                        @can('update', $template)
                            <x-ui.dropdown align="end" width="w-56">
                                <x-slot:trigger>
                                    <x-ui.button variant="ghost" size="sm" icon-only
                                                 :aria-label="__('Actions for :name', ['name' => $template->name])">
                                        <x-icon.dots class="size-4" />
                                    </x-ui.button>
                                </x-slot:trigger>

                                <x-ui.dropdown-item icon="icon.cog" wire:click="startEdit({{ $template->getKey() }})">
                                    {{ __('Rename and describe') }}
                                </x-ui.dropdown-item>

                                <x-ui.dropdown-item icon="icon.archive"
                                                    wire:click="toggleActive({{ $template->getKey() }})">
                                    {{ $template->is_active ? __('Retire template') : __('Offer it again') }}
                                </x-ui.dropdown-item>

                                <x-ui.dropdown-item icon="icon.folder"
                                                    wire:click="duplicate({{ $template->getKey() }})">
                                    {{ __('Duplicate') }}
                                </x-ui.dropdown-item>

                                @can('delete', $template)
                                    <x-ui.dropdown-separator />
                                    <x-ui.dropdown-item icon="icon.trash" danger
                                                        wire:click="deleteTemplate({{ $template->getKey() }})"
                                                        wire:confirm="{{ __('Delete “:name”? Projects already created from it are not affected.', ['name' => $template->name]) }}">
                                        {{ __('Delete template') }}
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
    {{-- Shipped with Planvio                                             --}}
    {{-- ---------------------------------------------------------------- --}}
    <x-ui.card flush>
        <x-slot:header>
            <p class="text-sm font-semibold text-[var(--text-strong)]">{{ __('Shipped with Planvio') }}</p>
            <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                {{ __('Part of the product, and updated with it. Copy one to get a version this workspace owns and can change.') }}
            </p>
        </x-slot:header>

        @if ($this->system->isEmpty())
            <x-ui.empty-state icon="icon.folder"
                              :title="__('No shipped templates installed')"
                              :description="__('This installation has none seeded. Your own templates still work exactly the same.')"
                              compact />
        @else
            <ul class="divide-y divide-[var(--line-subtle)]">
                @foreach ($this->system as $template)
                    @php $contents = $this->contents($template); @endphp
                    <li wire:key="sys-{{ $template->getKey() }}"
                        class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-3">

                        <span class="grid size-8 shrink-0 place-items-center rounded-md bg-[var(--surface-sunken)]
                                     text-sm">
                            {{ $template->icon ?: '📦' }}
                        </span>

                        <span class="min-w-0 flex-1">
                            <span dir="auto" class="block truncate text-sm font-medium text-[var(--text-strong)]">
                                {{ $template->name }}
                            </span>
                            @if (filled($template->description))
                                <span dir="auto" class="block truncate text-xs text-[var(--text-muted)]">
                                    {{ $template->description }}
                                </span>
                            @endif
                            <span class="block text-2xs text-[var(--text-subtle)]">
                                {{ __(':statuses statuses · :milestones milestones · :tasks tasks · :tags tags', $contents) }}
                            </span>
                        </span>

                        <x-ui.badge color="gray">{{ $template->type?->label() }}</x-ui.badge>

                        @if ($canManage)
                            <x-ui.button variant="secondary" size="sm"
                                         wire:click="duplicate({{ $template->getKey() }})">
                                {{ __('Copy to workspace') }}
                            </x-ui.button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    <x-ui.modal wire:model="showForm" :title="__('Edit template')" size="md">
        <div class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-[5rem_minmax(0,1fr)]">
                <x-ui.field :label="__('Icon')" for="template-icon" :error="$errors->first('icon')"
                            :hint="__('An emoji.')">
                    <x-ui.input id="template-icon" wire:model="icon" maxlength="8" class="text-center"
                                :invalid="$errors->has('icon')" />
                </x-ui.field>

                <x-ui.field :label="__('Name')" for="template-name" :error="$errors->first('name')" required>
                    <x-ui.input id="template-name" wire:model="name" maxlength="80"
                                :invalid="$errors->has('name')" />
                </x-ui.field>
            </div>

            <x-ui.field :label="__('Description')" for="template-description"
                        :error="$errors->first('description')"
                        :hint="__('Shown next to the template when somebody is choosing one.')">
                <x-ui.textarea id="template-description" wire:model="description" rows="3"
                               :invalid="$errors->has('description')" />
            </x-ui.field>

            <x-ui.field :label="__('Kind of project')" for="template-type" :error="$errors->first('type')" required>
                <x-ui.select id="template-type" wire:model="type" :invalid="$errors->has('type')">
                    @foreach (\App\Enums\ProjectType::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        </div>

        <x-slot:footer>
            <x-ui.button variant="ghost" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="primary" wire:click="save" wire:target="save">
                {{ __('Save template') }}
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>

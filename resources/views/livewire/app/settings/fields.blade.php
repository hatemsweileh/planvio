@php
    $canCreate = auth()->user()->can('create', [\App\Models\CustomField::class, null]);
    $counts = $this->answerCounts;
    $selectedType = \App\Enums\CustomFieldType::tryFrom($type) ?? \App\Enums\CustomFieldType::Text;
@endphp

<div>
    <x-ui.card flush>
        <x-slot:header>
            <p class="text-sm font-semibold text-[var(--text-strong)]">{{ __('Custom fields') }}</p>
            <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                {{ __('Available on every project in this workspace. Fields that belong to one project are set up in that project.') }}
            </p>
        </x-slot:header>

        <x-slot:actions>
            <div class="flex items-center gap-2">
                <x-ui.tabs variant="pill">
                    <x-ui.tab variant="pill" :active="$entity === 'task'" wire:click="setEntity('task')">
                        {{ __('Tasks') }}
                    </x-ui.tab>
                    <x-ui.tab variant="pill" :active="$entity === 'project'" wire:click="setEntity('project')">
                        {{ __('Projects') }}
                    </x-ui.tab>
                </x-ui.tabs>

                @if ($canCreate)
                    <x-ui.button variant="secondary" size="sm" icon="icon.plus" wire:click="startCreate">
                        {{ __('Add field') }}
                    </x-ui.button>
                @endif
            </div>
        </x-slot:actions>

        @if ($this->fields->isEmpty())
            <x-ui.empty-state icon="icon.list"
                              :title="$entity === 'task' ? __('No custom task fields') : __('No custom project fields')"
                              :description="__('Add the attribute your process depends on but Planvio does not ship — a client reference, an effort band, a compliance flag. It appears on every form and every filter.')"
                              compact>
                <x-slot:actions>
                    @if ($canCreate)
                        <x-ui.button variant="primary" size="md" icon="icon.plus" wire:click="startCreate">
                            {{ __('Add a field') }}
                        </x-ui.button>
                    @endif
                </x-slot:actions>
            </x-ui.empty-state>
        @else
            <ul class="divide-y divide-[var(--line-subtle)]">
                @foreach ($this->fields as $field)
                    @php $answers = $counts[$field->getKey()] ?? 0; @endphp
                    <li wire:key="field-{{ $field->getKey() }}"
                        class="group/row flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2.5
                               transition-colors hover:bg-[var(--surface-hover)]
                               {{ $field->is_active ? '' : 'opacity-60' }}">

                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-1.5">
                                <span class="truncate text-sm font-medium text-[var(--text-strong)]">
                                    {{ $field->name }}
                                </span>
                                @if ($field->is_required)
                                    <span class="text-2xs uppercase tracking-wide text-critical-600">
                                        {{ __('Required') }}
                                    </span>
                                @endif
                                @unless ($field->is_active)
                                    <x-ui.badge color="gray" size="sm">{{ __('Off') }}</x-ui.badge>
                                @endunless
                            </span>
                            <span class="block truncate font-mono text-2xs text-[var(--text-subtle)]">
                                <x-ui.bidi>{{ $field->key }}</x-ui.bidi>
                            </span>
                        </span>

                        <x-ui.badge :color="$field->type?->color() ?? 'gray'">
                            {{ $field->type?->label() }}
                        </x-ui.badge>

                        @if ($field->type?->hasOptions())
                            <span class="text-xs text-[var(--text-muted)]">
                                {{ trans_choice('{1} :count choice|[2,*] :count choices', count((array) $field->options), ['count' => count((array) $field->options)]) }}
                            </span>
                        @endif

                        <span class="w-24 shrink-0 text-end text-xs tabular-nums text-[var(--text-subtle)]">
                            {{ trans_choice('{0} no answers|{1} :count answer|[2,*] :count answers', $answers, ['count' => $answers]) }}
                        </span>

                        @can('update', $field)
                            <x-ui.dropdown align="end" width="w-56">
                                <x-slot:trigger>
                                    <x-ui.button variant="ghost" size="sm" icon-only
                                                 :aria-label="__('Actions for :name', ['name' => $field->name])">
                                        <x-icon.dots class="size-4" />
                                    </x-ui.button>
                                </x-slot:trigger>

                                <x-ui.dropdown-item icon="icon.cog" wire:click="startEdit({{ $field->getKey() }})">
                                    {{ __('Edit field') }}
                                </x-ui.dropdown-item>

                                <x-ui.dropdown-item icon="icon.archive"
                                                    wire:click="toggleActive({{ $field->getKey() }})">
                                    {{ $field->is_active ? __('Switch off') : __('Switch on') }}
                                </x-ui.dropdown-item>

                                @can('delete', $field)
                                    <x-ui.dropdown-separator />
                                    <x-ui.dropdown-item icon="icon.trash" danger
                                                        wire:click="deleteField({{ $field->getKey() }})"
                                                        wire:confirm="{{ trans_choice(
                                                            '{0} Delete “:name”? Nothing has been filled in yet.|{1} Delete “:name”? :count answer is deleted with it.|[2,*] Delete “:name”? :count answers are deleted with it.',
                                                            $answers,
                                                            ['name' => $field->name, 'count' => $answers],
                                                        ) }}">
                                        {{ __('Delete field') }}
                                    </x-ui.dropdown-item>
                                @endcan
                            </x-ui.dropdown>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    <x-ui.modal wire:model="showForm" :title="$editingId ? __('Edit field') : __('New field')" size="md">
        <div class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field :label="__('Label')" for="field-name" :error="$errors->first('name')" required>
                    <x-ui.input id="field-name" wire:model="name" maxlength="80"
                                :placeholder="__('Client reference')" :invalid="$errors->has('name')" />
                </x-ui.field>

                <x-ui.field :label="__('Key')" for="field-key" :error="$errors->first('key')"
                            :hint="__('Used by the API and by imports. Left blank, it is derived from the label.')">
                    <x-ui.input id="field-key" wire:model="key" maxlength="64" class="font-mono"
                                :placeholder="__('client_reference')" :invalid="$errors->has('key')" />
                </x-ui.field>
            </div>

            <x-ui.field :label="__('Type')" for="field-type" :error="$errors->first('type')" required>
                <x-ui.select id="field-type" wire:model.live="type" :invalid="$errors->has('type')">
                    @foreach (\App\Enums\CustomFieldType::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            @if ($selectedType->hasOptions())
                <x-ui.field :label="__('Choices')" for="field-options" :error="$errors->first('options')"
                            :hint="__('One per line, in the order they should appear.')" required>
                    <x-ui.textarea id="field-options" wire:model="options" rows="5"
                                   :invalid="$errors->has('options')"
                                   placeholder="{{ __('Small') }}&#10;{{ __('Medium') }}&#10;{{ __('Large') }}" />
                </x-ui.field>
            @endif

            <div class="space-y-2">
                <x-ui.checkbox wire:model="isRequired"
                               :label="__('Required')"
                               :description="__('The form will not save without an answer.')" />

                @if ($editingId)
                    <x-ui.checkbox wire:model="isActive"
                                   :label="__('Active')"
                                   :description="__('Switching it off hides the field everywhere but keeps the answers already given.')" />
                @endif
            </div>
        </div>

        <x-slot:footer>
            <x-ui.button variant="ghost" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="primary" wire:click="save" wire:target="save">
                {{ $editingId ? __('Save field') : __('Add field') }}
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>

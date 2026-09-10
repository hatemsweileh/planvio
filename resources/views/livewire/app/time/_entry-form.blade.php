@php
    /**
     * Recording or correcting one entry.
     *
     * The duration field takes what people type rather than insisting on a format — `90`,
     * `1:30`, `1h 30m`, `1.5h` all mean the same ninety minutes — and the hint says so, so
     * nobody has to discover it. Expects `$showProject`.
     */
@endphp

<x-ui.modal wire:model="formOpen" size="md"
            :title="$editingId ? __('Edit entry') : __('Log time')"
            :description="$editingId
                ? __('Corrections are recorded in the project activity feed.')
                : __('Record work that happened without a timer running.')">

    <form wire:submit="saveEntry" class="space-y-3">
        <div class="grid gap-3 sm:grid-cols-2">
            <x-ui.field :label="__('Date')" for="entry-date" required :error="$errors->first('formDate')">
                <x-ui.input id="entry-date" type="date" size="md" wire:model="formDate"
                            max="{{ $this->today()->toDateString() }}" />
            </x-ui.field>

            <x-ui.field :label="__('Duration')" for="entry-duration" required
                        :error="$errors->first('formDuration')"
                        :hint="__('90, 1:30, 1h 30m and 1.5h all mean the same thing.')">
                <x-ui.input id="entry-duration" size="md" wire:model="formDuration"
                            placeholder="1h 30m" inputmode="text" autocomplete="off" />
            </x-ui.field>
        </div>

        @if ($showProject)
            <x-ui.field :label="__('Project')" for="entry-project" required :error="$errors->first('formProject')">
                <x-ui.select id="entry-project" size="md" wire:model.live="formProject" :options="$this->projectOptions" />
            </x-ui.field>
        @endif

        <x-ui.field :label="__('Task')" for="entry-task"
                    :hint="__('Leave this on “project level” for work that does not belong to one task.')">
            <x-ui.select id="entry-task" size="md" wire:model="formTask" :options="$this->taskOptions" />
        </x-ui.field>

        <x-ui.field :label="__('Note')" for="entry-note">
            <x-ui.input id="entry-note" size="md" wire:model="formDescription" maxlength="255"
                        :placeholder="__('What was done')" />
        </x-ui.field>

        <x-ui.checkbox wire:model="formBillable" :label="__('Billable')"
                       :description="__('Counts towards the billable total on every time report.')" />

        <button type="submit" class="hidden" aria-hidden="true" tabindex="-1"></button>
    </form>

    <x-slot:footer>
        <x-ui.button variant="ghost" size="md" wire:click="cancelEntry">{{ __('Cancel') }}</x-ui.button>
        <x-ui.button variant="primary" size="md" wire:click="saveEntry" wire:target="saveEntry">
            {{ $editingId ? __('Save changes') : __('Log time') }}
        </x-ui.button>
    </x-slot:footer>
</x-ui.modal>

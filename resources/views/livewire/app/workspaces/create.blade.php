<div>
    <form wire:submit="save" class="space-y-4">
        <x-ui.field :label="__('Workspace name')" for="workspace-name" :error="$errors->first('name')" required>
            <x-ui.input id="workspace-name" wire:model="name" type="text" size="lg" required autofocus
                        autocomplete="organization" maxlength="80"
                        :placeholder="__('Acme, Marketing, Northwind Ltd')"
                        :invalid="$errors->has('name')" />
        </x-ui.field>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.field :label="__('Timezone')" for="workspace-timezone" :error="$errors->first('timezone')" required>
                <x-ui.select id="workspace-timezone" wire:model="timezone" size="lg"
                             :invalid="$errors->has('timezone')">
                    @foreach ($this->timezones() as $identifier)
                        <option value="{{ $identifier }}">{{ str_replace('_', ' ', $identifier) }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            <x-ui.field :label="__('Currency')" for="workspace-currency" :error="$errors->first('currency')"
                        :hint="__('Used for budgets and expenses.')" required>
                <x-ui.input id="workspace-currency" wire:model="currency" type="text" size="lg" required
                            maxlength="3" minlength="3" class="uppercase"
                            :invalid="$errors->has('currency')" />
            </x-ui.field>
        </div>

        @error('domain')
            <p class="text-xs text-critical-600" role="alert">{{ $message }}</p>
        @enderror

        <x-ui.button type="submit" variant="primary" size="lg" class="w-full" wire:target="save">
            <span wire:loading.remove wire:target="save">{{ __('Create workspace') }}</span>
            <span wire:loading wire:target="save" class="inline-flex items-center gap-2">
                <x-ui.spinner class="size-4" />
                {{ __('Setting things up…') }}
            </span>
        </x-ui.button>
    </form>

    <p class="mt-4 text-center text-xs leading-relaxed text-[var(--text-subtle)]">
        {{ __('Planvio creates the default statuses, tags and AI settings for you. You can change all of them later.') }}
    </p>
</div>

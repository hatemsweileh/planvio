{{--
    The accent listener lives on the root of this component: when the save announces a new
    colour, the token the whole design system reads is reassigned in place, so the sidebar,
    the buttons and the focus rings change with the card rather than after a reload.
--}}
<div x-data
     x-on:planvio-accent-changed.window="
        document.documentElement.style.setProperty('--accent', $event.detail.color);
        document.documentElement.style.setProperty('--accent-hover', `color-mix(in oklab, ${$event.detail.color} 85%, black)`);
     ">

    <form wire:submit="save" class="space-y-4">

        {{-- ------------------------------------------------------------ --}}
        {{-- Identity                                                     --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card :title="__('Identity')" :subtitle="__('What this workspace is called, and where it lives.')">
            <div class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field :label="__('Name')" for="ws-name" :error="$errors->first('name')" required>
                        <x-ui.input id="ws-name" wire:model="name" maxlength="80"
                                    :invalid="$errors->has('name')" />
                    </x-ui.field>

                    <x-ui.field :label="__('Address')" for="ws-slug" :error="$errors->first('slug')"
                                :hint="__('Every link to this workspace uses it. Changing it breaks old links.')" required>
                        <div class="flex items-center gap-1 rounded-md border border-[var(--line-DEFAULT)]
                                    bg-[var(--surface-sunken)] ps-2.5 shadow-xs
                                    focus-within:border-[var(--accent)] focus-within:ring-2
                                    focus-within:ring-[var(--accent-ring)]">
                            <x-ui.bidi class="shrink-0 text-xs text-[var(--text-subtle)]">/w/</x-ui.bidi>
                            <input id="ws-slug" wire:model="slug" maxlength="60"
                                   class="h-8 w-full min-w-0 rounded-e-md border-0 bg-[var(--surface-panel)] px-2
                                          text-sm text-[var(--text-strong)] focus:outline-none focus:ring-0"
                                   @if ($errors->has('slug')) aria-invalid="true" @endif>
                        </div>
                    </x-ui.field>
                </div>

                <x-ui.field :label="__('Description')" for="ws-description"
                            :error="$errors->first('description')"
                            :hint="__('One line, for the workspace switcher.')">
                    <x-ui.input id="ws-description" wire:model="description" maxlength="500" dir="auto"
                                :placeholder="__('Product and engineering at Northwind')"
                                :invalid="$errors->has('description')" />
                </x-ui.field>

                {{-- Logo --}}
                <div>
                    <p class="mb-1.5 text-xs font-medium text-[var(--text-DEFAULT)]">{{ __('Logo') }}</p>
                    <div x-data="{ uploading: false, progress: 0 }"
                         x-on:livewire-upload-start="uploading = true; progress = 0"
                         x-on:livewire-upload-finish="uploading = false"
                         x-on:livewire-upload-cancel="uploading = false"
                         x-on:livewire-upload-error="uploading = false"
                         x-on:livewire-upload-progress="progress = $event.detail.progress"
                         class="flex flex-wrap items-center gap-3">

                        <span class="grid size-12 shrink-0 place-items-center overflow-hidden rounded-lg border
                                     border-[var(--line-subtle)] bg-[var(--surface-sunken)]">
                            @if ($logo)
                                <img src="{{ $logo->temporaryUrl() }}" alt="" class="size-full object-cover">
                            @elseif ($this->logoUrl())
                                <img src="{{ $this->logoUrl() }}" alt="{{ $workspace->name }}"
                                     class="size-full object-cover">
                            @else
                                <span class="text-sm font-semibold text-[var(--text-subtle)]">
                                    {{ mb_strtoupper(mb_substr($workspace->name, 0, 2)) }}
                                </span>
                            @endif
                        </span>

                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <label class="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-md border
                                              border-[var(--line-DEFAULT)] bg-[var(--surface-panel)] px-3 text-sm
                                              font-medium text-[var(--text-DEFAULT)] shadow-xs transition-colors
                                              hover:bg-[var(--surface-hover)]">
                                    {{ $this->logoUrl() ? __('Replace') : __('Upload') }}
                                    <input type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp"
                                           class="sr-only">
                                </label>

                                @if ($this->logoUrl())
                                    <x-ui.button variant="danger-ghost" size="sm" wire:click="removeLogo"
                                                 wire:confirm="{{ __('Remove the workspace logo?') }}">
                                        {{ __('Remove') }}
                                    </x-ui.button>
                                @endif
                            </div>

                            <p class="mt-1 text-xs text-[var(--text-muted)]">
                                {{ __('PNG, JPEG or WebP, up to :size KB. Square works best.', [
                                    'size' => config('planvio.uploads.avatar_max_size_kb', 2048),
                                ]) }}
                            </p>

                            <div x-show="uploading" x-cloak class="mt-1.5 h-1 w-40 overflow-hidden rounded-full
                                        bg-[var(--surface-active)]" role="progressbar"
                                 aria-label="{{ __('Uploading') }}" x-bind:aria-valuenow="progress"
                                 aria-valuemin="0" aria-valuemax="100">
                                <div class="h-full rounded-full bg-[var(--accent)]"
                                     x-bind:style="`width: ${progress}%`"></div>
                            </div>

                            @error('logo')
                                <p class="mt-1 text-xs text-critical-600" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>
            </div>
        </x-ui.card>

        {{-- ------------------------------------------------------------ --}}
        {{-- Accent                                                       --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card :title="__('Accent colour')"
                   :subtitle="__('Used for primary actions, links, focus rings and the active navigation item.')">
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex flex-wrap items-center gap-1.5">
                    @foreach ($this->presets() as $preset)
                        <button type="button" wire:click="$set('accentColor', '{{ $preset }}')"
                                class="size-7 rounded-full border transition-transform hover:scale-110
                                       {{ mb_strtolower($accentColor) === mb_strtolower($preset)
                                           ? 'border-[var(--text-strong)] ring-2 ring-offset-2 ring-[var(--line-strong)]'
                                           : 'border-[var(--line-subtle)]' }}"
                                style="background-color: {{ $preset }}"
                                aria-label="{{ __('Use :color', ['color' => $preset]) }}"
                                aria-pressed="{{ mb_strtolower($accentColor) === mb_strtolower($preset) ? 'true' : 'false' }}"></button>
                    @endforeach
                </div>

                <div class="flex items-center gap-2">
                    <label for="ws-accent" class="sr-only">{{ __('Custom accent colour') }}</label>
                    <input id="ws-accent" type="color" wire:model.live="accentColor"
                           class="size-8 cursor-pointer rounded-md border border-[var(--line-DEFAULT)]
                                  bg-[var(--surface-panel)] p-0.5">
                    <span class="font-mono text-xs uppercase text-[var(--text-muted)]">{{ $accentColor }}</span>
                </div>
            </div>

            @error('accentColor')
                <p class="mt-2 text-xs text-critical-600" role="alert">{{ $message }}</p>
            @enderror

            {{-- A live preview, drawn with the value in the field rather than the saved one. --}}
            <div class="mt-4 flex flex-wrap items-center gap-2 rounded-lg border border-[var(--line-subtle)]
                        bg-[var(--surface-sunken)] p-3"
                 style="--accent: {{ $accentColor }}; --accent-ring: color-mix(in oklab, {{ $accentColor }} 35%, transparent)">
                <span class="text-xs text-[var(--text-muted)]">{{ __('Preview') }}</span>
                <x-ui.button variant="primary" size="sm" type="button">{{ __('Primary action') }}</x-ui.button>
                <span class="text-sm" style="color: {{ $accentColor }}">{{ __('A link') }}</span>
                <span class="inline-flex h-6 items-center rounded px-2 text-xs font-medium"
                      style="background-color: color-mix(in oklab, {{ $accentColor }} 12%, transparent); color: {{ $accentColor }}">
                    {{ __('Active') }}
                </span>
            </div>
        </x-ui.card>

        {{-- ------------------------------------------------------------ --}}
        {{-- Regional                                                     --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card :title="__('Region and formats')"
                   :subtitle="__('Applies to dates, budgets and reminder timing for everyone in this workspace.')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field :label="__('Timezone')" for="ws-timezone" :error="$errors->first('timezone')" required>
                    <x-ui.select id="ws-timezone" wire:model="timezone" :invalid="$errors->has('timezone')">
                        @foreach ($this->timezones() as $identifier)
                            <option value="{{ $identifier }}">{{ str_replace('_', ' ', $identifier) }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field :label="__('Language')" for="ws-locale" :error="$errors->first('locale')" required>
                    <x-ui.select id="ws-locale" wire:model="locale" :invalid="$errors->has('locale')">
                        @foreach ($this->locales as $code => $label)
                            <option value="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field :label="__('Currency')" for="ws-currency" :error="$errors->first('currency')"
                            :hint="__('Three-letter code, used for budgets and expenses.')" required>
                    <x-ui.input id="ws-currency" wire:model="currency" maxlength="3" minlength="3"
                                class="uppercase-latin" :invalid="$errors->has('currency')" />
                </x-ui.field>

                <x-ui.field :label="__('Date format')" for="ws-date-format"
                            :error="$errors->first('dateFormat')" required>
                    <x-ui.select id="ws-date-format" wire:model="dateFormat"
                                 :invalid="$errors->has('dateFormat')">
                        @foreach ($this->dateFormats() as $format => $example)
                            <option value="{{ $format }}">{{ $example }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field :label="__('Week starts on')" for="ws-week-start"
                            :error="$errors->first('weekStartsOn')" required>
                    <x-ui.select id="ws-week-start" wire:model="weekStartsOn"
                                 :invalid="$errors->has('weekStartsOn')">
                        @foreach ($this->weekdays() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>
        </x-ui.card>

        <div class="flex items-center justify-end gap-2">
            <span wire:loading wire:target="save" class="text-xs text-[var(--text-muted)]">
                {{ __('Saving…') }}
            </span>
            <x-ui.button type="submit" variant="primary" wire:target="save">{{ __('Save changes') }}</x-ui.button>
        </div>
    </form>
</div>

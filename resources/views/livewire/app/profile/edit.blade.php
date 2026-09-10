{{--
    The theme is written to the account *and* to this browser: the column is what a new
    device inherits, localStorage is what paints before the first frame.
--}}
<div class="page page-prose py-6"
     x-data
     x-on:planvio-theme-preference.window="window.Planvio?.setTheme($event.detail.theme)">

    @include('livewire.app.profile._tabs')

    <form wire:submit="save" class="mt-5 space-y-4">

        {{-- ------------------------------------------------------------ --}}
        {{-- Photo                                                        --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card :title="__('Photo')"
                   :subtitle="__('Shown wherever your name appears — task assignees, comments, the people directory.')">
            <div x-data="{ uploading: false, progress: 0 }"
                 x-on:livewire-upload-start="uploading = true; progress = 0"
                 x-on:livewire-upload-finish="uploading = false"
                 x-on:livewire-upload-cancel="uploading = false"
                 x-on:livewire-upload-error="uploading = false"
                 x-on:livewire-upload-progress="progress = $event.detail.progress"
                 class="flex flex-wrap items-center gap-4">

                <span class="grid size-16 shrink-0 place-items-center overflow-hidden rounded-full border
                             border-[var(--line-subtle)] bg-[var(--surface-sunken)]">
                    @if ($avatar)
                        <img src="{{ $avatar->temporaryUrl() }}" alt="" class="size-full object-cover">
                    @else
                        <img src="{{ $this->avatarUrl() }}" alt="{{ auth()->user()->name }}"
                             class="size-full object-cover">
                    @endif
                </span>

                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-md border
                                      border-[var(--line-DEFAULT)] bg-[var(--surface-panel)] px-3 text-sm
                                      font-medium text-[var(--text-DEFAULT)] shadow-xs transition-colors
                                      hover:bg-[var(--surface-hover)]">
                            {{ auth()->user()->avatar_path ? __('Replace photo') : __('Upload a photo') }}
                            <input type="file" wire:model="avatar" accept="image/png,image/jpeg,image/webp"
                                   class="sr-only">
                        </label>

                        @if (auth()->user()->avatar_path)
                            <x-ui.button variant="danger-ghost" size="sm" wire:click="removeAvatar"
                                         wire:confirm="{{ __('Remove your photo? Your initials will be shown instead.') }}">
                                {{ __('Remove') }}
                            </x-ui.button>
                        @endif
                    </div>

                    <p class="mt-1 text-xs text-[var(--text-muted)]">
                        {{ __('PNG, JPEG or WebP, up to :size KB. Without one, Planvio draws your initials — nothing is fetched from an external service.', [
                            'size' => config('planvio.uploads.avatar_max_size_kb', 2048),
                        ]) }}
                    </p>

                    <div x-show="uploading" x-cloak class="mt-1.5 h-1 w-40 overflow-hidden rounded-full
                                bg-[var(--surface-active)]" role="progressbar"
                         aria-label="{{ __('Uploading') }}" x-bind:aria-valuenow="progress"
                         aria-valuemin="0" aria-valuemax="100">
                        <div class="h-full rounded-full bg-[var(--accent)]" x-bind:style="`width: ${progress}%`"></div>
                    </div>

                    @error('avatar')
                        <p class="mt-1 text-xs text-critical-600" role="alert">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </x-ui.card>

        {{-- ------------------------------------------------------------ --}}
        {{-- Identity                                                     --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card :title="__('About you')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field :label="__('Name')" for="me-name" :error="$errors->first('name')" required>
                    <x-ui.input id="me-name" wire:model="name" maxlength="120" autocomplete="name"
                                :invalid="$errors->has('name')" />
                </x-ui.field>

                <x-ui.field :label="__('Job title')" for="me-job" :error="$errors->first('jobTitle')"
                            :hint="__('Shown under your name in the people directory.')">
                    <x-ui.input id="me-job" wire:model="jobTitle" maxlength="120"
                                :placeholder="__('Delivery lead')" :invalid="$errors->has('jobTitle')" />
                </x-ui.field>

                <x-ui.field :label="__('Email')" for="me-email" :error="$errors->first('email')"
                            :hint="__('This is what you sign in with.')" required class="sm:col-span-2">
                    <x-ui.input id="me-email" type="email" wire:model="email" maxlength="255"
                                autocomplete="email" :invalid="$errors->has('email')" />
                </x-ui.field>
            </div>
        </x-ui.card>

        {{-- ------------------------------------------------------------ --}}
        {{-- Preferences                                                  --}}
        {{-- ------------------------------------------------------------ --}}
        <x-ui.card :title="__('How Planvio behaves for you')"
                   :subtitle="__('These follow you into every workspace you belong to.')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field :label="__('Timezone')" for="me-timezone" :error="$errors->first('timezone')"
                            :hint="__('Every date and reminder is shown in this zone.')" required>
                    <x-ui.select id="me-timezone" wire:model="timezone" :invalid="$errors->has('timezone')">
                        @foreach ($this->timezones() as $identifier)
                            <option value="{{ $identifier }}">{{ str_replace('_', ' ', $identifier) }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field :label="__('Language')" for="me-locale" :error="$errors->first('locale')" required>
                    <x-ui.select id="me-locale" wire:model="locale" :invalid="$errors->has('locale')">
                        @foreach ($this->locales as $code => $label)
                            <option value="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>

            <div class="mt-4">
                <p class="mb-1.5 text-xs font-medium text-[var(--text-DEFAULT)]">{{ __('Appearance') }}</p>
                <div class="grid gap-2 sm:grid-cols-3" role="radiogroup" aria-label="{{ __('Appearance') }}">
                    @foreach ([
                        ['system', __('Match my device'), 'icon.monitor'],
                        ['light', __('Light'), 'icon.sun'],
                        ['dark', __('Dark'), 'icon.moon'],
                    ] as [$value, $label, $icon])
                        <button type="button" wire:click="$set('theme', '{{ $value }}')"
                                x-on:click="window.Planvio?.setTheme('{{ $value }}')"
                                role="radio" aria-checked="{{ $theme === $value ? 'true' : 'false' }}"
                                class="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm transition-colors
                                       {{ $theme === $value
                                           ? 'border-[var(--accent)] bg-[var(--accent-soft)] text-[var(--accent-soft-text)]'
                                           : 'border-[var(--line-subtle)] bg-[var(--surface-panel)] text-[var(--text-DEFAULT)] hover:bg-[var(--surface-hover)]' }}">
                            <x-dynamic-component :component="$icon" class="size-4 shrink-0" />
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
                @error('theme')
                    <p class="mt-1 text-xs text-critical-600" role="alert">{{ $message }}</p>
                @enderror
            </div>
        </x-ui.card>

        <div class="flex items-center justify-end gap-2">
            <span wire:loading wire:target="save" class="text-xs text-[var(--text-muted)]">{{ __('Saving…') }}</span>
            <x-ui.button type="submit" variant="primary">{{ __('Save profile') }}</x-ui.button>
        </div>
    </form>
</div>

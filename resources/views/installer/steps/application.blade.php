<div>
    <section class="panel">
        @include('installer.partials.panel-head', ['step' => $step, 'heading' => $heading, 'lede' => $lede])

        <form class="panel-body stack" wire:submit="save">
            <div class="grid grid-2">
                <div class="field">
                    <label class="label" for="app-name">
                        {{ __('Application name') }} <span class="req" aria-hidden="true">*</span>
                    </label>
                    <input class="input @error('name') is-invalid @enderror"
                           id="app-name" type="text" wire:model.blur="name" required autofocus maxlength="60">
                    @error('name')
                        <p class="error" role="alert">{{ $message }}</p>
                    @else
                        <p class="hint">{{ __('Shown in the browser tab, in email and on the sign-in screen. Your first workspace takes this name too.') }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label class="label" for="app-url">
                        {{ __('Application address') }} <span class="req" aria-hidden="true">*</span>
                    </label>
                    <input class="input @error('url') is-invalid @enderror"
                           id="app-url" type="url" wire:model.blur="url" required
                           autocomplete="off" spellcheck="false" placeholder="https://planvio.example.com">
                    @error('url')
                        <p class="error" role="alert">{{ $message }}</p>
                    @else
                        <p class="hint">{{ __('Detected from the address you opened. Password reset links are built from it, so get it exactly right — including https.') }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label class="label" for="app-timezone">
                        {{ __('Timezone') }} <span class="req" aria-hidden="true">*</span>
                    </label>
                    <select class="select @error('timezone') is-invalid @enderror"
                            id="app-timezone" wire:model="timezone" required>
                        @foreach ($this->timezones() as $identifier)
                            <option value="{{ $identifier }}" @selected($timezone === $identifier)>{{ str_replace('_', ' ', $identifier) }}</option>
                        @endforeach
                    </select>
                    @error('timezone')<p class="error" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label class="label" for="app-date-format">
                        {{ __('Date format') }} <span class="req" aria-hidden="true">*</span>
                    </label>
                    <select class="select @error('dateFormat') is-invalid @enderror"
                            id="app-date-format" wire:model="dateFormat" required>
                        @foreach ($this->dateFormats() as $value => $label)
                            <option value="{{ $value }}" @selected($dateFormat === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('dateFormat')<p class="error" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label class="label" for="app-locale">
                        {{ __('Language') }} <span class="req" aria-hidden="true">*</span>
                    </label>
                    <input class="input @error('locale') is-invalid @enderror"
                           id="app-locale" type="text" wire:model.blur="locale" required maxlength="8"
                           autocomplete="off" spellcheck="false">
                    @error('locale')
                        <p class="error" role="alert">{{ $message }}</p>
                    @else
                        <p class="hint">{{ __('A two-letter code such as en. This release ships English and Arabic; further languages are added and translated after installation.') }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label class="label" for="app-currency">
                        {{ __('Currency') }} <span class="req" aria-hidden="true">*</span>
                    </label>
                    <input class="input @error('currency') is-invalid @enderror"
                           id="app-currency" type="text" wire:model.blur="currency" required
                           maxlength="3" minlength="3" style="text-transform: uppercase"
                           autocomplete="off" spellcheck="false">
                    @error('currency')
                        <p class="error" role="alert">{{ $message }}</p>
                    @else
                        <p class="hint">{{ __('Used for budgets and expenses. Three letters, such as USD or EUR.') }}</p>
                    @enderror
                </div>

                <div class="field span-2">
                    <label class="label" for="app-environment">
                        {{ __('Environment') }} <span class="req" aria-hidden="true">*</span>
                    </label>
                    <select class="select @error('environment') is-invalid @enderror"
                            id="app-environment" wire:model="environment" required>
                        <option value="production" @selected($environment === 'production')>{{ __('Production — error details hidden from visitors') }}</option>
                        <option value="local" @selected($environment === 'local')>{{ __('Local — full error pages, for development only') }}</option>
                    </select>
                    @error('environment')
                        <p class="error" role="alert">{{ $message }}</p>
                    @else
                        <p class="hint">{{ __('Leave this on Production for anything reachable from the internet.') }}</p>
                    @enderror
                </div>
            </div>

            @if ($detected !== [])
                <div class="group">
                    <p class="group-title">{{ __('What Planvio detected') }}</p>
                    <dl class="kv">
                        @foreach ($detected as $label => $value)
                            <dt>{{ $label }}</dt>
                            <dd>{{ $value }}</dd>
                        @endforeach
                    </dl>
                </div>
            @endif
        </form>

        <div class="panel-foot">
            <button type="button" class="btn btn-ghost" wire:click="back">{{ __('Back') }}</button>
            <span class="spacer"></span>
            <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">
                <span class="spin" wire:loading wire:target="save" aria-hidden="true"></span>
                {{ __('Continue') }}
            </button>
        </div>
    </section>
</div>

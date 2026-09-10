<div>
    <section class="panel">
        @include('installer.partials.panel-head', ['step' => $step, 'heading' => $heading, 'lede' => $lede])

        <form class="panel-body stack" wire:submit="save">
            <label class="check">
                <input type="checkbox" wire:model.live="configured" @checked($configured)>
                <span>
                    <span class="check-title">{{ __('Send email through my SMTP server') }}</span>
                    <span class="check-note">{{ __('Leave this off and Planvio writes messages to storage/logs instead. Nothing breaks; nothing is delivered either.') }}</span>
                </span>
            </label>

            <div class="grid grid-2" @if (! $configured) hidden @endif>
                <div class="field">
                    <label class="label" for="mail-host">
                        {{ __('SMTP host') }} <span class="req" aria-hidden="true">*</span>
                    </label>
                    <input class="input @error('host') is-invalid @enderror"
                           id="mail-host" type="text" wire:model.blur="host"
                           autocomplete="off" spellcheck="false" placeholder="mail.example.com">
                    @error('host')<p class="error" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label class="label" for="mail-encryption">
                        {{ __('Encryption') }} <span class="req" aria-hidden="true">*</span>
                    </label>
                    <select class="select @error('encryption') is-invalid @enderror"
                            id="mail-encryption" wire:model="encryption">
                        @foreach ($this->encryptionOptions() as $value => $label)
                            <option value="{{ $value }}" @selected($encryption === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('encryption')<p class="error" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label class="label" for="mail-port">
                        {{ __('Port') }} <span class="req" aria-hidden="true">*</span>
                    </label>
                    <input class="input @error('port') is-invalid @enderror"
                           id="mail-port" type="number" min="1" max="65535" wire:model.blur="port"
                           inputmode="numeric">
                    @error('port')
                        <p class="error" role="alert">{{ $message }}</p>
                    @else
                        <p class="hint">{{ __('587 with STARTTLS, or 465 with SSL/TLS.') }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label class="label" for="mail-username">{{ __('Username') }}</label>
                    <input class="input @error('username') is-invalid @enderror"
                           id="mail-username" type="text" wire:model.blur="username"
                           autocomplete="off" spellcheck="false" placeholder="planvio@example.com">
                    @error('username')
                        <p class="error" role="alert">{{ $message }}</p>
                    @else
                        <p class="hint">{{ __('Usually the full mailbox address, not just the part before the @.') }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label class="label" for="mail-password">{{ __('Password') }}</label>
                    <input class="input @error('password') is-invalid @enderror"
                           id="mail-password" type="password" wire:model.blur="password"
                           autocomplete="off">
                    @error('password')<p class="error" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label class="label" for="mail-from-address">
                        {{ __('From address') }} <span class="req" aria-hidden="true">*</span>
                    </label>
                    <input class="input @error('fromAddress') is-invalid @enderror"
                           id="mail-from-address" type="email" wire:model.blur="fromAddress"
                           autocomplete="off" spellcheck="false">
                    @error('fromAddress')<p class="error" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="field span-2">
                    <label class="label" for="mail-from-name">
                        {{ __('From name') }} <span class="req" aria-hidden="true">*</span>
                    </label>
                    <input class="input @error('fromName') is-invalid @enderror"
                           id="mail-from-name" type="text" wire:model.blur="fromName" maxlength="80">
                    @error('fromName')
                        <p class="error" role="alert">{{ $message }}</p>
                    @else
                        <p class="hint">{{ __('What recipients see in the From column of their inbox.') }}</p>
                    @enderror
                </div>
            </div>

            @if ($result !== [])
                @include('installer.partials.test-result', ['result' => $result])
            @endif

            @unless ($configured)
                <div class="note">
                    <p>{{ __('Email is off. Invitations and password resets will be written to storage/logs, where you can still read them, and you can configure a real server at any time from the admin panel.') }}</p>
                </div>
            @endunless
        </form>

        <div class="panel-foot">
            <button type="button" class="btn btn-ghost" wire:click="back">{{ __('Back') }}</button>
            <span class="spacer"></span>

            @if ($configured)
                <button type="button" class="btn btn-ghost" wire:click="skip">{{ __('Skip for now') }}</button>
                <button type="button" class="btn btn-secondary" wire:click="sendTest" wire:loading.attr="disabled">
                    <span class="spin" wire:loading wire:target="sendTest" aria-hidden="true"></span>
                    {{ __('Send test email') }}
                </button>
            @endif

            <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">
                <span class="spin" wire:loading wire:target="save" aria-hidden="true"></span>
                {{ __('Continue') }}
            </button>
        </div>
    </section>
</div>

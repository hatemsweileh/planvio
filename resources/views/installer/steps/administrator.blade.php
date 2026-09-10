<div>
    <section class="panel">
        @include('installer.partials.panel-head', ['step' => $step, 'heading' => $heading, 'lede' => $lede])

        <form class="panel-body stack" wire:submit="save">
            <div class="grid grid-2">
                <div class="field">
                    <label class="label" for="admin-name">
                        {{ __('Your name') }} <span class="req" aria-hidden="true">*</span>
                    </label>
                    <input class="input @error('name') is-invalid @enderror"
                           id="admin-name" type="text" wire:model.blur="name" required autofocus
                           autocomplete="name" maxlength="80">
                    @error('name')<p class="error" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label class="label" for="admin-email">
                        {{ __('Email address') }} <span class="req" aria-hidden="true">*</span>
                    </label>
                    <input class="input @error('email') is-invalid @enderror"
                           id="admin-email" type="email" wire:model.blur="email" required
                           autocomplete="username" spellcheck="false" maxlength="191">
                    @error('email')
                        <p class="error" role="alert">{{ $message }}</p>
                    @else
                        <p class="hint">{{ __('This is the address you sign in with, and where a password reset would be sent.') }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label class="label" for="admin-password">
                        {{ __('Password') }} <span class="req" aria-hidden="true">*</span>
                    </label>
                    <input class="input @error('password') is-invalid @enderror"
                           id="admin-password" type="password" wire:model.blur="password" required
                           autocomplete="new-password">
                    @error('password')
                        <p class="error" role="alert">{{ $message }}</p>
                    @else
                        <p class="hint">{{ $this->passwordPolicy() }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label class="label" for="admin-password-confirmation">
                        {{ __('Confirm password') }} <span class="req" aria-hidden="true">*</span>
                    </label>
                    <input class="input @error('passwordConfirmation') is-invalid @enderror"
                           id="admin-password-confirmation" type="password"
                           wire:model.blur="passwordConfirmation" required autocomplete="new-password">
                    @error('passwordConfirmation')<p class="error" role="alert">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="note">
                <p><strong>{{ __('This account can do everything.') }}</strong></p>
                <p>{{ __('It administers the whole installation and owns your first workspace. Turn on two-factor authentication for it as soon as you have signed in — it is in Profile → Security.') }}</p>
            </div>
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

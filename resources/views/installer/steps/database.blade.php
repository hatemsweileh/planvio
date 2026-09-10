<div>
    <section class="panel">
        @include('installer.partials.panel-head', ['step' => $step, 'heading' => $heading, 'lede' => $lede])

        <form class="panel-body stack" wire:submit="save">
            <div class="grid grid-2">
                <div class="field span-2">
                    <label class="label" for="db-database">
                        {{ __('Database name') }} <span class="req" aria-hidden="true">*</span>
                    </label>
                    <input class="input @error('database') is-invalid @enderror"
                           id="db-database" type="text" wire:model.blur="database" required autofocus
                           autocomplete="off" spellcheck="false" placeholder="acme_planvio">
                    @error('database')
                        <p class="error" role="alert">{{ $message }}</p>
                    @else
                        <p class="hint">{{ __('On cPanel this includes your account prefix, so it usually looks like acme_planvio.') }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label class="label" for="db-username">
                        {{ __('Database user') }} <span class="req" aria-hidden="true">*</span>
                    </label>
                    <input class="input @error('username') is-invalid @enderror"
                           id="db-username" type="text" wire:model.blur="username" required
                           autocomplete="off" spellcheck="false" placeholder="acme_planvio">
                    @error('username')<p class="error" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label class="label" for="db-password">{{ __('Database password') }}</label>
                    <input class="input @error('password') is-invalid @enderror"
                           id="db-password" type="password" wire:model.blur="password"
                           autocomplete="off">
                    @error('password')<p class="error" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label class="label" for="db-host">
                        {{ __('Host') }} <span class="req" aria-hidden="true">*</span>
                    </label>
                    <input class="input @error('host') is-invalid @enderror"
                           id="db-host" type="text" wire:model.blur="host" required
                           autocomplete="off" spellcheck="false">
                    @error('host')
                        <p class="error" role="alert">{{ $message }}</p>
                    @else
                        <p class="hint">{{ __('Use 127.0.0.1 unless your host told you otherwise.') }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label class="label" for="db-port">
                        {{ __('Port') }} <span class="req" aria-hidden="true">*</span>
                    </label>
                    <input class="input @error('port') is-invalid @enderror"
                           id="db-port" type="number" min="1" max="65535" wire:model.blur="port" required
                           inputmode="numeric">
                    @error('port')<p class="error" role="alert">{{ $message }}</p>@enderror
                </div>
            </div>

            @if ($result !== [])
                @include('installer.partials.test-result', ['result' => $result])
            @endif

            <p class="hint">{{ __('Planvio never creates the database itself. Create an empty one first, then add your user to it with all privileges.') }}</p>
        </form>

        <div class="panel-foot">
            <button type="button" class="btn btn-ghost" wire:click="back">{{ __('Back') }}</button>
            <span class="spacer"></span>

            <button type="button" class="btn btn-secondary" wire:click="test" wire:loading.attr="disabled">
                <span class="spin" wire:loading wire:target="test" aria-hidden="true"></span>
                {{ __('Test connection') }}
            </button>

            <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">
                <span class="spin" wire:loading wire:target="save" aria-hidden="true"></span>
                {{ __('Continue') }}
            </button>
        </div>
    </section>
</div>

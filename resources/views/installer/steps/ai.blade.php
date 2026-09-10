<div>
    <section class="panel">
        @include('installer.partials.panel-head', ['step' => $step, 'heading' => $heading, 'lede' => $lede])

        <form class="panel-body stack" wire:submit="save">
            <label class="check">
                <input type="checkbox" wire:model.live="enabled" @checked($enabled)>
                <span>
                    <span class="check-title">{{ __('Turn on AI for this installation') }}</span>
                    <span class="check-note">{{ __('Planvio calls only the provider you name here, and only when somebody asks it to. Leave it off and every other feature works exactly the same.') }}</span>
                </span>
            </label>

            <div class="grid grid-2" @if (! $enabled) hidden @endif>
                <div class="field">
                    <label class="label" for="ai-driver">{{ __('Provider') }}</label>
                    <select class="select @error('driver') is-invalid @enderror"
                            id="ai-driver" wire:model.live="driver">
                        @foreach ($this->drivers() as $value => $label)
                            <option value="{{ $value }}" @selected($driver === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('driver')<p class="error" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label class="label" for="ai-model">
                        {{ __('Model') }} <span class="req" aria-hidden="true">*</span>
                    </label>
                    <input class="input @error('model') is-invalid @enderror"
                           id="ai-model" type="text" wire:model.blur="model" list="ai-model-suggestions"
                           autocomplete="off" spellcheck="false" maxlength="191">
                    <datalist id="ai-model-suggestions">
                        @foreach ($this->suggestedModels() as $suggestion)
                            <option value="{{ $suggestion }}"></option>
                        @endforeach
                    </datalist>
                    @error('model')<p class="error" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="field span-2">
                    <label class="label" for="ai-base-url">
                        {{ __('Base URL') }}
                        @if ($this->requiresBaseUrl())<span class="req" aria-hidden="true">*</span>@endif
                    </label>
                    <input class="input @error('baseUrl') is-invalid @enderror"
                           id="ai-base-url" type="url" wire:model.blur="baseUrl"
                           autocomplete="off" spellcheck="false" placeholder="https://api.openai.com/v1">
                    @error('baseUrl')
                        <p class="error" role="alert">{{ $message }}</p>
                    @else
                        <p class="hint">{{ __('Anything speaking the OpenAI chat-completions protocol works here: OpenRouter, Groq, Together, Azure, Ollama or LM Studio on your own machine.') }}</p>
                    @enderror
                </div>

                <div class="field span-2">
                    <label class="label" for="ai-api-key">{{ __('API key') }}</label>
                    <input class="input @error('apiKey') is-invalid @enderror"
                           id="ai-api-key" type="password" wire:model.blur="apiKey"
                           autocomplete="off" spellcheck="false"
                           placeholder="{{ $hasStoredKey ? __('A key is already saved — leave blank to keep it') : '' }}">
                    @error('apiKey')
                        <p class="error" role="alert">{{ $message }}</p>
                    @else
                        <p class="hint">{{ __('Stored encrypted in the database, never in a file, and never shown again after this screen. A local model may not need one.') }}</p>
                    @enderror
                </div>

                <div class="field span-2">
                    <label class="label" for="ai-mode">{{ __('Default mode') }}</label>
                    <select class="select @error('mode') is-invalid @enderror" id="ai-mode" wire:model="mode">
                        @foreach ($this->modes() as $value => $label)
                            <option value="{{ $value }}" @selected($mode === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('mode')
                        <p class="error" role="alert">{{ $message }}</p>
                    @else
                        <p class="hint">{{ __('Assistant answers questions and changes nothing. Copilot proposes changes and asks before each one. Autonomous acts within the limits you set. You can change this per workspace afterwards.') }}</p>
                    @enderror
                </div>
            </div>

            @if ($result !== [])
                @include('installer.partials.test-result', ['result' => $result])
            @endif

            @unless ($enabled)
                <div class="note">
                    <p>{{ __('AI is off, which is how Planvio ships. Turning it on later is one screen in the admin panel — no reinstall, no downtime.') }}</p>
                </div>
            @endunless
        </form>

        <div class="panel-foot">
            <button type="button" class="btn btn-ghost" wire:click="back">{{ __('Back') }}</button>
            <span class="spacer"></span>

            @if ($enabled)
                <button type="button" class="btn btn-ghost" wire:click="skip">{{ __('Skip for now') }}</button>
                <button type="button" class="btn btn-secondary" wire:click="testConnection" wire:loading.attr="disabled">
                    <span class="spin" wire:loading wire:target="testConnection" aria-hidden="true"></span>
                    {{ __('Test connection') }}
                </button>
            @endif

            <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">
                <span class="spin" wire:loading wire:target="save" aria-hidden="true"></span>
                {{ __('Continue') }}
            </button>
        </div>
    </section>
</div>

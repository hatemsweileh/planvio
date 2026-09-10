@extends('layouts.guest')

@section('title', __('Two-factor authentication'))
@section('heading', __('Two-factor authentication'))
@section('subheading', $enabled
    ? __('Your account is protected by an authenticator app.')
    : __('Add a second step to sign-in using an authenticator app.'))

@section('content')
    @if ($recoveryCodes)
        {{--
            The only time these are ever shown. They are stored encrypted and there is no
            screen that reveals them again.
        --}}
        <div class="mb-5 rounded-lg border border-[var(--line-DEFAULT)] bg-[var(--surface-sunken)] p-4">
            <p class="text-xs font-medium text-[var(--text-strong)]">{{ __('Recovery codes') }}</p>
            <p class="mt-1 text-xs text-[var(--text-muted)]">
                {{ __('Store these somewhere safe. Each one signs you in once if you lose your phone, and they are not shown again.') }}
            </p>
            <ul class="mt-3 grid grid-cols-2 gap-1 font-mono text-xs text-[var(--text-DEFAULT)]">
                @foreach ($recoveryCodes as $code)
                    <li>{{ $code }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($enabled)
        <div class="space-y-5">
            <p class="text-sm text-[var(--text-muted)]">
                {{ __('Unused recovery codes remaining: :count', ['count' => $remainingRecoveryCodes]) }}
            </p>

            <form method="POST" action="{{ route('two-factor.recovery-codes') }}" class="space-y-3">
                @csrf
                <x-ui.field :label="__('Current password')" for="current_password"
                            :error="$errors->first('current_password')" required>
                    <x-ui.input id="current_password" name="current_password" type="password" size="lg" required
                                autocomplete="current-password" :invalid="$errors->has('current_password')" />
                </x-ui.field>

                <x-ui.button type="submit" variant="secondary" size="lg" class="w-full">
                    {{ __('Generate new recovery codes') }}
                </x-ui.button>
            </form>

            @unless ($required)
                <form method="POST" action="{{ route('two-factor.disable') }}"
                      class="border-t border-[var(--line-subtle)] pt-5">
                    @csrf
                    @method('DELETE')
                    <p class="mb-3 text-xs text-[var(--text-muted)]">
                        {{ __('Turning two-factor off also deletes your recovery codes.') }}
                    </p>
                    <x-ui.field :label="__('Current password')" for="disable_current_password" required>
                        <x-ui.input id="disable_current_password" name="current_password" type="password" size="lg"
                                    required autocomplete="current-password" />
                    </x-ui.field>
                    <x-ui.button type="submit" variant="danger" size="lg" class="mt-3 w-full">
                        {{ __('Turn off two-factor authentication') }}
                    </x-ui.button>
                </form>
            @endunless
        </div>
    @elseif ($pending)
        <div class="space-y-4">
            <p class="text-sm text-[var(--text-muted)]">
                {{ __('Scan this code with your authenticator app, then enter the six digits it shows.') }}
            </p>

            <div class="flex justify-center rounded-lg border border-[var(--line-subtle)] bg-white p-4">
                {!! $qrCode !!}
            </div>

            <details class="text-xs text-[var(--text-muted)]">
                <summary class="cursor-pointer">{{ __('Cannot scan the code?') }}</summary>
                <p class="mt-2">{{ __('Enter this key in your app instead:') }}</p>
                <code class="mt-1 block break-all font-mono text-[var(--text-DEFAULT)]">{{ $secret }}</code>
            </details>

            <form method="POST" action="{{ route('two-factor.confirm') }}" class="space-y-3">
                @csrf
                <x-ui.field :label="__('Authentication code')" for="code" :error="$errors->first('code')" required>
                    <x-ui.input id="code" name="code" type="text" size="lg" required autofocus
                                autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]*" maxlength="6"
                                :invalid="$errors->has('code')" class="text-center tracking-[0.4em]" />
                </x-ui.field>

                <x-ui.button type="submit" variant="primary" size="lg" class="w-full">
                    {{ __('Confirm and turn on') }}
                </x-ui.button>
            </form>
        </div>
    @else
        <form method="POST" action="{{ route('two-factor.enable') }}">
            @csrf
            <x-ui.button type="submit" variant="primary" size="lg" class="w-full">
                {{ __('Set up two-factor authentication') }}
            </x-ui.button>
        </form>
    @endif
@endsection

@section('footer')
    <form method="POST" action="{{ route('logout') }}" class="inline">
        @csrf
        <button type="submit" class="text-[var(--accent)] hover:underline">{{ __('Sign out') }}</button>
    </form>
@endsection

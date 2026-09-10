@extends('layouts.guest')

@section('title', __('Two-factor authentication'))
@section('heading', __('Two-factor authentication'))
@section('subheading', __('Enter the six-digit code from your authenticator app.'))

@section('content')
    <div x-data="{ recovery: {{ $errors->has('recovery_code') ? 'true' : 'false' }} }">

        <form method="POST" action="{{ route('two-factor.login') }}" class="space-y-4">
            @csrf

            <div x-show="! recovery">
                <x-ui.field :label="__('Authentication code')" for="code" :error="$errors->first('code')">
                    <x-ui.input id="code" name="code" type="text" size="lg"
                                autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]*"
                                maxlength="6" autofocus :invalid="$errors->has('code')"
                                class="text-center tracking-[0.4em]" />
                </x-ui.field>
            </div>

            <div x-show="recovery" x-cloak>
                <x-ui.field :label="__('Recovery code')" for="recovery_code"
                            :error="$errors->first('recovery_code')"
                            :hint="__('Each recovery code can be used once.')">
                    <x-ui.input id="recovery_code" name="recovery_code" type="text" size="lg"
                                autocomplete="one-time-code" :invalid="$errors->has('recovery_code')" />
                </x-ui.field>
            </div>

            <x-ui.button type="submit" variant="primary" size="lg" class="w-full">
                {{ __('Continue') }}
            </x-ui.button>
        </form>

        @if ($hasRecoveryCodes)
            <button type="button" x-on:click="recovery = ! recovery"
                    class="mt-4 w-full text-center text-sm text-[var(--accent)] hover:underline">
                <span x-show="! recovery">{{ __('Use a recovery code instead') }}</span>
                <span x-show="recovery" x-cloak>{{ __('Use an authentication code instead') }}</span>
            </button>
        @endif
    </div>
@endsection

@section('footer')
    <a href="{{ route('login') }}" class="text-[var(--accent)] hover:underline">{{ __('Back to sign in') }}</a>
@endsection

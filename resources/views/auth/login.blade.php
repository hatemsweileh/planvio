@extends('layouts.guest')

@section('title', __('Sign in'))
@section('heading', __('Sign in'))
@section('subheading', __('Welcome back.'))

@section('content')
    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <x-ui.field :label="__('Email address')" for="email" :error="$errors->first('email')" required>
            <x-ui.input id="email" name="email" type="email" size="lg" required autofocus
                        autocomplete="username" inputmode="email"
                        :value="old('email')" :invalid="$errors->has('email')" />
        </x-ui.field>

        <x-ui.field :label="__('Password')" for="password" :error="$errors->first('password')" required>
            <x-ui.input id="password" name="password" type="password" size="lg" required
                        autocomplete="current-password" :invalid="$errors->has('password')" />
        </x-ui.field>

        <div class="flex items-center justify-between gap-3">
            <x-ui.checkbox name="remember" value="1" :label="__('Stay signed in')" :checked="old('remember')" />

            <a href="{{ route('password.request') }}"
               class="text-sm text-[var(--accent)] hover:underline">{{ __('Forgot password?') }}</a>
        </div>

        <x-ui.button type="submit" variant="primary" size="lg" class="w-full">
            {{ __('Sign in') }}
        </x-ui.button>
    </form>
@endsection

@section('footer')
    @if ($canRegister ?? false)
        {{ __('No account yet?') }}
        <a href="{{ route('register') }}" class="text-[var(--accent)] hover:underline">{{ __('Create one') }}</a>
    @endif
@endsection

@extends('layouts.guest')

@section('title', __('Create your account'))
@section('heading', __('Create your account'))

@section('content')
    <form method="POST" action="{{ route('register') }}" class="space-y-4">
        @csrf

        <x-ui.field :label="__('Full name')" for="name" :error="$errors->first('name')" required>
            <x-ui.input id="name" name="name" type="text" size="lg" required autofocus
                        autocomplete="name" :value="old('name')" :invalid="$errors->has('name')" />
        </x-ui.field>

        <x-ui.field :label="__('Email address')" for="email" :error="$errors->first('email')" required>
            <x-ui.input id="email" name="email" type="email" size="lg" required
                        autocomplete="username" inputmode="email"
                        :value="old('email')" :invalid="$errors->has('email')" />
        </x-ui.field>

        <x-ui.field :label="__('Password')" for="password" :error="$errors->first('password')"
                    :hint="\App\Rules\StrongPassword::description()" required>
            <x-ui.input id="password" name="password" type="password" size="lg" required
                        autocomplete="new-password" :invalid="$errors->has('password')" />
        </x-ui.field>

        <x-ui.field :label="__('Confirm password')" for="password_confirmation"
                    :error="$errors->first('password_confirmation')" required>
            <x-ui.input id="password_confirmation" name="password_confirmation" type="password" size="lg" required
                        autocomplete="new-password" />
        </x-ui.field>

        <x-ui.button type="submit" variant="primary" size="lg" class="w-full">
            {{ __('Create account') }}
        </x-ui.button>
    </form>
@endsection

@section('footer')
    {{ __('Already have an account?') }}
    <a href="{{ route('login') }}" class="text-[var(--accent)] hover:underline">{{ __('Sign in') }}</a>
@endsection

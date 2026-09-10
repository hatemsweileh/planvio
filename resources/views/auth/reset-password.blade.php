@extends('layouts.guest')

@section('title', __('Choose a new password'))
@section('heading', __('Choose a new password'))

@section('content')
    <form method="POST" action="{{ route('password.store') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <x-ui.field :label="__('Email address')" for="email" :error="$errors->first('email')" required>
            <x-ui.input id="email" name="email" type="email" size="lg" required
                        autocomplete="username" inputmode="email"
                        :value="old('email', $email)" :invalid="$errors->has('email')" />
        </x-ui.field>

        <x-ui.field :label="__('New password')" for="password" :error="$errors->first('password')"
                    :hint="\App\Rules\StrongPassword::description()" required>
            <x-ui.input id="password" name="password" type="password" size="lg" required autofocus
                        autocomplete="new-password" :invalid="$errors->has('password')" />
        </x-ui.field>

        <x-ui.field :label="__('Confirm new password')" for="password_confirmation"
                    :error="$errors->first('password_confirmation')" required>
            <x-ui.input id="password_confirmation" name="password_confirmation" type="password" size="lg" required
                        autocomplete="new-password" />
        </x-ui.field>

        <x-ui.button type="submit" variant="primary" size="lg" class="w-full">
            {{ __('Save new password') }}
        </x-ui.button>
    </form>
@endsection

@section('footer')
    <a href="{{ route('login') }}" class="text-[var(--accent)] hover:underline">{{ __('Back to sign in') }}</a>
@endsection

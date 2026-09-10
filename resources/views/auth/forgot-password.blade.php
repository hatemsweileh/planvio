@extends('layouts.guest')

@section('title', __('Reset your password'))
@section('heading', __('Reset your password'))
@section('subheading', __('Enter your email address and we will send you a link to choose a new one.'))

@section('content')
    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <x-ui.field :label="__('Email address')" for="email" :error="$errors->first('email')" required>
            <x-ui.input id="email" name="email" type="email" size="lg" required autofocus
                        autocomplete="username" inputmode="email"
                        :value="old('email')" :invalid="$errors->has('email')" />
        </x-ui.field>

        <x-ui.button type="submit" variant="primary" size="lg" class="w-full">
            {{ __('Send reset link') }}
        </x-ui.button>
    </form>
@endsection

@section('footer')
    <a href="{{ route('login') }}" class="text-[var(--accent)] hover:underline">{{ __('Back to sign in') }}</a>
@endsection

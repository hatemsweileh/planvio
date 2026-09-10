@extends('layouts.guest')

@section('title', __('Verify your email'))
@section('heading', __('Verify your email'))
@section('subheading', __('We sent a verification link to :email. Open it to confirm the address is yours.', ['email' => auth()->user()?->email]))

@section('content')
    <div class="space-y-3">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-ui.button type="submit" variant="primary" size="lg" class="w-full">
                {{ __('Send the link again') }}
            </x-ui.button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <x-ui.button type="submit" variant="secondary" size="lg" class="w-full">
                {{ __('Sign out') }}
            </x-ui.button>
        </form>
    </div>
@endsection

@extends('errors.layout')
@section('code', __('Error :code', ['code' => 401]))
@section('title', __('Please sign in'))
@section('message', __('You need to be signed in to view this page.'))
@section('actions')
    <a class="btn primary" href="{{ route('login') }}">{{ __('Sign in') }}</a>
@endsection

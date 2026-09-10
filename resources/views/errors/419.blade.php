@extends('errors.layout')
@section('code', __('Error :code', ['code' => 419]))
@section('title', __('Your session expired'))
@section('message', __('For your security this page was open too long. Reload it and try again — nothing you submitted was saved.'))
@section('actions')
    <a class="btn primary" href="{{ url()->current() }}">{{ __('Reload the page') }}</a>
    <a class="btn ghost" href="{{ url('/') }}">{{ __('Go home') }}</a>
@endsection

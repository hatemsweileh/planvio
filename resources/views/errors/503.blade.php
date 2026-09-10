@extends('errors.layout')
@section('code', __('Maintenance'))
@section('title', __(':app is being updated', ['app' => config('planvio.brand.name', 'Planvio')]))
@section('message')
    {{ $message ?? __('We are performing scheduled maintenance and will be back shortly. Thanks for your patience.') }}
@endsection
@section('actions')
    <a class="btn ghost" href="{{ url()->current() }}">{{ __('Try again') }}</a>
@endsection

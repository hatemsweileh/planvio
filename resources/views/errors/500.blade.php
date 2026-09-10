@extends('errors.layout')
@section('code', __('Error :code', ['code' => 500]))
@section('title', __('Something went wrong'))
@section('message')
    {{ __(':app hit an unexpected error. The details were written to the application log; nothing was sent anywhere else.', ['app' => config('planvio.brand.name', 'Planvio')]) }}
@endsection
@section('reference')
    {{-- The path and the timestamp are read by a person at a terminal, so both are isolated
         from the surrounding prose rather than reordered by it. --}}
    {{ __('An administrator can find the details in :path around this time:', ['path' => 'storage/logs']) }}
    <code dir="ltr">{{ now()->toDateTimeString() }} UTC</code>
@endsection

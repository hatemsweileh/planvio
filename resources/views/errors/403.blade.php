@extends('errors.layout')
@section('code', __('Error :code', ['code' => 403]))
@section('title', __('You do not have access'))
@section('message')
    {{-- An abort() message is already translated by whoever raised it. --}}
    {{ $exception?->getMessage() ?: __('Your role in this workspace does not allow this action. Ask a workspace owner or administrator if you think this is a mistake.') }}
@endsection

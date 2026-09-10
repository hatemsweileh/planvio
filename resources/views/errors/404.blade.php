@extends('errors.layout')
@section('code', __('Error :code', ['code' => 404]))
@section('title', __('We could not find that'))
@section('message', __('The page, project or task you are looking for does not exist, was moved, or you no longer have access to it.'))

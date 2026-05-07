@extends('statamic::layout')
@section('title', __('Automation Templates'))

@section('content')
    <div data-automations-app="templates"></div>
@endsection

@push('head')
    @include('statamic-automations::cp.partials.assets')
@endpush

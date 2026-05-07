@extends('statamic::layout')
@section('title', __('Automations'))

@section('content')
    <div data-automations-app="list"></div>
@endsection

@push('head')
    @include('statamic-automations::cp.partials.assets')
@endpush

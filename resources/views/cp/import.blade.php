@extends('statamic::layout')
@section('title', __('Import Automation'))

@section('content')
    <div data-automations-app="import"></div>
@endsection

@push('head')
    @include('statamic-automations::cp.partials.assets')
@endpush

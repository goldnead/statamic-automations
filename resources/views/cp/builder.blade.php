@extends('statamic::layout')
@section('title', __('Automation Builder'))
@section('wrapper_class', 'max-w-full')

@section('content')
    <div
        data-automations-app="builder"
        @if(request()->route('automation'))
            data-prop-automation_id="{{ json_encode(request()->route('automation')) }}"
        @endif
    ></div>
@endsection

@push('head')
    @include('statamic-automations::cp.partials.assets')
@endpush

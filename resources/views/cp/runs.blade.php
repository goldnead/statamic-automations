@extends('statamic::layout')
@section('title', __('Automation Runs'))

@section('content')
    @php $runId = request()->route('run'); @endphp

    @if($runId)
        <div data-automations-app="run-detail" data-prop-run_id="{{ json_encode($runId) }}"></div>
    @else
        <div data-automations-app="runs"></div>
    @endif
@endsection

@push('head')
    @include('statamic-automations::cp.partials.assets')
@endpush

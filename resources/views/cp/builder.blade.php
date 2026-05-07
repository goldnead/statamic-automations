@extends('statamic::layout')
@section('title', __('Automation Builder'))

@section('content')
    <div id="automations-builder" data-automation="{{ request()->route('automation') }}">
        <p class="text-grey p-4">{{ __('Builder will be rendered here in Phase H.') }}</p>
    </div>
@endsection

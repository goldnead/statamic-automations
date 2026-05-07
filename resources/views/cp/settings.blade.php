@extends('statamic::layout')
@section('title', __('Automation Settings'))

@section('content')
    <h1 class="mb-6">{{ __('Settings') }}</h1>
    <div class="card p-4">
        <p class="text-grey">
            {{ __('Most automation settings live in your application config file.') }}
        </p>
        <pre class="text-xs bg-grey-10 p-2 mt-2 rounded">config/automations.php</pre>
    </div>
@endsection

{{--
    Asset partial for the Statamic Automations Vue front-end.

    The package ships a pre-built `cp.js` + `cp.css` in
    resources/dist/ which is published into the application's
    public/vendor/ folder via:

        php artisan vendor:publish --tag=statamic-automations-assets

    During development, run `npm run dev` inside the package and
    point the meta tag below at the dev server.
--}}
<meta name="automations-base" content="{{ url('/cp/automations/api') }}">

@if(file_exists(public_path('vendor/statamic-automations/cp.js')))
    <script type="module" src="{{ asset('vendor/statamic-automations/cp.js') }}" defer></script>
@endif
@if(file_exists(public_path('vendor/statamic-automations/automations.css')))
    <link rel="stylesheet" href="{{ asset('vendor/statamic-automations/automations.css') }}">
@endif

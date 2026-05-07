<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Statamic Automations CP Routes
|--------------------------------------------------------------------------
|
| Stub routes — real controllers will be added in Phase G (CP API).
| For Phase A we only register named placeholder routes so navigation
| and tests can resolve URLs without errors.
|
*/

Route::prefix('automations')
    ->name('automations.')
    ->middleware(class_exists(\Statamic\Http\Middleware\CP\Authorize::class) ? ['statamic.cp.authenticated'] : [])
    ->group(function () {
        // View routes (placeholders for Phase H UI).
        Route::view('/', 'statamic-automations::cp.index')->name('index');
        Route::view('/create', 'statamic-automations::cp.builder')->name('create');
        Route::view('/{automation}', 'statamic-automations::cp.builder')->name('show');
        Route::view('/runs', 'statamic-automations::cp.runs')->name('runs.index');
        Route::view('/runs/{run}', 'statamic-automations::cp.runs')->name('runs.show');
        Route::view('/templates', 'statamic-automations::cp.templates')->name('templates.index');
        Route::view('/settings', 'statamic-automations::cp.settings')->name('settings');
    });

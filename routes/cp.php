<?php

use Goldnead\StatamicAutomations\Http\Controllers\AutomationsController;
use Goldnead\StatamicAutomations\Http\Controllers\ExportImportController;
use Goldnead\StatamicAutomations\Http\Controllers\NodesController;
use Goldnead\StatamicAutomations\Http\Controllers\RunsController;
use Goldnead\StatamicAutomations\Http\Controllers\SettingsController;
use Goldnead\StatamicAutomations\Http\Controllers\TemplatesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Statamic Automations CP Routes
|--------------------------------------------------------------------------
*/

$middleware = class_exists(\Statamic\Http\Middleware\CP\Authorize::class)
    ? ['statamic.cp.authenticated']
    : ['web', 'auth'];

Route::prefix('automations')
    ->name('automations.')
    ->middleware($middleware)
    ->group(function () {
        // ----- View routes (Blade entry points; UI lives in Vue Flow) -----
        Route::view('/', 'statamic-automations::cp.index')->name('index');
        Route::view('/create', 'statamic-automations::cp.builder')->name('create');
        Route::view('/runs', 'statamic-automations::cp.runs')->name('runs.index');
        Route::view('/runs/{run}', 'statamic-automations::cp.runs')->name('runs.show');
        Route::view('/templates', 'statamic-automations::cp.templates')->name('templates.index');
        Route::view('/import', 'statamic-automations::cp.import')->name('import');
        Route::view('/settings', 'statamic-automations::cp.settings')->name('settings');
        Route::view('/{automation}', 'statamic-automations::cp.builder')->name('show');

        // ----- JSON API endpoints (consumed by the Vue Flow front-end) -----
        Route::prefix('api')->name('api.')->group(function () {
            // Automations CRUD + actions
            Route::get('automations', [AutomationsController::class, 'index'])->name('automations.index');
            Route::post('automations', [AutomationsController::class, 'store'])->name('automations.store');
            Route::get('automations/{automation}', [AutomationsController::class, 'show'])->name('automations.show');
            Route::patch('automations/{automation}', [AutomationsController::class, 'update'])->name('automations.update');
            Route::delete('automations/{automation}', [AutomationsController::class, 'destroy'])->name('automations.destroy');
            Route::post('automations/{automation}/duplicate', [AutomationsController::class, 'duplicate'])->name('automations.duplicate');
            Route::post('automations/{automation}/validate', [AutomationsController::class, 'validateAutomation'])->name('automations.validate');
            Route::post('automations/{automation}/test', [AutomationsController::class, 'test'])->name('automations.test');
            Route::post('automations/{automation}/enable', [AutomationsController::class, 'enable'])->name('automations.enable');
            Route::post('automations/{automation}/disable', [AutomationsController::class, 'disable'])->name('automations.disable');

            // Node / trigger / action metadata
            Route::get('nodes', [NodesController::class, 'index'])->name('nodes.index');
            Route::get('triggers', [NodesController::class, 'triggers'])->name('triggers.index');
            Route::get('actions', [NodesController::class, 'actions'])->name('actions.index');
            Route::get('nodes/{handle}', [NodesController::class, 'describe'])
                ->where('handle', '[A-Za-z0-9_.-]+')
                ->name('nodes.describe');
            Route::get('context-schema/{handle}', [NodesController::class, 'contextSchema'])
                ->where('handle', '[A-Za-z0-9_.-]+')
                ->name('context-schema');
            Route::get('options/{source}', [NodesController::class, 'options'])
                ->where('source', '[A-Za-z0-9_.-]+')
                ->name('options');

            // Runs
            Route::get('runs', [RunsController::class, 'index'])->name('runs.index');
            Route::get('runs/{run}', [RunsController::class, 'show'])->name('runs.show');
            Route::post('runs/{run}/retry', [RunsController::class, 'retry'])->name('runs.retry');
            Route::post('node-runs/{nodeRun}/retry', [RunsController::class, 'retryNodeRun'])->name('node-runs.retry');

            // Templates
            Route::get('templates', [TemplatesController::class, 'index'])->name('templates.index');
            Route::post('templates/{handle}/install', [TemplatesController::class, 'install'])
                ->where('handle', '[A-Za-z0-9_-]+')
                ->name('templates.install');

            // Settings
            Route::get('settings', [SettingsController::class, 'show'])->name('settings.show');

            // Export / Import
            Route::get('automations/{automation}/export', [ExportImportController::class, 'export'])->name('automations.export');
            Route::get('automations/{automation}/sync-status', [ExportImportController::class, 'syncStatus'])->name('automations.sync-status');
            Route::post('automations/{automation}/sync-to-file', [ExportImportController::class, 'syncToFile'])->name('automations.sync-to-file');
            Route::post('automations/import', [ExportImportController::class, 'import'])->name('automations.import');
            Route::get('automations/file-storage/list', [ExportImportController::class, 'listFiles'])->name('automations.files.list');
        });
    });

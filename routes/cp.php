<?php

use Goldnead\StatamicAutomations\Http\Controllers\AutomationsController;
use Goldnead\StatamicAutomations\Http\Controllers\ExportImportController;
use Goldnead\StatamicAutomations\Http\Controllers\NodesController;
use Goldnead\StatamicAutomations\Http\Controllers\Pages\AutomationsPageController;
use Goldnead\StatamicAutomations\Http\Controllers\Pages\ImportPageController;
use Goldnead\StatamicAutomations\Http\Controllers\Pages\RunsPageController;
use Goldnead\StatamicAutomations\Http\Controllers\Pages\SettingsPageController;
use Goldnead\StatamicAutomations\Http\Controllers\Pages\TemplatesPageController;
use Goldnead\StatamicAutomations\Http\Controllers\RunsController;
use Goldnead\StatamicAutomations\Http\Controllers\SettingsController;
use Goldnead\StatamicAutomations\Http\Controllers\TemplatesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Statamic Automations CP Routes
|--------------------------------------------------------------------------
|
| Two route trees live here:
|
|   - Inertia GET routes that render Vue pages via @statamic/cms.
|     These are what users navigate to in the CP.
|
|   - /api/* JSON routes used by the canvas and list screens for AJAX
|     interactions (save, validate, test, retry, etc.). They never
|     render a page — only return JSON or no-content responses.
*/

/*
 * These routes are registered through the ServiceProvider's
 * `$routes = ['cp' => ...]` property, so Statamic mounts them under the
 * Control Panel route group automatically — the `/cp` URL prefix, the
 * `statamic.cp.` route-name prefix and the CP authentication middleware are
 * all applied by Statamic. That is why the route names resolve through
 * `cp_route('statamic-automations.*')` (= `statamic.cp.statamic-automations.*`)
 * in the controllers and CP navigation.
 */
Route::prefix('automations')
    ->name('statamic-automations.')
    ->group(function () {
        // ================================================================
        // Inertia pages (rendered via Statamic.$inertia.register())
        // ================================================================

        Route::get('/', [AutomationsPageController::class, 'index'])
            ->name('automations.index');

        Route::get('dashboard', [\Goldnead\StatamicAutomations\Http\Controllers\Pages\DashboardPageController::class, 'index'])
            ->name('dashboard');

        Route::get('automations/create', [AutomationsPageController::class, 'create'])
            ->name('automations.create');

        Route::get('automations/{automation}/edit', [AutomationsPageController::class, 'edit'])
            ->name('automations.edit');

        Route::get('runs', [RunsPageController::class, 'index'])
            ->name('runs.index');

        Route::get('runs/{run}', [RunsPageController::class, 'show'])
            ->name('runs.show');

        Route::get('templates', [TemplatesPageController::class, 'index'])
            ->name('templates.index');

        Route::get('import', [ImportPageController::class, 'show'])
            ->name('import');

        Route::get('settings', [SettingsPageController::class, 'index'])
            ->name('settings');

        Route::get('audit', [\Goldnead\StatamicAutomations\Http\Controllers\Pages\AuditPageController::class, 'index'])
            ->name('audit');

        // ================================================================
        // JSON API (consumed by Vue Flow canvas + Listing AJAX)
        // ================================================================
        Route::prefix('api')->name('api.')->group(function () {
            // Index "ping" endpoint used by the builder for sanity checks.
            Route::get('/', fn () => ['ok' => true, 'service' => 'statamic-automations'])
                ->name('index');

            // Automations CRUD + actions
            Route::get('automations', [AutomationsController::class, 'index'])->name('automations.index');
            Route::post('automations', [AutomationsController::class, 'store'])->name('automations.store');
            Route::get('automations/{automation}', [AutomationsController::class, 'show'])->name('automations.show');
            Route::patch('automations/{automation}', [AutomationsController::class, 'update'])->name('automations.update');
            Route::delete('automations/{automation}', [AutomationsController::class, 'destroy'])->name('automations.destroy');
            Route::post('automations/{automation}/duplicate', [AutomationsController::class, 'duplicate'])->name('automations.duplicate');
            Route::post('automations/{automation}/validate', [AutomationsController::class, 'validateAutomation'])->name('automations.validate');
            Route::post('automations/{automation}/test', [AutomationsController::class, 'test'])->name('automations.test');
            Route::post('automations/{automation}/test-node', [AutomationsController::class, 'testNode'])->name('automations.test-node');
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

            // Versions + audit
            Route::get('automations/{automation}/versions', [\Goldnead\StatamicAutomations\Http\Controllers\VersionsController::class, 'index'])->name('automations.versions');
            Route::post('automations/{automation}/versions/{timestamp}/revert', [\Goldnead\StatamicAutomations\Http\Controllers\VersionsController::class, 'revert'])
                ->where('timestamp', '[0-9]+')
                ->name('automations.versions.revert');
            Route::get('audit', [\Goldnead\StatamicAutomations\Http\Controllers\AuditController::class, 'index'])->name('audit.list');

            // Runs
            Route::get('runs', [RunsController::class, 'index'])->name('runs.list');
            Route::get('runs/{run}', [RunsController::class, 'show'])->name('runs.detail');
            Route::post('runs/{run}/retry', [RunsController::class, 'retry'])->name('runs.retry');
            Route::post('node-runs/{nodeRun}/retry', [RunsController::class, 'retryNodeRun'])->name('node-runs.retry');

            // Templates
            Route::get('templates', [TemplatesController::class, 'index'])->name('templates.list');
            Route::post('templates/{handle}/install', [TemplatesController::class, 'install'])
                ->where('handle', '[A-Za-z0-9_-]+')
                ->name('templates.install');

            // Settings
            Route::get('settings', [SettingsController::class, 'show'])->name('settings.show');
            Route::get('license/status', [SettingsController::class, 'license'])->name('license.status');

            // Export / Import
            Route::get('automations/{automation}/export', [ExportImportController::class, 'export'])->name('automations.export');
            Route::get('automations/{automation}/sync-status', [ExportImportController::class, 'syncStatus'])->name('automations.sync-status');
            Route::post('automations/{automation}/sync-to-file', [ExportImportController::class, 'syncToFile'])->name('automations.sync-to-file');
            Route::post('automations/import', [ExportImportController::class, 'import'])->name('automations.import');
            Route::get('automations/file-storage/list', [ExportImportController::class, 'listFiles'])->name('automations.files.list');
        });
    });

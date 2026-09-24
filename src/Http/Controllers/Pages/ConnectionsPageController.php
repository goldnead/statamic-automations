<?php

namespace Goldnead\StatamicAutomations\Http\Controllers\Pages;

use Goldnead\StatamicAutomations\Http\Controllers\ConnectionsController;
use Goldnead\StatamicAutomations\Http\Controllers\Controller;
use Goldnead\StatamicAutomations\Models\AutomationConnection;
use Goldnead\StatamicAutomations\Models\AutomationConnectionOperation;
use Goldnead\StatamicAutomations\Support\Setup;
use Inertia\Inertia;
use Statamic\CP\Column;

/**
 * The connections screens: the listing, and one page to create or edit a
 * connection with its operations. Everything they save goes through the JSON
 * API in {@see ConnectionsController}; these hand the pages their data and
 * endpoints, and the edit page reads the connection through the same
 * presenter the API uses, so no credential reaches the browser from here
 * either.
 */
class ConnectionsPageController extends Controller
{
    protected const PERMISSION = 'manage automation connections';

    public function index()
    {
        $this->authorizeAction(self::PERMISSION);

        if ($setup = Setup::guard(__('Connections'), 'automation_connections', 'automation_connection_operations')) {
            return $setup;
        }

        $api = app(ConnectionsController::class);

        $rows = AutomationConnection::query()
            ->withCount('operations')
            ->orderBy('name')
            ->get()
            ->map(fn (AutomationConnection $connection) => $api->present($connection))
            ->values();

        return Inertia::render('statamic-automations::Connections/Index', [
            'title' => __('Connections'),
            'rows' => $rows,
            'columns' => collect([
                Column::make('name')->label(__('Name')),
                Column::make('base_url')->label(__('Base URL')),
                Column::make('auth_type')->label(__('Authentication')),
                Column::make('operations_count')->label(__('Operations'))->numeric(true),
            ])->map->toArray()->all(),
            'createUrl' => cp_route('statamic-automations.connections.create'),
            'authTypes' => $this->authTypeLabels(),
        ]);
    }

    public function create()
    {
        $this->authorizeAction(self::PERMISSION);

        return $this->form(null);
    }

    public function edit(AutomationConnection $automationConnection)
    {
        $this->authorizeAction(self::PERMISSION);

        return $this->form($automationConnection);
    }

    protected function form(?AutomationConnection $connection)
    {
        $connection = $connection
            ? app(ConnectionsController::class)->present($connection, detailed: true)
            : [
                'id' => null,
                'handle' => '',
                'name' => '',
                'base_url' => '',
                'auth_type' => 'none',
                'auth_config' => [],
                'auth_configured' => false,
                'default_headers' => [],
                'timeout' => 15,
                'test_path' => '',
                'operations' => [],
                'used_by' => [],
            ];

        return Inertia::render('statamic-automations::Connections/Edit', [
            'title' => $connection['id'] ? $connection['name'] : __('Create connection'),
            'connection' => $connection,
            'isNew' => $connection['id'] === null,
            'indexUrl' => cp_route('statamic-automations.connections.index'),
            'storeUrl' => cp_route('statamic-automations.api.connections.store'),
            'operationsUrl' => $connection['id']
                ? cp_route('statamic-automations.api.connections.operations.store', $connection['id'])
                : null,
            'authTypes' => $this->authTypeLabels(),
            'authFields' => AutomationConnection::AUTH_FIELDS,
            'methods' => AutomationConnectionOperation::METHODS,
            'inputTypes' => AutomationConnectionOperation::INPUT_TYPES,
            'placeholder' => ConnectionsController::PLACEHOLDER,
        ]);
    }

    /** @return array<string, string> */
    protected function authTypeLabels(): array
    {
        $labels = [
            'none' => __('No authentication'),
            'header' => __('Header'),
            'bearer' => __('Bearer token'),
            'basic' => __('Basic auth'),
        ];

        return collect(AutomationConnection::AUTH_TYPES)
            ->mapWithKeys(fn (string $type) => [$type => $labels[$type] ?? $type])
            ->all();
    }
}

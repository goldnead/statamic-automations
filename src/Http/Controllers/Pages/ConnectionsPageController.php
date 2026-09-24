<?php

namespace Goldnead\StatamicAutomations\Http\Controllers\Pages;

use Goldnead\StatamicAutomations\Http\Controllers\ConnectionsController;
use Goldnead\StatamicAutomations\Http\Controllers\Controller;
use Goldnead\StatamicAutomations\Models\AutomationConnection;
use Goldnead\StatamicAutomations\Models\AutomationConnectionOperation;
use Inertia\Inertia;

/**
 * The connections screen. Everything it shows and saves goes through the JSON
 * API in {@see ConnectionsController}; this only hands the page its endpoints.
 */
class ConnectionsPageController extends Controller
{
    public function index()
    {
        $this->authorizeAction('manage automation connections');

        return Inertia::render('statamic-automations::Connections/Index', [
            'title' => __('Connections'),
            'listUrl' => cp_route('statamic-automations.api.connections.index'),
            'storeUrl' => cp_route('statamic-automations.api.connections.store'),
            'authTypes' => AutomationConnection::AUTH_TYPES,
            'authFields' => AutomationConnection::AUTH_FIELDS,
            'methods' => AutomationConnectionOperation::METHODS,
            'inputTypes' => AutomationConnectionOperation::INPUT_TYPES,
            'placeholder' => ConnectionsController::PLACEHOLDER,
        ]);
    }
}

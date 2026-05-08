<?php

namespace Goldnead\StatamicAutomations\Http\Controllers\Pages;

use Goldnead\StatamicAutomations\Http\Controllers\Controller;
use Inertia\Inertia;

class ImportPageController extends Controller
{
    public function show()
    {
        $this->authorizeAction('create automations');

        return Inertia::render('statamic-automations::Import', [
            'title' => __('Import Automation'),
            'importUrl' => cp_route('statamic-automations.api.automations.import'),
            'indexUrl' => cp_route('statamic-automations.automations.index'),
        ]);
    }
}

<?php

namespace Goldnead\StatamicAutomations\Http\Controllers\Pages;

use Goldnead\StatamicAutomations\Http\Controllers\Controller;
use Goldnead\StatamicAutomations\Integrations\IntegrationDetector;
use Goldnead\StatamicAutomations\Licensing\LicenseManager;
use Inertia\Inertia;

class SettingsPageController extends Controller
{
    public function index(IntegrationDetector $detector, LicenseManager $license)
    {
        $this->authorizeAction('view automations');

        return Inertia::render('statamic-automations::Settings/Show', [
            'title' => __('Automation Settings'),
            'config_path' => 'config/automations.php',
            'queue' => config('automations.queue'),
            'queue_connection' => config('automations.queue_connection'),
            'runs' => config('automations.runs'),
            'test_mode' => config('automations.test_mode'),
            'features' => config('automations.features'),
            'redact_keys' => config('automations.security.redact_keys'),
            'integrations' => $detector->snapshot(),
            'license' => [
                'mode' => config('automations.license.mode'),
                'has_key' => ! empty(config('automations.license.key')),
                'is_valid' => $license->isValid(),
                'features' => config('automations.license.features', []),
            ],
        ]);
    }
}

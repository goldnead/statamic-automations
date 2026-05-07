<?php

namespace Goldnead\StatamicAutomations\Http\Controllers;

use Goldnead\StatamicAutomations\Integrations\IntegrationDetector;
use Goldnead\StatamicAutomations\Licensing\LicenseManager;
use Illuminate\Http\JsonResponse;

class SettingsController extends Controller
{
    public function show(IntegrationDetector $detector, LicenseManager $license): JsonResponse
    {
        $this->authorizeAction('view automations');

        return response()->json([
            'data' => [
                'queue' => config('automations.queue'),
                'queue_connection' => config('automations.queue_connection'),
                'runs' => config('automations.runs'),
                'test_mode' => config('automations.test_mode'),
                'features' => config('automations.features'),
                'security' => [
                    'redact_keys' => config('automations.security.redact_keys'),
                ],
                'integrations' => $detector->snapshot(),
                'license' => $license->status(),
            ],
        ]);
    }

    public function license(LicenseManager $license): JsonResponse
    {
        $this->authorizeAction('view automations');

        return response()->json([
            'data' => $license->status(),
        ]);
    }
}

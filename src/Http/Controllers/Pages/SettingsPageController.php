<?php

namespace Goldnead\StatamicAutomations\Http\Controllers\Pages;

use Goldnead\StatamicAutomations\Http\Controllers\Controller;
use Goldnead\StatamicAutomations\Integrations\IntegrationDetector;
use Goldnead\StatamicAutomations\Support\Settings;
use Inertia\Inertia;

class SettingsPageController extends Controller
{
    public function index(IntegrationDetector $detector, Settings $settings)
    {
        $this->authorizeAction('view automations');

        $values = [];

        foreach (array_keys(Settings::fields()) as $key) {
            $values[$key] = $settings->value($key);
        }

        return Inertia::render('statamic-automations::Settings/Show', [
            'title' => __('Automation Settings'),
            'config_path' => 'config/automations.php',
            // The form is drawn from the same definition the validation and the
            // boot-time override read, so a field cannot exist on screen
            // without a rule behind it, or the other way round.
            'groups' => Settings::groups(),
            'values' => $values,
            'updateUrl' => cp_route('statamic-automations.api.settings.update'),
            'canEdit' => $this->userCan('manage automation settings'),
            // A detection, not a setting: whether a sister addon is installed
            // is decided by composer, and a control here would be a switch that
            // does nothing. Shown, never editable.
            'integrations' => $detector->snapshot(),
        ]);
    }

    protected function userCan(string $permission): bool
    {
        $user = auth()->user();

        return $user !== null && method_exists($user, 'can') && (bool) $user->can($permission);
    }
}

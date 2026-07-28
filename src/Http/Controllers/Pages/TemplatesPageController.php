<?php

namespace Goldnead\StatamicAutomations\Http\Controllers\Pages;

use Goldnead\StatamicAutomations\Http\Controllers\Controller;
use Goldnead\StatamicAutomations\Integrations\IntegrationDetector;
use Goldnead\StatamicAutomations\Templates\TemplateRegistry;
use Inertia\Inertia;

class TemplatesPageController extends Controller
{
    public function index(TemplateRegistry $registry, IntegrationDetector $detector)
    {
        $this->authorizeAction('view automations');

        $integrations = $detector->snapshot();
        $templates = collect($registry->all())->map(function (array $tpl) use ($integrations) {
            $missing = collect($tpl['requires'] ?? [])
                ->reject(fn ($req) => (bool) ($integrations[$req] ?? false))
                ->values()
                ->all();

            return [
                'handle' => $tpl['handle'],
                'name' => $tpl['name'],
                'description' => $tpl['description'],
                'requires' => $tpl['requires'] ?? [],
                'missing_integrations' => $missing,
                'available' => empty($missing),
                'node_count' => count($tpl['nodes'] ?? []),
                'install_url' => cp_route('statamic-automations.api.templates.install', $tpl['handle']),
            ];
        })->values();

        return Inertia::render('statamic-automations::Templates/Index', [
            'title' => __('Automation templates'),
            'templates' => $templates,
            'canCreate' => $this->userCan('create automations'),
        ]);
    }

    protected function userCan(string $permission): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }
        if (method_exists($user, 'can')) {
            return (bool) $user->can($permission);
        }

        return true;
    }
}

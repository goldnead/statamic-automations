<?php

namespace Goldnead\StatamicAutomations\Http\Controllers;

use Goldnead\StatamicAutomations\Integrations\IntegrationDetector;
use Goldnead\StatamicAutomations\Integrations\LeadHub\LeadHubAdapter;
use Goldnead\StatamicAutomations\Integrations\WebhookManager\WebhookManagerAdapter;
use Goldnead\StatamicAutomations\Registries\ActionRegistry;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Registries\TriggerRegistry;
use Illuminate\Http\JsonResponse;

class NodesController extends Controller
{
    /**
     * Return all registered nodes (triggers / logic / actions) with
     * full schema metadata. The Vue Flow canvas uses this to render
     * the Node Library.
     */
    public function index(NodeRegistry $registry, IntegrationDetector $detector): JsonResponse
    {
        $this->authorizeAction('view automations');

        return response()->json([
            'data' => [
                'triggers' => $registry->byKind('trigger'),
                'logic' => $registry->byKind('logic'),
                'actions' => $registry->byKind('action'),
            ],
            'meta' => [
                'integrations' => $detector->snapshot(),
            ],
        ]);
    }

    public function triggers(TriggerRegistry $triggers, NodeRegistry $registry): JsonResponse
    {
        $this->authorizeAction('view automations');

        $data = array_map(
            fn (string $handle) => $registry->describe($handle),
            array_keys($triggers->all()),
        );

        return response()->json(['data' => $data]);
    }

    public function actions(ActionRegistry $actions, NodeRegistry $registry): JsonResponse
    {
        $this->authorizeAction('view automations');

        $data = array_map(
            fn (string $handle) => $registry->describe($handle),
            array_keys($actions->all()),
        );

        return response()->json(['data' => $data]);
    }

    public function describe(string $handle, NodeRegistry $registry): JsonResponse
    {
        $this->authorizeAction('view automations');

        if (! $registry->has($handle)) {
            return response()->json(['message' => "Node '{$handle}' not found."], 404);
        }

        return response()->json(['data' => $registry->describe($handle)]);
    }

    /**
     * Returns the output schema for a particular trigger handle so the
     * data picker can present available variables.
     */
    public function contextSchema(string $handle, NodeRegistry $registry): JsonResponse
    {
        $this->authorizeAction('view automations');

        $description = $registry->describe($handle);

        if (empty($description) || ($description['kind'] ?? null) !== 'trigger') {
            return response()->json(['message' => "Trigger '{$handle}' not found."], 404);
        }

        return response()->json([
            'data' => $description['output_schema'] ?? [],
        ]);
    }

    /**
     * Resolve dynamic option sources (e.g. leadhub.statuses) to a flat
     * list of options for the config form.
     */
    public function options(string $source, LeadHubAdapter $leadHub, WebhookManagerAdapter $webhookManager): JsonResponse
    {
        $this->authorizeAction('view automations');

        $options = match ($source) {
            'leadhub.statuses' => $leadHub->statuses(),
            'leadhub.tags' => $leadHub->tags(),
            'webhook_manager.destinations' => $webhookManager->destinations(),
            'statamic.forms' => $this->statamicForms(),
            'statamic.collections' => $this->statamicCollections(),
            'statamic.sites' => $this->statamicSites(),
            'email_templates.templates' => $this->emailTemplates(),
            default => [],
        };

        return response()->json(['data' => $options]);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    protected function statamicForms(): array
    {
        if (! class_exists(\Statamic\Facades\Form::class)) {
            return [];
        }

        try {
            $forms = \Statamic\Facades\Form::all();

            return collect($forms)
                ->map(fn ($form) => [
                    'value' => method_exists($form, 'handle') ? $form->handle() : (string) $form,
                    'label' => method_exists($form, 'title') ? $form->title() : (string) $form,
                ])
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    protected function statamicCollections(): array
    {
        if (! class_exists(\Statamic\Facades\Collection::class)) {
            return [];
        }

        try {
            $collections = \Statamic\Facades\Collection::all();

            return collect($collections)
                ->map(fn ($c) => [
                    'value' => method_exists($c, 'handle') ? $c->handle() : (string) $c,
                    'label' => method_exists($c, 'title') ? $c->title() : (string) $c,
                ])
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Slug/title options from the managed `email_templates` collection owned by
     * the optional email-templates addon. Guarded by the sibling's public
     * facade so the source resolves to an empty list (and the picker stays
     * hidden) when the addon is not installed — no hard dependency.
     *
     * @return array<int, array{value: string, label: string}>
     */
    protected function emailTemplates(): array
    {
        if (! class_exists(\Goldnead\EmailTemplates\Facades\EmailTemplates::class)
            || ! class_exists(\Statamic\Facades\Entry::class)) {
            return [];
        }

        try {
            return collect(\Statamic\Facades\Entry::query()->where('collection', 'et_templates')->get())
                ->map(fn ($entry) => [
                    'value' => method_exists($entry, 'slug') ? (string) $entry->slug() : '',
                    'label' => method_exists($entry, 'value')
                        ? (string) ($entry->value('title') ?? $entry->slug())
                        : (string) $entry,
                ])
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    protected function statamicSites(): array
    {
        if (! class_exists(\Statamic\Facades\Site::class)) {
            return [];
        }

        try {
            $sites = \Statamic\Facades\Site::all();

            return collect($sites)
                ->map(fn ($s) => [
                    'value' => method_exists($s, 'handle') ? $s->handle() : (string) $s,
                    'label' => method_exists($s, 'name') ? $s->name() : (string) $s,
                ])
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}

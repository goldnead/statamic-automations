<?php

namespace Goldnead\StatamicAutomations\Http\Controllers;

use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Integrations\IntegrationDetector;
use Goldnead\StatamicAutomations\Integrations\LeadHub\LeadHubAdapter;
use Goldnead\StatamicAutomations\Integrations\WebhookManager\WebhookManagerAdapter;
use Goldnead\StatamicAutomations\Registries\ActionRegistry;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Registries\TriggerRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
     *
     * Statamic-native sources accept both the bare handle (`collections`)
     * and the historical `statamic.`-prefixed spelling (`statamic.collections`)
     * so existing node schemas (which already reference the prefixed form)
     * keep working unchanged while the frontend is free to request either.
     */
    public function options(
        string $source,
        Request $request,
        LeadHubAdapter $leadHub,
        WebhookManagerAdapter $webhookManager,
        AutomationRepository $automations,
    ): JsonResponse {
        $this->authorizeAction('view automations');

        $options = match ($source) {
            'leadhub.statuses' => $leadHub->statuses(),
            'leadhub.tags' => $leadHub->tags(),
            'webhook_manager.destinations', 'webhooks' => $webhookManager->destinations(),
            'statamic.forms', 'forms' => $this->statamicForms(),
            'statamic.collections', 'collections' => $this->statamicCollections(),
            'statamic.sites', 'sites' => $this->statamicSites(),
            'statamic.entries', 'entries' => $this->statamicEntries($request),
            'statamic.taxonomies', 'taxonomies' => $this->statamicTaxonomies(),
            'statamic.terms', 'terms' => $this->statamicTerms($request),
            'statamic.users', 'users' => $this->statamicUsers(),
            'statamic.roles', 'roles' => $this->statamicRoles(),
            'statamic.blueprints', 'blueprints' => $this->statamicBlueprints($request),
            'statamic.assets', 'assets' => $this->statamicAssets($request),
            'statamic.asset_containers', 'asset_containers' => $this->statamicAssetContainers(),
            'statamic.globals', 'globals' => $this->statamicGlobals(),
            'automations' => $this->automationsList($automations),
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

    /**
     * Entries in a given collection (`?collection=`). No/unknown collection
     * -> empty list, never an error.
     *
     * @return array<int, array{value: string, label: string}>
     */
    protected function statamicEntries(Request $request): array
    {
        $collection = $request->query('collection');

        if (! is_string($collection) || $collection === '' || ! class_exists(\Statamic\Facades\Entry::class)) {
            return [];
        }

        try {
            return \Statamic\Facades\Entry::query()
                ->where('collection', $collection)
                ->get()
                ->map(fn ($entry) => [
                    'value' => method_exists($entry, 'id') ? (string) $entry->id() : (string) $entry,
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
    protected function statamicTaxonomies(): array
    {
        if (! class_exists(\Statamic\Facades\Taxonomy::class)) {
            return [];
        }

        try {
            return collect(\Statamic\Facades\Taxonomy::all())
                ->map(fn ($t) => [
                    'value' => method_exists($t, 'handle') ? $t->handle() : (string) $t,
                    'label' => method_exists($t, 'title') ? $t->title() : (string) $t,
                ])
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Terms in a given taxonomy (`?taxonomy=`). No/unknown taxonomy ->
     * empty list, never an error.
     *
     * @return array<int, array{value: string, label: string}>
     */
    protected function statamicTerms(Request $request): array
    {
        $taxonomy = $request->query('taxonomy');

        if (! is_string($taxonomy) || $taxonomy === '' || ! class_exists(\Statamic\Facades\Term::class)) {
            return [];
        }

        try {
            return \Statamic\Facades\Term::whereTaxonomy($taxonomy)
                ->map(fn ($term) => [
                    'value' => method_exists($term, 'id') ? (string) $term->id() : (string) $term,
                    'label' => method_exists($term, 'title') ? (string) $term->title() : (string) $term,
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
    protected function statamicUsers(): array
    {
        if (! class_exists(\Statamic\Facades\User::class)) {
            return [];
        }

        try {
            return collect(\Statamic\Facades\User::all())
                ->map(fn ($user) => [
                    'value' => method_exists($user, 'id') ? (string) $user->id() : (string) $user,
                    'label' => method_exists($user, 'email')
                        ? (string) ($user->email() ?: (method_exists($user, 'name') ? $user->name() : $user->id()))
                        : (string) $user,
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
    protected function statamicRoles(): array
    {
        if (! class_exists(\Statamic\Facades\Role::class)) {
            return [];
        }

        try {
            return collect(\Statamic\Facades\Role::all())
                ->map(fn ($role) => [
                    'value' => method_exists($role, 'handle') ? $role->handle() : (string) $role,
                    'label' => method_exists($role, 'title') ? $role->title() : (string) $role,
                ])
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Blueprints, optionally scoped to a collection (`?collection=`). With
     * no collection param, returns all collection-namespace blueprints.
     *
     * @return array<int, array{value: string, label: string}>
     */
    protected function statamicBlueprints(Request $request): array
    {
        $collectionHandle = $request->query('collection');

        try {
            if (is_string($collectionHandle) && $collectionHandle !== '') {
                if (! class_exists(\Statamic\Facades\Collection::class)) {
                    return [];
                }

                $collection = \Statamic\Facades\Collection::findByHandle($collectionHandle);

                if ($collection === null) {
                    return [];
                }

                $blueprints = $collection->entryBlueprints();
            } else {
                if (! class_exists(\Statamic\Facades\Blueprint::class)) {
                    return [];
                }

                $blueprints = \Statamic\Facades\Blueprint::in('collections');
            }

            return collect($blueprints)
                ->map(fn ($blueprint) => [
                    'value' => method_exists($blueprint, 'handle') ? $blueprint->handle() : (string) $blueprint,
                    'label' => method_exists($blueprint, 'title') ? $blueprint->title() : (string) $blueprint,
                ])
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Assets in a given container (`?container=`). No/unknown container ->
     * empty list, never an error.
     *
     * @return array<int, array{value: string, label: string}>
     */
    protected function statamicAssets(Request $request): array
    {
        $container = $request->query('container');

        if (! is_string($container) || $container === '' || ! class_exists(\Statamic\Facades\Asset::class)) {
            return [];
        }

        try {
            return \Statamic\Facades\Asset::whereContainer($container)
                ->map(fn ($asset) => [
                    'value' => method_exists($asset, 'id') ? (string) $asset->id() : (string) $asset,
                    'label' => method_exists($asset, 'basename') ? (string) $asset->basename() : (string) $asset,
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
    protected function statamicAssetContainers(): array
    {
        if (! class_exists(\Statamic\Facades\AssetContainer::class)) {
            return [];
        }

        try {
            return collect(\Statamic\Facades\AssetContainer::all())
                ->map(fn ($container) => [
                    'value' => method_exists($container, 'handle') ? $container->handle() : (string) $container,
                    'label' => method_exists($container, 'title') ? $container->title() : (string) $container,
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
    protected function statamicGlobals(): array
    {
        if (! class_exists(\Statamic\Facades\GlobalSet::class)) {
            return [];
        }

        try {
            return collect(\Statamic\Facades\GlobalSet::all())
                ->map(fn ($global) => [
                    'value' => method_exists($global, 'handle') ? $global->handle() : (string) $global,
                    'label' => method_exists($global, 'title') ? $global->title() : (string) $global,
                ])
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Other automations defined in this addon — used by the sub-automation
     * / trigger-another-automation actions to pick a target.
     *
     * @return array<int, array{value: string, label: string}>
     */
    protected function automationsList(AutomationRepository $automations): array
    {
        try {
            return $automations->all()
                ->map(fn ($automation) => [
                    'value' => (string) ($automation->handle ?? $automation->id),
                    'label' => (string) ($automation->name ?? $automation->handle),
                ])
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}

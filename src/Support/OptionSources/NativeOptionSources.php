<?php

namespace Goldnead\StatamicAutomations\Support\OptionSources;

use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Integrations\LeadHub\LeadHubAdapter;
use Goldnead\StatamicAutomations\Integrations\WebhookManager\WebhookManagerAdapter;
use Illuminate\Http\Request;

/**
 * Resolvers for the addon's built-in `options_source` handles.
 *
 * These used to live as a hard-coded `match()` inside NodesController. They
 * are now registered into the {@see \Goldnead\StatamicAutomations\Registries\OptionSourceRegistry}
 * at boot — i.e. the built-ins go through the very same public surface a third
 * party uses (`Automations::registerOptionSource()`). Every method is
 * defensive: a missing facade or any error yields an empty list, never a fatal.
 */
class NativeOptionSources
{
    public function __construct(
        protected LeadHubAdapter $leadHub,
        protected WebhookManagerAdapter $webhookManager,
        protected AutomationRepository $automations,
    ) {
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function forms(Request $request): array
    {
        if (! class_exists(\Statamic\Facades\Form::class)) {
            return [];
        }

        try {
            return collect(\Statamic\Facades\Form::all())
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
    public function collections(Request $request): array
    {
        if (! class_exists(\Statamic\Facades\Collection::class)) {
            return [];
        }

        try {
            return collect(\Statamic\Facades\Collection::all())
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
     * @return array<int, array{value: string, label: string}>
     */
    public function sites(Request $request): array
    {
        if (! class_exists(\Statamic\Facades\Site::class)) {
            return [];
        }

        try {
            return collect(\Statamic\Facades\Site::all())
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
     * @return array<int, array{value: string, label: string}>
     */
    public function entries(Request $request): array
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
    public function taxonomies(Request $request): array
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
     * @return array<int, array{value: string, label: string}>
     */
    public function terms(Request $request): array
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
    public function users(Request $request): array
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
    public function roles(Request $request): array
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
     * User groups — backs the `add_user_to_group` action picker.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function userGroups(Request $request): array
    {
        if (! class_exists(\Statamic\Facades\UserGroup::class)) {
            return [];
        }

        try {
            return collect(\Statamic\Facades\UserGroup::all())
                ->map(fn ($group) => [
                    'value' => method_exists($group, 'handle') ? $group->handle() : (string) $group,
                    'label' => method_exists($group, 'title') ? $group->title() : (string) $group,
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
    public function blueprints(Request $request): array
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
     * @return array<int, array{value: string, label: string}>
     */
    public function assets(Request $request): array
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
    public function assetContainers(Request $request): array
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
    public function globals(Request $request): array
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
     * actions to pick a target.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function automations(Request $request): array
    {
        try {
            return $this->automations->all()
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

    /**
     * Slug/title options from the managed `et_templates` collection owned by
     * the optional email-templates addon. Guarded so it resolves to an empty
     * list when that addon is not installed — no hard dependency.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function emailTemplates(Request $request): array
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
    public function leadHubStatuses(Request $request): array
    {
        return $this->leadHub->statuses();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function leadHubTags(Request $request): array
    {
        return $this->leadHub->tags();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function webhookDestinations(Request $request): array
    {
        return $this->webhookManager->destinations();
    }
}

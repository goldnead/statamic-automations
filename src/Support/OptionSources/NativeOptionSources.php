<?php

namespace Goldnead\StatamicAutomations\Support\OptionSources;

use Goldnead\EmailTemplates\Facades\EmailTemplates;
use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Integrations\LeadHub\LeadHubAdapter;
use Goldnead\StatamicAutomations\Integrations\WebhookManager\WebhookManagerAdapter;
use Goldnead\StatamicAutomations\Registries\OptionSourceRegistry;
use Illuminate\Http\Request;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Form;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;

/**
 * Resolvers for the addon's built-in `options_source` handles.
 *
 * These used to live as a hard-coded `match()` inside NodesController. They
 * are now registered into the {@see OptionSourceRegistry}
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
    ) {}

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function forms(Request $request): array
    {
        if (! class_exists(Form::class)) {
            return [];
        }

        try {
            return collect(Form::all())
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
        if (! class_exists(Collection::class)) {
            return [];
        }

        try {
            return collect(Collection::all())
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
        if (! class_exists(Site::class)) {
            return [];
        }

        try {
            return collect(Site::all())
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

        if (! is_string($collection) || $collection === '' || ! class_exists(Entry::class)) {
            return [];
        }

        try {
            return Entry::query()
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
        if (! class_exists(Taxonomy::class)) {
            return [];
        }

        try {
            return collect(Taxonomy::all())
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

        if (! is_string($taxonomy) || $taxonomy === '' || ! class_exists(Term::class)) {
            return [];
        }

        try {
            return Term::whereTaxonomy($taxonomy)
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
        if (! class_exists(User::class)) {
            return [];
        }

        try {
            return collect(User::all())
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
        if (! class_exists(Role::class)) {
            return [];
        }

        try {
            return collect(Role::all())
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
        if (! class_exists(UserGroup::class)) {
            return [];
        }

        try {
            return collect(UserGroup::all())
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
                if (! class_exists(Collection::class)) {
                    return [];
                }

                $collection = Collection::findByHandle($collectionHandle);

                if ($collection === null) {
                    return [];
                }

                $blueprints = $collection->entryBlueprints();
            } else {
                if (! class_exists(Blueprint::class)) {
                    return [];
                }

                $blueprints = Blueprint::in('collections');
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

        if (! is_string($container) || $container === '' || ! class_exists(Asset::class)) {
            return [];
        }

        try {
            return Asset::whereContainer($container)
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
        if (! class_exists(AssetContainer::class)) {
            return [];
        }

        try {
            return collect(AssetContainer::all())
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
        if (! class_exists(GlobalSet::class)) {
            return [];
        }

        try {
            return collect(GlobalSet::all())
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
        if (! class_exists(EmailTemplates::class)
            || ! class_exists(Entry::class)) {
            return [];
        }

        try {
            return collect(Entry::query()->where('collection', 'et_templates')->get())
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

    /**
     * What may be bought, for the product filter on the payment triggers.
     *
     * Read from the payments catalogue rather than from the products table:
     * the catalogue is the one surface that already unifies the config file,
     * `statamic-products` and `statamic-offers`, so a picker built on the table
     * alone would show three of six and then refuse the save. Same source the
     * offer form's own product select uses.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function paymentProducts(Request $request): array
    {
        $catalogue = 'Goldnead\\StatamicPayments\\Support\\Catalogue';

        if (! class_exists($catalogue)) {
            return [];
        }

        try {
            return collect(app($catalogue)->all())
                ->map(fn ($product, $handle) => [
                    'value' => (string) $handle,
                    'label' => (string) (is_array($product) ? ($product['name'] ?? $handle) : $handle),
                ])
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The offers of the optional offers addon, for the offer filter on the
     * payment triggers and the declined-offer trigger.
     *
     * The value is the bare offer handle (`kurs`), not the product handle
     * (`offer:kurs`): the filter matches every way of buying the offer, and the
     * prefix is a setting of the offers addon that this list should not freeze.
     * Narrowed to the reader's brand the way the offers addon's own pickers are.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function offers(Request $request): array
    {
        return collect($this->offerRows())
            ->map(fn ($offer) => [
                'value' => (string) $offer->handle,
                'label' => (string) ($offer->name ?: $offer->handle),
            ])
            ->values()
            ->all();
    }

    /**
     * Every pricing option of every offer, as the handle a purchase of it
     * carries: `offer:kurs:raten3`, labelled "3 Raten · Kurs".
     *
     * One flat list rather than a second select that depends on the first:
     * the editor's fields do not depend on each other, and a full handle is
     * unambiguous on its own. Offers without options do not appear here; for
     * those the offer filter is the whole answer.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function offerPricingOptions(Request $request): array
    {
        $prefix = (string) config('statamic-offers.handle_prefix', 'offer:');
        $prefix = $prefix === '' ? 'offer:' : $prefix;

        return collect($this->offerRows())
            ->flatMap(function ($offer) use ($prefix) {
                $options = method_exists($offer, 'pricingOptions') ? $offer->pricingOptions() : [];
                $name = (string) ($offer->name ?: $offer->handle);

                // The option first: the config column is narrow, a selected
                // value is cut at the right, and the option is what tells two
                // entries of the same offer apart.
                return collect($options)->map(fn (array $option) => [
                    'value' => $prefix.$offer->handle.':'.$option['key'],
                    'label' => (($option['label'] ?? '') !== '' ? $option['label'] : $option['key']).' · '.$name,
                ]);
            })
            ->values()
            ->all();
    }

    /**
     * @return iterable<int, object>
     */
    protected function offerRows(): iterable
    {
        $model = 'Goldnead\\StatamicOffers\\Models\\Offer';

        if (! class_exists($model)) {
            return [];
        }

        try {
            $query = $model::query();

            // An offers release from before brands has no such scope; the
            // list is then simply every offer, as it was on that release.
            try {
                $query->forBrand();
            } catch (\BadMethodCallException) {
                $query = $model::query();
            }

            return $query->orderBy('name')->get();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The courses of the optional courses addon, for the course filter on its
     * triggers. The value is the entry id, which the events carry and which
     * survives a changed slug; the filter accepts a slug as well.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function courses(Request $request): array
    {
        if (! class_exists('Goldnead\\Courses\\ServiceProvider')) {
            return [];
        }

        // courses 0ec0f86 and later: its own list, narrowed to the reader's
        // brand (plus courses without one). Older releases have no brand on a
        // course; the collection read below is then the whole answer.
        $progress = 'Goldnead\\Courses\\CourseProgress';

        if (class_exists($progress)) {
            try {
                $service = app($progress);

                if (method_exists($service, 'courses')) {
                    $brands = app('brand-context');
                    $brandId = $brands->multiBrandEnabled() && $brands->hasCurrent() ? $brands->currentId() : null;

                    return collect($service->courses($brandId))
                        ->filter(fn ($course) => is_array($course) && isset($course['id']))
                        ->map(fn (array $course) => [
                            'value' => (string) $course['id'],
                            'label' => (string) (($course['title'] ?? '') !== '' ? $course['title'] : ($course['slug'] ?? $course['id'])),
                        ])
                        ->sortBy('label')
                        ->values()
                        ->all();
                }
            } catch (\Throwable) {
                // Fall through to the collection.
            }
        }

        try {
            $collection = (string) config('courses.collections.courses', 'courses');

            return Entry::whereCollection($collection)
                ->map(fn ($entry) => [
                    'value' => (string) $entry->id(),
                    'label' => (string) ($entry->value('title') ?? $entry->slug()),
                ])
                ->sortBy('label')
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The funnels of the optional funnels addon, for the funnel filter on its
     * triggers. Referenced by name so this class stays loadable without it.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function funnels(Request $request): array
    {
        $funnel = 'Goldnead\\StatamicFunnels\\Models\\Funnel';

        if (! class_exists($funnel)) {
            return [];
        }

        try {
            return collect($funnel::query()->orderBy('title')->get())
                ->map(fn ($item) => [
                    'value' => (string) $item->handle,
                    'label' => (string) ($item->title ?: $item->handle),
                ])
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}

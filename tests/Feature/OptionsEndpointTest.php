<?php

/**
 * Coverage for `GET /cp/automations/api/options/{source}` — the endpoint
 * the node config forms use to populate dynamic <select> pickers (Task 2.2,
 * frontend, will start fetching these). NodesController::options() already
 * serves a handful of sources under a `statamic.`-prefixed convention
 * (`statamic.collections`, `statamic.forms`, `statamic.sites`); this test
 * locks in the expanded set of entity sources plus both the bare and the
 * `statamic.`-prefixed spelling for each, without breaking the existing ones.
 */

use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry;
use Statamic\Facades\Form;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\User;

beforeEach(function (): void {
    $this->actingAsSuperUser();
});

function seedOptionsFixtures(): void
{
    $collection = CollectionFacade::make('blog')->title('Blog');
    $collection->save();

    Entry::make()
        ->collection('blog')
        ->locale('default')
        ->slug('hello-world')
        ->data(['title' => 'Hello World'])
        ->save();

    $taxonomy = Taxonomy::make('topics')->title('Topics');
    $taxonomy->save();

    Term::make()
        ->taxonomy('topics')
        ->slug('music')
        ->in('default')
        ->data(['title' => 'Music'])
        ->save();

    Form::make('contact')->title('Contact')->save();

    $role = Role::make('editor')->title('Editor');
    Role::save($role);

    $user = User::make()->email('jane@example.com')->data(['name' => 'Jane Doe']);
    $user->save();

    AssetContainer::make('assets')->title('Assets')->disk('local')->save();
}

it('lists collections', function (): void {
    seedOptionsFixtures();

    foreach (['collections', 'statamic.collections'] as $source) {
        $response = $this->getJson("/cp/automations/api/options/{$source}")->assertOk();
        $data = $response->json('data');

        expect($data)->toBeArray()->not->toBeEmpty();
        expect(collect($data)->firstWhere('value', 'blog'))
            ->toBe(['value' => 'blog', 'label' => 'Blog']);
    }
});

it('lists entries for a given collection and empty for an unknown one', function (): void {
    seedOptionsFixtures();

    foreach (['entries', 'statamic.entries'] as $source) {
        $response = $this->getJson("/cp/automations/api/options/{$source}?collection=blog")->assertOk();
        $data = $response->json('data');

        expect($data)->toBeArray()->not->toBeEmpty();
        expect($data[0])->toHaveKeys(['value', 'label']);
        // Statamic's flat-file content lives in the testbench skeleton and is
        // NOT rolled back between tests, so the `blog` collection also holds
        // entries other tests wrote. Assert the seeded entry is present rather
        // than assuming it sorts first — the endpoint promises no order.
        expect(collect($data)->pluck('label'))->toContain('Hello World');

        $this->getJson("/cp/automations/api/options/{$source}?collection=does-not-exist")
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    // No collection param at all -> empty, never an error.
    $this->getJson('/cp/automations/api/options/entries')
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('lists taxonomies', function (): void {
    seedOptionsFixtures();

    foreach (['taxonomies', 'statamic.taxonomies'] as $source) {
        $data = $this->getJson("/cp/automations/api/options/{$source}")->assertOk()->json('data');

        expect(collect($data)->firstWhere('value', 'topics'))
            ->toBe(['value' => 'topics', 'label' => 'Topics']);
    }
});

it('lists terms for a given taxonomy and empty for an unknown one', function (): void {
    seedOptionsFixtures();

    foreach (['terms', 'statamic.terms'] as $source) {
        $data = $this->getJson("/cp/automations/api/options/{$source}?taxonomy=topics")->assertOk()->json('data');

        expect($data)->toBeArray()->not->toBeEmpty();
        expect($data[0]['label'])->toBe('Music');

        $this->getJson("/cp/automations/api/options/{$source}?taxonomy=does-not-exist")
            ->assertOk()
            ->assertJsonPath('data', []);
    }
});

it('lists forms', function (): void {
    seedOptionsFixtures();

    foreach (['forms', 'statamic.forms'] as $source) {
        $data = $this->getJson("/cp/automations/api/options/{$source}")->assertOk()->json('data');

        expect(collect($data)->firstWhere('value', 'contact'))
            ->toBe(['value' => 'contact', 'label' => 'Contact']);
    }
});

it('lists users', function (): void {
    seedOptionsFixtures();

    foreach (['users', 'statamic.users'] as $source) {
        $data = $this->getJson("/cp/automations/api/options/{$source}")->assertOk()->json('data');

        expect($data)->toBeArray()->not->toBeEmpty();
        expect(collect($data)->pluck('label'))->toContain('jane@example.com');
    }
});

it('lists roles', function (): void {
    seedOptionsFixtures();

    foreach (['roles', 'statamic.roles'] as $source) {
        $data = $this->getJson("/cp/automations/api/options/{$source}")->assertOk()->json('data');

        expect(collect($data)->firstWhere('value', 'editor'))
            ->toBe(['value' => 'editor', 'label' => 'Editor']);
    }
});

it('lists blueprints, optionally scoped to a collection', function (): void {
    seedOptionsFixtures();

    foreach (['blueprints', 'statamic.blueprints'] as $source) {
        $all = $this->getJson("/cp/automations/api/options/{$source}")->assertOk()->json('data');
        expect($all)->toBeArray();

        $scoped = $this->getJson("/cp/automations/api/options/{$source}?collection=blog")->assertOk()->json('data');
        expect($scoped)->toBeArray();
    }
});

it('lists asset containers', function (): void {
    seedOptionsFixtures();

    foreach (['asset_containers', 'statamic.asset_containers'] as $source) {
        $data = $this->getJson("/cp/automations/api/options/{$source}")->assertOk()->json('data');

        expect(collect($data)->firstWhere('value', 'assets'))
            ->toBe(['value' => 'assets', 'label' => 'Assets']);
    }
});

it('lists assets for a given container and empty for an unknown one', function (): void {
    seedOptionsFixtures();

    foreach (['assets', 'statamic.assets'] as $source) {
        $this->getJson("/cp/automations/api/options/{$source}?container=assets")
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->getJson("/cp/automations/api/options/{$source}?container=does-not-exist")
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    $this->getJson('/cp/automations/api/options/assets')
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('lists sites', function (): void {
    seedOptionsFixtures();

    foreach (['sites', 'statamic.sites'] as $source) {
        $data = $this->getJson("/cp/automations/api/options/{$source}")->assertOk()->json('data');

        expect(collect($data)->pluck('value'))->toContain(Site::default()->handle());
    }
});

it('lists globals', function (): void {
    seedOptionsFixtures();

    foreach (['globals', 'statamic.globals'] as $source) {
        $data = $this->getJson("/cp/automations/api/options/{$source}")->assertOk()->json('data');

        expect($data)->toBeArray();
    }
});

it('lists other automations', function (): void {
    seedOptionsFixtures();

    \Goldnead\StatamicAutomations\Models\Automation::create(['name' => 'Inquiry', 'handle' => 'inquiry']);
    \Goldnead\StatamicAutomations\Models\Automation::create(['name' => 'Welcome', 'handle' => 'welcome']);

    $data = $this->getJson('/cp/automations/api/options/automations')->assertOk()->json('data');

    expect(collect($data)->pluck('label'))->toContain('Inquiry', 'Welcome');
});

it('returns an empty list for webhooks when the webhook-manager addon is absent', function (): void {
    seedOptionsFixtures();

    $this->getJson('/cp/automations/api/options/webhooks')
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('returns an empty array (never an error) for an entirely unknown source', function (): void {
    $this->getJson('/cp/automations/api/options/not-a-real-source')
        ->assertOk()
        ->assertJsonPath('data', []);
});

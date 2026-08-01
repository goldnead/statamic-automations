<?php

namespace Goldnead\StatamicAutomations\Tests;

use Goldnead\StatamicAutomations\ServiceProvider;
use Goldnead\StatamicAutomations\Tests\Feature\RouteParameterCollisionTest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\User;
use Statamic\Providers\StatamicServiceProvider;
use Statamic\Testing\AddonTestCase;

/**
 * Booted through Statamic's own AddonTestCase rather than a hand-rolled
 * Testbench subclass. Core wires the provider order, the addon manifest, the
 * Inertia page paths and the Stache store locations there, and keeps doing so
 * across majors; a local copy of that wiring drifts the moment Statamic changes
 * any of it. Everything below is what this addon needs *in addition*.
 */
abstract class TestCase extends AddonTestCase
{
    use RefreshDatabase;

    protected string $addonServiceProvider = ServiceProvider::class;

    /**
     * Where this run's Stache content lives.
     *
     * Core's `PreventsSavingStacheItemsToDisk` is not used: it routes every
     * save into a dev-null directory, and a third of this suite creates a
     * collection, an entry or a user and then reads it back through the option
     * endpoints. What that trait is actually there to prevent — test content
     * landing in the repository — is handled by moving the stores into a
     * throwaway directory instead, which keeps both properties.
     */
    protected ?string $stacheRoot = null;

    protected function setUp(): void
    {
        parent::setUp();

        // AddonTestCase mocks the Nav facade but only declares `build` and
        // `clearCachedUrls`. This addon's bootAddon() calls `Nav::extend`, which
        // the partial mock then rejects outright. Nothing in this suite asserts
        // on the nav, so accepting the call is enough.
        Nav::shouldReceive('extend');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Statamic's AddonServiceProvider runs bootAddon() inside a
        // Statamic::booted(...) callback. Under the old hand-rolled Testbench
        // bed those callbacks never fired and this had to force the call. With
        // AddonTestCase the addon manifest is wired up properly and Statamic
        // boots the addon itself — forcing it a second time registered every
        // event listener twice, which showed up as one entry save producing two
        // automation runs. Left as an assertion instead of a call: if a future
        // Statamic stops booting the addon here, the whole feature suite would
        // otherwise fail in a hundred confusing ways.
        expect(app('router')->getRoutes()->getByName('statamic.cp.statamic-automations.automations.index'))
            ->not->toBeNull('The addon provider did not boot; bootAddon() never ran.');
    }

    /**
     * Core's list plus brand-context, which every model's HasBrand trait needs
     * and which must boot before this addon's provider.
     */
    protected function getPackageProviders($app): array
    {
        $providers = parent::getPackageProviders($app);

        array_splice(
            $providers,
            array_search(StatamicServiceProvider::class, $providers, true) + 1,
            0,
            [\Goldnead\BrandContext\ServiceProvider::class]
        );

        return $providers;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->stacheRoot !== null && is_dir($this->stacheRoot)) {
            $this->deleteDirectory($this->stacheRoot);
            $this->stacheRoot = null;
        }
    }

    private function deleteDirectory(string $path): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }

    /**
     * AddonTestCase points every Stache store at `tests/__fixtures__/content`
     * and does it *after* `defineEnvironment()` has run, so the override has to
     * live here to win. Writing there would leave test content in the working
     * tree, so each run gets a throwaway directory under the system temp dir
     * and tearDown removes it.
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $this->stacheRoot = sys_get_temp_dir().'/statamic-automations-stache-'.bin2hex(random_bytes(6));

        foreach ((array) $app['config']->get('statamic.stache.stores', []) as $handle => $store) {
            if (! isset($store['directory'])) {
                continue;
            }

            $app['config']->set(
                'statamic.stache.stores.'.$handle.'.directory',
                $this->stacheRoot.'/'.$handle
            );
        }
    }

    protected function defineEnvironment($app): void
    {
        // Several suites create more than one user (permission checks compare a
        // super user against a regular one), which the Solo edition refuses.
        $app['config']->set('statamic.editions.pro', true);

        // Stable APP_KEY so Crypt-based casts (EncryptedJson) work in tests.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // The suite runs on SQLite by default — fast, no service required.
        // Hosts run MySQL, and the two databases disagree about things that
        // matter here (typed datetimes, fractional-second precision, what
        // `->change()` actually does). Set AUTOMATIONS_TEST_DB=mysql plus the
        // usual DB_* variables to run the same suite against a real server:
        //
        //   AUTOMATIONS_TEST_DB=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
        //   DB_DATABASE=automations_test DB_USERNAME=root DB_PASSWORD=secret \
        //   vendor/bin/pest
        $driver = env('AUTOMATIONS_TEST_DB', 'sqlite');

        $app['config']->set('database.default', $driver);
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        if ($driver === 'mysql') {
            $app['config']->set('database.connections.mysql', array_merge(
                $app['config']->get('database.connections.mysql', []),
                [
                    'driver' => 'mysql',
                    'host' => env('DB_HOST', '127.0.0.1'),
                    'port' => env('DB_PORT', '3306'),
                    'database' => env('DB_DATABASE', 'automations_test'),
                    'username' => env('DB_USERNAME', 'root'),
                    'password' => env('DB_PASSWORD', ''),
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                    'prefix' => '',
                ],
            ));
        }

        // Use Statamic's flat-file user repository so feature tests can create
        // real CP super users and hit the authenticated CP routes.
        $app['config']->set('statamic.users.repository', 'file');

        // Pro gating is opt-in for hosts but interferes with tests that
        // intentionally register custom actions/triggers. Off by default.
        $app['config']->set('automations.features.custom_actions_requires_pro', false);
    }

    /**
     * Statamic registers addon CP routes inside Statamic::booted callbacks
     * that orchestra/testbench doesn't fire. For feature tests we mount them
     * ourselves under the `statamic.cp.` name prefix and `/cp` URL prefix that
     * production uses, so `cp_route('statamic-automations.*')` resolves exactly
     * as it does in a real Control Panel.
     */
    protected function defineRoutes($router): void
    {
        // The addon's own CP routes are NOT mounted here any more. Statamic
        // mounts them itself from the provider's $routes property now that
        // AddonTestCase gives the addon a real manifest; doing it here as well
        // registered every route twice under the same name.
        $this->mountStandInSiblingRoutes($router);
    }

    /**
     * Stand-ins for the routes of a sibling addon installed next to this one.
     *
     * They belong to the bed rather than to the test that reads them because a
     * sibling registers its routes the same way this addon does: at boot, and
     * therefore ahead of Statamic's `{segments?}` frontend catch-all. A route
     * added later — from inside a test body — is shadowed by that catch-all and
     * answers 404 no matter what the bindings do, which would make the check
     * pass for the wrong reason.
     *
     * Each one does nothing but echo its own parameter. If this addon binds a
     * name they use, the echo never happens: the binder resolves the value
     * against this addon's repository first, finds nothing, and aborts 404 —
     * precisely what LeadHub's delete button did.
     *
     * @see RouteParameterCollisionTest
     */
    protected function mountStandInSiblingRoutes($router): void
    {
        $router->middleware(SubstituteBindings::class)
            ->group(function ($router) {
                foreach (static::NAMES_A_SIBLING_MIGHT_USE as $name) {
                    $router->get(
                        'sibling-probe/'.$name.'/{'.$name.'}',
                        fn ($value) => (string) $value
                    );
                }
            });
    }

    /**
     * Generic names a sibling addon could plausibly put in one of its own
     * routes. `automation` heads the list because this addon bound it until
     * 1.6.0; `rule` and `template` are not hypothetical either — LeadHub
     * shipped `{rule}` and lost its edit and its delete to a sibling's binding.
     *
     * @var list<string>
     */
    public const NAMES_A_SIBLING_MIGHT_USE = [
        'automation', 'rule', 'template', 'webhook', 'endpoint', 'handle', 'id', 'slug', 'record',
    ];

    /**
     * Create and authenticate a Statamic super user — the standard actor for
     * CP feature tests. Mirrors how a real Control Panel request is made.
     */
    protected function actingAsSuperUser(): \Statamic\Contracts\Auth\User
    {
        $user = User::make()
            ->email('admin@example.com')
            ->makeSuper();
        $user->save();

        $this->actingAs($user);

        return $user;
    }
}

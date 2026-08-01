<?php

namespace Goldnead\StatamicAutomations\Tests;

use Goldnead\StatamicAutomations\ServiceProvider;
use Goldnead\StatamicAutomations\Tests\Feature\RouteParameterCollisionTest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Statamic\Facades\User;
use Statamic\Providers\StatamicServiceProvider;
use Statamic\Statamic;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Statamic's AddonServiceProvider runs bootAddon() inside a
        // Statamic::booted(...) callback. orchestra/testbench doesn't fire
        // those callbacks, so nodes, routes, permissions, listeners and the
        // rest of bootAddon never register. Force it so feature tests can hit
        // the real CP routes with the real middleware and ACLs in place.
        $provider = $this->app->getProvider(ServiceProvider::class);
        if ($provider instanceof ServiceProvider) {
            $provider->bootAddon();
        }
    }

    protected function getPackageProviders($app): array
    {
        return [
            StatamicServiceProvider::class,
            \Goldnead\BrandContext\ServiceProvider::class,
            ServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
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
        // SubstituteBindings is part of Statamic's CP route group in
        // production; mount it here so route-model binding for
        // {automationFlow}, {run}, {nodeRun} resolves to real models.
        $router->name('statamic.cp.')
            ->prefix('cp')
            ->middleware(SubstituteBindings::class)
            ->group(__DIR__.'/../routes/cp.php');

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
